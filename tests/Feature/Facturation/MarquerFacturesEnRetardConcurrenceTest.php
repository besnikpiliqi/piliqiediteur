<?php

namespace Tests\Feature\Facturation;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le passage en retard des factures échues (voir MarquerFacturesEnRetard)
 * et un autre geste au même instant, chacun dans son processus, sur une
 * vraie base PostgreSQL. Chaque facture passe en retard par sa propre mise
 * à jour, qui attend au besoin le verrou d'un paiement puis relit ses
 * conditions, et la notification du propriétaire ne nomme que les
 * factures réellement passées en retard : jamais une facture réglée
 * pendant le passage en retard. La fermeture ou la réouverture du
 * magasin, l'émission d'une facture ou la validation d'une facture d'achat
 * au même instant : les deux passent, sans interblocage, et les statuts
 * suivent leurs échéances, même dans un magasin fermé.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class MarquerFacturesEnRetardConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiersEntreLePassageEnRetardEtLePaiement(): array
    {
        return [
            'le paiement d\'abord' => [false],
            'le passage en retard d\'abord' => [true],
        ];
    }

    /**
     * Deux tickets de 120,00 € échus le 01/10 ; le 06/10, le responsable
     * encaisse le premier pendant que la commande les passe en retard. Le
     * paiement d'abord : la commande attend la facture qu'il tient, la
     * trouve réglée et ne passe en retard, et n'annonce, que la seconde. Le
     * passage en retard d'abord : les deux passent en retard et sont
     * annoncées, puis le paiement règle la première.
     */
    #[DataProvider('premiersEntreLePassageEnRetardEtLePaiement')]
    #[Group('concurrence')]
    public function test_une_facture_payee_pendant_le_passage_en_retard_n_est_annoncee_que_si_elle_est_passee_en_retard(bool $leRetardDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $payee = $this->ticketPayableComptant($magasin);
        $autre = $this->ticketPayableComptant($magasin);
        $this->travelTo('2026-10-06 10:00:00');
        [$magasinId, $factureId] = [$magasin->id, $payee->id];
        $payer = static function () use ($magasinId, $factureId): void {
            self::payerLaFacture($magasinId, $factureId);
        };
        $concurrente = null;

        if ($leRetardDAbord) {
            $this->concurrencerApresLaPremiereMiseEnRetard($payer, $concurrente);
            self::marquerLesFacturesEnRetard();
        } else {
            $this->concurrencerDesQueLaLigneEstVerrouillee('factures', 'for update', static function (): void {
                self::marquerLesFacturesEnRetard();
            }, $concurrente);
            $payer();
        }

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([StatutFacture::Payee, StatutFacture::EnRetard], [$payee->fresh()->statut, $autre->fresh()->statut]);
        $notification = $this->proprietaireDe($magasin)->notifications()->where('type', FacturesPasseesEnRetard::class)->sole();
        $this->assertSame($leRetardDAbord
            ? ['Ma Boutique : 2 factures passées en retard', "Échéance dépassée sans règlement complet : {$payee->numero}, {$autre->numero}."]
            : ['Ma Boutique : 1 facture passée en retard', "Échéance dépassée sans règlement complet : {$autre->numero}."],
            [$notification->data['title'], $notification->data['body']]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Le passage en retard face à chaque autre geste, dans les deux ordres.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function gestesEtOrdres(): array
    {
        $cas = [];
        $gestes = [
            'fermer' => 'la fermeture du magasin',
            'rouvrir' => 'la réouverture du magasin',
            'emettre' => 'l\'émission d\'une facture',
            'validerUnAchat' => 'la validation d\'une facture d\'achat',
        ];

        foreach ($gestes as $geste => $libelle) {
            $cas["le passage en retard avant {$libelle}"] = [$geste, true];
            $cas["{$libelle} avant le passage en retard"] = [$geste, false];
        }

        return $cas;
    }

    /**
     * Le passage en retard d'un ticket échu le 01/10 et l'autre geste, le
     * 06/10, dans cet ordre : le second démarre dès que le premier tient le
     * verrou qu'il pourrait attendre (la facture que la fermeture
     * verrouille pour clôturer ses journées, la ligne du magasin que la
     * réouverture verrouille, celle du responsable qui émet ou valide), ou,
     * le passage en retard d'abord, dès sa première mise à jour. Les deux
     * passent, sans interblocage ; le ticket passe en retard et est
     * annoncé, dans un magasin fermé comme ouvert.
     */
    #[DataProvider('gestesEtOrdres')]
    #[Group('concurrence')]
    public function test_le_passage_en_retard_et_un_autre_geste_simultanes_passent_l_un_apres_l_autre_sans_interblocage(string $geste, bool $leRetardDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $echu = $this->ticketPayableComptant($magasin);
        $this->travelTo('2026-10-06 10:00:00');
        $parametres = $this->preparer($magasin, $geste);
        $magasinId = $magasin->id;
        $faire = static function () use ($geste, $magasinId, $parametres): void {
            self::faire($geste, $magasinId, $parametres);
        };
        $marquer = static function (): void {
            self::marquerLesFacturesEnRetard();
        };
        $concurrente = null;

        if ($leRetardDAbord) {
            $this->concurrencerApresLaPremiereMiseEnRetard($faire, $concurrente);
        } else {
            [$table, $verrou] = match ($geste) {
                'fermer' => ['factures', 'for update'],
                'rouvrir' => ['magasins', 'for update'],
                default => ['users', 'for no key update'],
            };
            $this->concurrencerDesQueLaLigneEstVerrouillee($table, $verrou, $marquer, $concurrente);
        }

        $leRetardDAbord ? $marquer() : $faire();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin->fresh());
        $this->verifierLeGeste($geste, $parametres);
        $this->assertSame(StatutFacture::EnRetard, $echu->fresh()->statut);
        $notification = $this->proprietaireDe($magasin)->notifications()->where('type', FacturesPasseesEnRetard::class)->sole();
        $this->assertSame("Échéance dépassée sans règlement complet : {$echu->numero}.", $notification->data['body']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Prépare l'autre geste, avant toute concurrence : le magasin fermé
     * avant sa réouverture, le brouillon de facture à émettre, la facture
     * d'achat à valider ; rend ce dont il a besoin, en valeurs simples.
     *
     * @return array<string, int>
     */
    private function preparer(Magasin $magasin, string $geste): array
    {
        app(TenantContext::class)->set($magasin);
        $responsable = self::responsableDeTest($magasin);

        return match ($geste) {
            'fermer' => [],
            'rouvrir' => tap([], fn () => self::fermer($magasin->id)),
            'emettre' => ['id' => app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]])->id],
            'validerUnAchat' => ['id' => app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]])->id],
        };
    }

    /**
     * L'autre geste, dans le processus du test ou dans le sien : le
     * propriétaire ferme ou rouvre le magasin ; le responsable émet la
     * facture ou valide la facture d'achat.
     *
     * @param  array<string, int>  $parametres
     */
    private static function faire(string $geste, int $magasinId, array $parametres): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        match ($geste) {
            'fermer' => self::fermer($magasinId),
            'rouvrir' => app(RouvrirMagasinAction::class)->handle(self::proprietaire($magasin), $magasin, 'password'),
            'emettre' => app(EmettreFactureAction::class)->handle(self::responsableDeTest($magasin), Facture::query()->findOrFail($parametres['id'])),
            'validerUnAchat' => app(ValiderFactureAchatAction::class)->handle(self::responsableDeTest($magasin), FactureAchat::query()->findOrFail($parametres['id'])),
        };
    }

    /**
     * Ce que l'autre geste a fait, quel que soit l'ordre.
     *
     * @param  array<string, int>  $parametres
     */
    private function verifierLeGeste(string $geste, array $parametres): void
    {
        $magasin = app(TenantContext::class)->magasin();

        match ($geste) {
            'fermer' => $this->assertSame(StatutMagasin::Ferme, $magasin->statut),
            'rouvrir' => $this->assertSame(StatutMagasin::Actif, $magasin->statut),
            'emettre' => $this->assertSame(StatutFacture::Emise, Facture::query()->findOrFail($parametres['id'])->statut),
            'validerUnAchat' => $this->assertSame(StatutFactureAchat::Validee, FactureAchat::query()->findOrFail($parametres['id'])->statut),
        };
    }

    /**
     * Démarre l'opération concurrente juste après la première mise en
     * retard d'une facture par la commande du test, et attend qu'elle
     * attende un verrou que tient le test, ou qu'elle ait fini.
     *
     * @param  Closure(): void  $operation
     */
    private function concurrencerApresLaPremiereMiseEnRetard(Closure $operation, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $requete) use ($operation, &$concurrente, $connexionDuTest): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && str_starts_with($requete->sql, 'update "factures"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operation);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * La commande planifiée, dans le processus du test ou dans le sien,
     * sans magasin actif.
     */
    private static function marquerLesFacturesEnRetard(): void
    {
        app(TenantContext::class)->clear();

        Artisan::call('app:marquer-factures-en-retard');
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Un ticket de 120,00 € TTC émis maintenant par le responsable à un
     * client payable comptant : il échoit le jour même.
     */
    private function ticketPayableComptant(Magasin $magasin): Facture
    {
        app(TenantContext::class)->set($magasin);
        $responsable = self::responsableDeTest($magasin);

        return app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(['delai_paiement_jours' => 0]), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
        ]));
    }

    /**
     * Le responsable encaisse en espèces tout ce que la facture doit.
     */
    private static function payerLaFacture(int $magasinId, int $factureId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $facture = Facture::query()->findOrFail($factureId);

        app(EnregistrerPaiementAction::class)->handle(self::responsableDeTest($magasin), $facture->client, [
            'mode_paiement' => ModePaiement::Especes,
            'montant' => 120,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => 120]]);
    }

    private static function fermer(int $magasinId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(FermerMagasinAction::class)->handle(self::proprietaire($magasin), $magasin, MotifFermeture::Temporaire, 'password');
    }

    private static function proprietaire(Magasin $magasin): User
    {
        return User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail();
    }
}
