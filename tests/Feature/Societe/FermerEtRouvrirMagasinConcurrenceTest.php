<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Actions\ChoisirEtablissementPrincipalAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Une vente à la caisse, ou une création, une validation ou une correction
 * d'achat, et la fermeture du magasin au même instant, chacune dans son
 * processus, sur une vraie base PostgreSQL : la seconde attend le verrou
 * que tient la première (celui du registre fiscal pour une vente, celui de
 * la ligne du magasin pour un achat). Une vente qui passe avant entre dans
 * la dernière clôture de la fermeture ; une vente ou un achat qui attendait
 * la fermeture est refusé, le magasin étant fermé. De même, une réouverture
 * qui attendait la cession du magasin fermé est refusée, et une réouverture
 * avant la validation de la société passe tout entière avant la saisie du
 * code du courrier, dont la validation met alors le magasin en activité.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FermerEtRouvrirMagasinConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiers(): array
    {
        return [
            'la vente d\'abord' => [true],
            'la fermeture d\'abord' => [false],
        ];
    }

    #[DataProvider('premiers')]
    public function test_une_vente_simultanee_a_la_fermeture_est_faite_avant_ou_refusee_apres(bool $laVenteDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $fermer = static function () use ($magasinId): void {
            self::fermer($magasinId);
        };
        $vendre = static function () use ($magasinId, $produitId): void {
            self::vendre($magasinId, $produitId);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($laVenteDAbord ? $fermer : $vendre, $concurrente);

        $laVenteDAbord ? $vendre() : $fermer();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertSame(! $laVenteDAbord, $resultat->failed(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(StatutMagasin::Ferme, $magasin->fresh()->statut);

        if ($laVenteDAbord) {
            $this->assertSame([1, '12.00'], [ClotureCaisse::query()->sole()->nombre_ventes, ClotureCaisse::query()->sole()->total_ttc]);
            $this->assertNotNull(Facture::query()->sole()->cloture_caisse_id);
        } else {
            $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
            $this->assertSame(0, Facture::query()->count());
        }

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * La création d'une facture d'achat attend la fermeture du magasin, qui
     * verrouille sa ligne : elle est ensuite refusée, le magasin étant
     * fermé, et aucun brouillon n'entre dans le magasin fermé.
     */
    #[Group('concurrence')]
    public function test_une_creation_d_achat_qui_attendait_la_fermeture_est_refusee(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $magasinId = $magasin->id;
        $fournisseurId = Fournisseur::factory()->create()->id;
        $produitId = Produit::factory()->create(['cmup_ht' => 2, 'taux_tva' => 20, 'suivi_stock' => true, 'seuil_alerte' => 0])->id;
        $creer = static function () use ($magasinId, $fournisseurId, $produitId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(CreerFactureAchatAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), Fournisseur::query()->findOrFail($fournisseurId), [
                ['produit_id' => $produitId, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20],
            ]);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($creer, $concurrente);

        self::fermer($magasinId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(0, FactureAchat::query()->count());
    }

    /**
     * La réouverture d'un magasin fermé temporairement attend sa cession,
     * qui verrouille son registre : elle est ensuite refusée, le magasin
     * étant cédé, qui reste fermé, sans nouvelle période d'activité.
     */
    #[Group('concurrence')]
    public function test_une_reouverture_qui_attendait_la_cession_d_un_magasin_ferme_est_refusee(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        self::fermer($magasin->id);
        $magasinId = $magasin->id;
        $rouvrir = static function () use ($magasinId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(RouvrirMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, 'password');
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($rouvrir, $concurrente);

        app(FermerMagasinAction::class)->handle($proprietaire, $magasin->fresh(), MotifFermeture::Cession, 'password', 'Ma Boutique');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString(RouvrirMagasinAction::MESSAGE_CEDE, $resultat->errorOutput());
        $cede = $magasin->fresh();
        $this->assertSame([StatutMagasin::Ferme, MotifFermeture::Cession, 1], [$cede->statut, $cede->motif_fermeture, PeriodeActivite::query()->where('magasin_id', $magasin->id)->count()]);
    }

    /**
     * Le code du courrier est saisi, et la société validée, pendant que la
     * boutique, fermée avant la validation, se rouvre : la réouverture
     * tient le registre de la boutique, que la saisie du code attend avant
     * de verrouiller la ligne de la société ; la boutique rouverte, sans
     * période d'activité, compte ensuite parmi les magasins actifs que la
     * validation met en activité (SIRET réservé, première période). Sans ce
     * verrou, la validation passerait sans la voir, et la boutique
     * rouvrirait sans période d'activité dans une société validée, où elle
     * vendrait sans ticket Z.
     */
    #[Group('concurrence')]
    public function test_une_reouverture_avant_la_validation_simultanee_a_la_saisie_du_code_met_le_magasin_en_activite(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);
        $boutique = $this->creerLeMagasinDuSiege($societe, 'Boutique', substr(FauxRepertoireSirene::siretDeLaBoutique($societe->siren), 9));
        $proprietaire = $societe->proprietaire;
        app(FermerMagasinAction::class)->handle($proprietaire, $boutique, MotifFermeture::Temporaire, self::MOT_DE_PASSE_CHOISI);
        [$siren, $code, $email] = [(string) $societe->siren, $this->codeDuCourrier($societe), (string) $proprietaire->email];
        $saisir = static function () use ($siren, $code, $email): void {
            app(SaisirCodeDeConfirmationAction::class)->handle($siren, $code, $email, self::MOT_DE_PASSE_CHOISI, '', '127.0.0.1');
        };
        $concurrente = null;
        $this->concurrencerDesQueLaSocieteEstLue($saisir, $concurrente);

        app(RouvrirMagasinAction::class)->handle($proprietaire, $boutique->fresh(), self::MOT_DE_PASSE_CHOISI);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame(1, PeriodeActivite::query()->where('magasin_id', $boutique->id)->count());
        $this->assertSame([(string) $boutique->siret], SiretMagasin::query()->where('magasin_id', $boutique->id)->whereNull('retire_le')->pluck('siret')->all());
        $this->assertTrue($boutique->fresh()->peutVendre());
    }

    /**
     * La validation d'une facture d'achat attend la cession du magasin, qui
     * verrouille sa ligne : elle est ensuite refusée, le magasin étant
     * fermé, et rien n'entre dans le stock que la cession a sorti.
     */
    public function test_une_validation_d_achat_qui_attendait_la_cession_est_refusee(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $produit = Produit::factory()->create(['cmup_ht' => 2, 'suivi_stock' => true, 'seuil_alerte' => 0]);
        app(StockLedgerService::class)->ajuster($produit, 10, 'Inventaire d\'ouverture');
        $factureAchat = app(CreerFactureAchatAction::class)->handle($proprietaire, Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20],
        ]);
        $magasinId = $magasin->id;
        $factureAchatId = $factureAchat->id;
        $valider = static function () use ($magasinId, $factureAchatId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(ValiderFactureAchatAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), FactureAchat::query()->findOrFail($factureAchatId));
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($valider, $concurrente);

        app(FermerMagasinAction::class)->handle($proprietaire, $magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([0, StatutFactureAchat::Brouillon], [$produit->fresh()->stock_quantite, $factureAchat->fresh()->statut]);
    }

    /**
     * La correction d'une facture d'achat validée (5 articles reçus portés
     * à 8) attend la cession du magasin, qui verrouille sa ligne : elle est
     * ensuite refusée, le magasin étant fermé ; la facture garde ses 5
     * articles et rien n'entre dans le stock que la cession a sorti.
     */
    public function test_une_correction_d_achat_valide_qui_attendait_la_cession_est_refusee(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $produit = Produit::factory()->create(['cmup_ht' => 2, 'suivi_stock' => true, 'seuil_alerte' => 0]);
        app(StockLedgerService::class)->ajuster($produit, 10, 'Inventaire d\'ouverture');
        $fournisseur = Fournisseur::factory()->create();
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($proprietaire, app(CreerFactureAchatAction::class)->handle($proprietaire, $fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20],
        ]));
        $magasinId = $magasin->id;
        $factureAchatId = $factureAchat->id;
        $fournisseurId = $fournisseur->id;
        $produitId = $produit->id;
        $corriger = static function () use ($magasinId, $factureAchatId, $fournisseurId, $produitId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->set($magasin);
            app(ModifierFactureAchatAction::class)->handle(
                User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(),
                FactureAchat::query()->findOrFail($factureAchatId),
                Fournisseur::query()->findOrFail($fournisseurId),
                [['produit_id' => $produitId, 'quantite' => 8, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]],
            );
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($corriger, $concurrente);

        app(FermerMagasinAction::class)->handle($proprietaire, $magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.', $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([0, [5]], [$produit->fresh()->stock_quantite, $factureAchat->fresh()->lignes->pluck('quantite')->all()]);
        $this->assertSame(
            [[TypeMouvementStock::AjustementEntree, 10], [TypeMouvementStock::ReceptionAchat, 5], [TypeMouvementStock::SortieCession, 15]],
            MouvementStock::query()->where('produit_id', $produit->id)->orderBy('id')->get()->map(fn (MouvementStock $mouvement): array => [$mouvement->type, $mouvement->quantite])->all(),
        );
    }

    /**
     * Le choix du magasin comme établissement principal attend sa cession,
     * qui verrouille sa ligne : il est ensuite refusé, le magasin étant
     * cédé, qui n'est pas indiqué comme établissement principal.
     */
    public function test_un_choix_d_etablissement_principal_qui_attendait_la_cession_est_refuse(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $magasinId = $magasin->id;
        $choisir = static function () use ($magasinId): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(ChoisirEtablissementPrincipalAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, true);
        };
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille($choisir, $concurrente);

        app(FermerMagasinAction::class)->handle($proprietaire, $magasin, MotifFermeture::Cession, 'password', 'Ma Boutique');

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed());
        $this->assertStringContainsString(ChoisirEtablissementPrincipalAction::MESSAGE_CEDE, $resultat->errorOutput());
        $this->assertSame([true, false], [$magasin->fresh()->aEteCede(), $magasin->fresh()->est_etablissement_principal]);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::EtablissementPrincipalChoisi));
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test, sous le
     * verrou du registre fiscal, a lu la ligne de la société (la
     * réouverture la verrouille FOR NO KEY UPDATE), et attend qu'elle
     * attende un verrou, ou qu'elle ait fini. $concurrente reçoit le
     * processus démarré.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLaSocieteEstLue(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        $registreVerrouille = false;

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, &$registreVerrouille, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente !== null || $requete->connectionName !== $connexionDuTest) {
                return;
            }

            $registreVerrouille = $registreVerrouille || (str_contains($requete->sql, '"tetes_registre_fiscal"') && str_contains($requete->sql, 'for update'));

            if ($registreVerrouille && str_contains($requete->sql, 'from "societes"')) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    /**
     * Le propriétaire ferme le magasin, temporairement.
     */
    private static function fermer(int $magasinId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(FermerMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, MotifFermeture::Temporaire, 'password');
    }

    /**
     * Le responsable de test du magasin, obtenu par le test avant de lancer
     * l'autre processus, vend un ticket de 12,00 € au comptoir : le
     * propriétaire ne vend jamais.
     */
    private static function vendre(int $magasinId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        self::vendreAuComptant(
            self::responsableDeTest($magasin),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }
}
