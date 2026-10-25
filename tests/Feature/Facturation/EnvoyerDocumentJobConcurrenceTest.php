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
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\EnvoiDocumentEchoue;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le départ de l'e-mail d'un devis ou d'une relance (voir
 * EnvoyerDocumentJob) et un autre geste au même instant, chacun dans son
 * processus, sur une vraie base PostgreSQL. Le départ relit, dans une
 * transaction courte, la ligne du magasin sous un verrou partagé, puis la
 * personne qui l'a demandé sous le verrou de sa ligne, puis le document
 * sous un verrou partagé, avec ce que son PDF imprime, et décide sur cette
 * seule lecture ; il rend ses verrous avant le PDF et l'envoi.
 *
 * Une modification du devis, ou un paiement de la facture relancée, qui
 * verrouillent le document avant de l'écrire, passe donc tout entière
 * avant cette lecture, et l'e-mail ne part pas, ou après elle, et l'e-mail
 * part tel que le magasin l'a demandé : jamais un devis aux lignes d'une
 * version et au total d'une autre, jamais un rappel de ce qui reste dû
 * d'une facture réglée pendant qu'il s'écrivait.
 *
 * La fermeture ou la réouverture du magasin, l'émission d'une facture, la
 * validation d'une facture d'achat ou la suspension de la personne qui a
 * demandé l'envoi, au même instant : la seconde attend la première, sans
 * interblocage.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class EnvoyerDocumentJobConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const DEVIS_MODIFIE = 'Le devis a été modifié depuis cette demande d\'envoi : l\'e-mail n\'est pas parti, envoyez sa nouvelle version à votre client si ce n\'est pas déjà fait.';

    private const FACTURE_REGLEE = 'La facture a été réglée ou soldée par un avoir entre-temps : la relance n\'est pas partie.';

    private const MAGASIN_FERME = 'Le magasin est fermé : il n\'écrit plus à ses clients, l\'e-mail n\'est pas parti.';

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiersEntreLeDepartEtLaModification(): array
    {
        return [
            'le départ d\'abord' => [true],
            'la modification d\'abord' => [false],
        ];
    }

    /**
     * Le propriétaire a envoyé un devis de 120,00 € TTC ; le responsable
     * le modifie (cinq articles, 600,00 € TTC) pendant que son e-mail part.
     * Le départ d'abord : la modification attend que le départ ait lu le
     * devis, et l'e-mail part avec la version demandée, son PDF compris,
     * puis le devis repasse en brouillon. La modification d'abord : le
     * départ voit le devis repassé en brouillon, et l'e-mail ne part pas.
     */
    #[DataProvider('premiersEntreLeDepartEtLaModification')]
    #[Group('concurrence')]
    public function test_un_devis_modifie_pendant_le_depart_de_son_e_mail_part_tel_qu_il_a_ete_demande_ou_ne_part_pas(bool $leDepartDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-09 10:00:00');
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $produit = $this->produit();
        $devis = app(CreerDevisAction::class)->handle(self::responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => '2026-10-31']);
        Queue::fake([EnvoyerDocumentJob::class]);
        $envoi = app(EnvoyerDocumentAction::class)->handle($proprietaire, $devis, 'client@exemple.fr', null);
        [$magasinId, $devisId, $produitId, $envoiId] = [$magasin->id, $devis->id, $produit->id, $envoi->id];
        $modifier = static function () use ($magasinId, $devisId, $produitId): void {
            self::modifierLeDevis($magasinId, $devisId, $produitId);
        };
        $partir = static function () use ($magasinId, $envoiId): void {
            (new EnvoyerDocumentJob($magasinId, $envoiId))->handle();
        };
        $concurrente = null;

        if ($leDepartDAbord) {
            $this->concurrencerApresLaPremiereRequete(fn (string $sql): bool => str_contains($sql, 'from "devis"'), $modifier, $concurrente);
            $partir();
        } else {
            $this->concurrencerDesQueLaLigneEstVerrouillee('devis', 'for update', $partir, $concurrente);
            $modifier();
        }

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $envoi->refresh();

        if ($leDepartDAbord) {
            $mail = Mail::sent(DocumentMail::class)->sole();
            $this->assertStringContainsString("notre devis {$devis->fresh()->numero} d'un montant de 120,00 € TTC", $mail->render());
            $textes = $this->textesDuPdf($mail);
            $this->assertStringContainsString('120,00 €', $textes);
            $this->assertStringNotContainsString('600,00', $textes);
            $this->assertSame(StatutEnvoi::Envoye, $envoi->statut);
        } else {
            $this->assertSame([StatutEnvoi::Echec, self::DEVIS_MODIFIE], [$envoi->statut, $envoi->erreur]);
            $this->assertSame(1, $proprietaire->notifications()->where('type', EnvoiDocumentEchoue::class)->count());
        }

        $devis->refresh();
        $this->assertSame([StatutDevis::Brouillon, '600.00'], [$devis->statut, $devis->total_ttc]);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiersEntreLeDepartEtLePaiement(): array
    {
        return [
            'le départ d\'abord' => [true],
            'le paiement d\'abord' => [false],
        ];
    }

    /**
     * Le propriétaire a relancé un ticket de 120,00 € échu le 30/09 ; le
     * responsable encaisse les 120,00 € pendant que la relance part. Le
     * départ d'abord : le paiement attend que la relance ait lu la
     * facture, et le rappel dit ce qui restait dû au moment où elle est
     * partie, jamais « il reste 0,00 € ». Le paiement d'abord : la relance
     * voit la facture réglée, et ne part pas.
     */
    #[DataProvider('premiersEntreLeDepartEtLePaiement')]
    #[Group('concurrence')]
    public function test_une_relance_dont_la_facture_est_payee_pendant_son_depart_dit_ce_qui_restait_du_ou_ne_part_pas(bool $leDepartDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $facture = $this->factureEchueLe30Septembre($magasin);
        Queue::fake([EnvoyerDocumentJob::class]);
        $relance = app(EnvoyerDocumentAction::class)->relancer($proprietaire, $facture, 'client@exemple.fr');
        [$magasinId, $factureId, $relanceId] = [$magasin->id, $facture->id, $relance->id];
        $payer = static function () use ($magasinId, $factureId): void {
            self::payerLaFacture($magasinId, $factureId);
        };
        $partir = static function () use ($magasinId, $relanceId): void {
            (new EnvoyerDocumentJob($magasinId, $relanceId))->handle();
        };
        $concurrente = null;

        if ($leDepartDAbord) {
            $this->concurrencerApresLaPremiereRequete(fn (string $sql): bool => str_contains($sql, 'from "avoir_allocations"'), $payer, $concurrente);
            $partir();
        } else {
            $this->concurrencerDesQueLaLigneEstVerrouillee('factures', 'for update', $partir, $concurrente);
            $payer();
        }

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $relance->refresh();

        if ($leDepartDAbord) {
            $html = Mail::sent(DocumentMail::class)->sole()->render();
            $this->assertStringContainsString('il reste 120,00 € à payer', $html);
            $this->assertStringNotContainsString('il reste 0,00 €', $html);
            $this->assertStringContainsString('il reste 120,00 € à payer', $relance->message);
            $this->assertSame(StatutEnvoi::Envoye, $relance->statut);
        } else {
            $this->assertSame([StatutEnvoi::Echec, self::FACTURE_REGLEE], [$relance->statut, $relance->erreur]);
            $this->assertSame(1, $proprietaire->notifications()->where('type', EnvoiDocumentEchoue::class)->count());
        }

        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Chaque départ (l'e-mail d'un devis, une relance), demandé par le
     * responsable, face à chaque autre geste, dans les deux ordres.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function departsEtGestes(): array
    {
        $cas = [];
        $gestes = [
            'fermer' => 'la fermeture du magasin',
            'rouvrir' => 'la réouverture du magasin',
            'emettre' => 'l\'émission d\'une facture',
            'validerUnAchat' => 'la validation d\'une facture d\'achat',
            'suspendre' => 'la suspension de l\'expéditeur',
        ];

        $departs = [
            'devis' => ['l\'e-mail d\'un devis', 'de l\'e-mail d\'un devis'],
            'relance' => ['une relance', 'd\'une relance'],
        ];

        foreach ($departs as $depart => [$libelleDuDepart, $complementDuDepart]) {
            foreach ($gestes as $geste => $libelleDuGeste) {
                $cas["{$libelleDuDepart} part avant {$libelleDuGeste}"] = [$depart, $geste, true];
                $cas["{$libelleDuGeste} avant le départ {$complementDuDepart}"] = [$depart, $geste, false];
            }
        }

        return $cas;
    }

    /**
     * Le départ et l'autre geste au même instant, dans cet ordre : le
     * second démarre dès que le premier tient le verrou qu'il attendra (la
     * ligne du magasin pour une fermeture ou une réouverture, celle du
     * responsable pour une émission, une validation d'achat ou sa
     * suspension). Les deux passent, sans interblocage. L'e-mail ne part
     * pas d'un magasin fermé, ni au nom d'un compte suspendu, quand le
     * départ a attendu la fermeture ou la suspension ; il part quand le
     * départ a attendu la réouverture, ou a précédé la fermeture ou la
     * suspension ; l'émission et la validation d'achat ne l'empêchent pas.
     */
    #[DataProvider('departsEtGestes')]
    #[Group('concurrence')]
    public function test_le_depart_d_un_e_mail_et_un_autre_geste_simultanes_passent_l_un_apres_l_autre_sans_interblocage(string $depart, string $geste, bool $leDepartDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $envoi = $this->programmerLeDepart($magasin, $depart, $responsable);
        $parametres = $this->preparer($magasin, $geste);
        [$magasinId, $envoiId] = [$magasin->id, $envoi->id];
        $partir = static function () use ($magasinId, $envoiId): void {
            (new EnvoyerDocumentJob($magasinId, $envoiId))->handle();
        };
        $faire = static function () use ($geste, $magasinId, $parametres): void {
            self::faire($geste, $magasinId, $parametres);
        };
        $concurrente = null;
        [$table, $verrou] = match (true) {
            in_array($geste, ['fermer', 'rouvrir'], true) => ['magasins', $leDepartDAbord ? 'for share' : 'for update'],
            $geste === 'suspendre' && ! $leDepartDAbord => ['users', 'for update'],
            default => ['users', 'for no key update'],
        };
        $this->concurrencerDesQueLaLigneEstVerrouillee($table, $verrou, $leDepartDAbord ? $faire : $partir, $concurrente);

        $leDepartDAbord ? $partir() : $faire();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin->fresh());
        $this->verifierLeGeste($geste, $parametres);
        $envoi->refresh();
        $refus = match (true) {
            $geste === 'fermer' && ! $leDepartDAbord, $geste === 'rouvrir' && $leDepartDAbord => self::MAGASIN_FERME,
            $geste === 'suspendre' && ! $leDepartDAbord => EnvoyerDocumentJob::MOTIF_EXPEDITEUR_SANS_DROIT,
            default => null,
        };
        $this->assertSame([$refus === null ? StatutEnvoi::Envoye : StatutEnvoi::Echec, $refus], [$envoi->statut, $envoi->erreur]);

        if ($leDepartDAbord) {
            $this->assertSame($refus === null ? 1 : 0, Mail::sent(DocumentMail::class)->count());
        }

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * L'envoi que le responsable demande, que son départ attend : l'e-mail
     * d'un devis de 120,00 € TTC, ou la relance d'un ticket de 120,00 €
     * échu le 30/09, le 15/10.
     */
    private function programmerLeDepart(Magasin $magasin, string $depart, User $responsable): EnvoiDocument
    {
        if ($depart === 'devis') {
            $this->travelTo('2026-10-15 10:00:00');
            $devis = app(CreerDevisAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], ['date_validite' => '2026-10-31']);
            Queue::fake([EnvoyerDocumentJob::class]);

            return app(EnvoyerDocumentAction::class)->handle($responsable, $devis, 'client@exemple.fr', null);
        }

        $facture = $this->factureEchueLe30Septembre($magasin);
        Queue::fake([EnvoyerDocumentJob::class]);

        return app(EnvoyerDocumentAction::class)->relancer($responsable, $facture, 'client@exemple.fr');
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
        $responsable = self::responsableDeTest($magasin);

        return match ($geste) {
            'fermer', 'suspendre' => [],
            'rouvrir' => tap([], fn () => self::fermer($magasin->id)),
            'emettre' => ['id' => app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]])->id],
            'validerUnAchat' => ['id' => app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]])->id],
        };
    }

    /**
     * L'autre geste, dans le processus du test ou dans le sien : le
     * propriétaire ferme ou rouvre le magasin, ou suspend le responsable ;
     * le responsable émet la facture ou valide la facture d'achat.
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
            'suspendre' => app(ChangerStatutCompteAction::class)->suspendre(self::proprietaire($magasin), self::responsableDeTest($magasin)),
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
            'suspendre' => $this->assertTrue(self::responsableDeTest($magasin)->estSuspendu()),
        };
    }

    /**
     * Démarre l'opération concurrente juste après la première requête du
     * test que désigne $estLaRequete, et attend qu'elle attende un verrou
     * que tient le test, ou qu'elle ait fini : elle se glisse entre cette
     * requête et la suivante.
     *
     * @param  Closure(string): bool  $estLaRequete
     * @param  Closure(): void  $operation
     */
    private function concurrencerApresLaPremiereRequete(Closure $estLaRequete, Closure $operation, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();

        DB::listen(function (QueryExecuted $requete) use ($estLaRequete, $operation, &$concurrente, $connexionDuTest): void {
            if ($concurrente === null && $requete->connectionName === $connexionDuTest && $estLaRequete($requete->sql)) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operation);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * Le texte que le PDF joint à l'e-mail imprime, morceau par morceau.
     */
    private function textesDuPdf(DocumentMail $mail): string
    {
        $pdf = $mail->attachments()[0]->attachWith(fn (): string => '', fn (Closure $donnees): string => $donnees());

        return implode(' | ', array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf))));
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Un ticket de 120,00 € TTC émis par le responsable le 30/09 à un
     * client payable comptant, donc échu le jour même ; le test reprend le
     * 15/10.
     */
    private function factureEchueLe30Septembre(Magasin $magasin): Facture
    {
        $this->travelTo('2026-09-30 10:00:00');
        $responsable = self::responsableDeTest($magasin);
        $facture = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(['delai_paiement_jours' => 0]), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
        ]));
        $this->travelTo('2026-10-15 10:00:00');

        return $facture;
    }

    /**
     * Le responsable passe le devis à cinq articles, 600,00 € TTC.
     */
    private static function modifierLeDevis(int $magasinId, int $devisId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $devis = Devis::query()->findOrFail($devisId);

        app(ModifierDevisAction::class)->handle(self::responsableDeTest($magasin), $devis, $devis->client, [['produit_id' => $produitId, 'quantite' => 5]]);
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
