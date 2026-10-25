<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le refus de l'éditeur, qui clôt une inscription, et un geste qui écrit
 * dans un magasin réel de cette société avant sa validation (une facture
 * d'achat ou un inventaire d'ouverture validés, une fiche produit
 * modifiée, un client créé, les paramètres du magasin réglés), au même
 * instant, chacun dans son processus, sur une vraie base PostgreSQL.
 *
 * Le geste relit la ligne de son magasin sous un verrou partagé (exclusif
 * de toute autre écriture de cette ligne pour les paramètres du magasin,
 * qu'il écrit) et le dit ouvert tant que l'inscription ne l'est pas ; la
 * clôture verrouille la société, puis les lignes de ses magasins réels,
 * avant de la clore. Un geste commencé avant la clôture passe donc tout
 * entier avant elle, qui ne se date qu'ensuite et ne s'inscrit au journal
 * d'audit qu'après lui ; un geste qui attendait la clôture voit
 * l'inscription close et est refusé sans rien écrire. Aucun des deux
 * ordres ne s'interbloque.
 *
 * La saisie du code du courrier, qui valide la société, et la validation
 * d'une facture d'achat par le propriétaire, au même instant : la saisie
 * verrouille les registres des magasins, la société puis la ligne du
 * propriétaire, sans toucher à la ligne d'un magasin ; la validation de
 * la facture d'achat verrouille la ligne de son magasin sous un verrou
 * partagé, puis celle du propriétaire. La seconde attend la première,
 * sans interblocage, et toutes deux passent. De même pour une fiche
 * produit modifiée, un client créé ou les paramètres du magasin réglés
 * par le propriétaire.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class GestesPendantLaClotureOuLaValidationConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const INSCRIPTION_CLOSE = 'Ce magasin est en lecture seule, l\'inscription de votre société étant close : seules la consultation et les téléchargements restent possibles.';

    /**
     * Le geste, et qui passe le premier.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function gestesEtOrdres(): array
    {
        return [
            'la facture d\'achat validée avant la clôture' => ['validerUneFactureDAchat', true],
            'la facture d\'achat validée après la clôture' => ['validerUneFactureDAchat', false],
            'l\'inventaire d\'ouverture validé avant la clôture' => ['validerUnInventaire', true],
            'l\'inventaire d\'ouverture validé après la clôture' => ['validerUnInventaire', false],
            'la fiche produit modifiée avant la clôture' => ['modifierUneFicheProduit', true],
            'la fiche produit modifiée après la clôture' => ['modifierUneFicheProduit', false],
            'le client créé avant la clôture' => ['creerUnClient', true],
            'le client créé après la clôture' => ['creerUnClient', false],
            'les paramètres du magasin réglés avant la clôture' => ['reglerLesParametresDuMagasin', true],
            'les paramètres du magasin réglés après la clôture' => ['reglerLesParametresDuMagasin', false],
        ];
    }

    #[DataProvider('gestesEtOrdres')]
    #[Group('concurrence')]
    public function test_un_geste_simultane_au_refus_de_l_inscription_passe_avant_elle_ou_est_refuse_apres(string $geste, bool $leGesteDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        app(TenantContext::class)->set($magasin);
        $documentId = $this->preparer($geste, $societe->proprietaire()->firstOrFail());
        $societeId = $societe->id;
        $magasinId = $magasin->id;
        $faire = static function () use ($geste, $magasinId, $documentId): void {
            self::faire($geste, $magasinId, $documentId);
        };
        $refuser = static function () use ($societeId): void {
            app(CloreLInscriptionAction::class)->refuser(Societe::query()->findOrFail($societeId), new InterventionDeLEditeur('Courrier revenu, pièces insuffisantes.', 'DOSSIER-2026-0070', ['alice']));
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', 'for no key update', $leGesteDAbord ? $refuser : $faire, $concurrente);

        $leGesteDAbord ? $faire() : $refuser();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertSame(! $leGesteDAbord, $resultat->failed(), $resultat->errorOutput());
        $this->assertTrue(Societe::query()->findOrFail($societeId)->estClose());
        app(TenantContext::class)->set($magasin->fresh());

        if ($leGesteDAbord) {
            $this->assertTrue($this->valide($geste, $documentId));
            $this->assertGesteInscritAvantLaCloture($geste, $societe);
        } else {
            $this->assertStringContainsString(self::INSCRIPTION_CLOSE, $resultat->errorOutput());
            $this->assertFalse($this->valide($geste, $documentId));
            $this->assertSame(0, Produit::query()->where('reference', 'CARNET-A5')->count());
        }
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function premiersEntreLaValidationDeLaSocieteEtLaFactureDAchat(): array
    {
        return [
            'la facture d\'achat d\'abord' => [true],
            'la société d\'abord' => [false],
        ];
    }

    #[DataProvider('premiersEntreLaValidationDeLaSocieteEtLaFactureDAchat')]
    #[Group('concurrence')]
    public function test_une_facture_d_achat_validee_pendant_la_saisie_du_code_passe_avant_ou_apres_elle_sans_interblocage(bool $laFactureDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        app(TenantContext::class)->set($magasin);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $factureAchatId = $this->preparer('validerUneFactureDAchat', $proprietaire);
        [$siren, $code, $email] = [(string) $societe->siren, $this->codeDuCourrier($societe), (string) $proprietaire->email];
        $magasinId = $magasin->id;
        $valider = static function () use ($magasinId, $factureAchatId): void {
            self::faire('validerUneFactureDAchat', $magasinId, $factureAchatId);
        };
        $saisir = static function () use ($siren, $code, $email): void {
            app(TenantContext::class)->clear();
            app(SaisirCodeDeConfirmationAction::class)->handle($siren, $code, $email, self::MOT_DE_PASSE_CHOISI, '', '127.0.0.1');
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', $laFactureDAbord ? 'for no key update' : 'for update', $laFactureDAbord ? $saisir : $valider, $concurrente);

        $laFactureDAbord ? $valider() : $saisir();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertTrue(Societe::query()->findOrFail($societe->id)->estValidee());
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertTrue($this->valide('validerUneFactureDAchat', $factureAchatId));
        $this->assertSame(10, Produit::query()->where('reference', 'CARNET-A5')->sole()->stock_quantite);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function gestesEtPremiersPendantLaSaisieDuCode(): array
    {
        return [
            'la fiche produit modifiée d\'abord' => ['modifierUneFicheProduit', true],
            'la société validée avant la fiche produit' => ['modifierUneFicheProduit', false],
            'le client créé d\'abord' => ['creerUnClient', true],
            'la société validée avant le client' => ['creerUnClient', false],
            'les paramètres du magasin réglés d\'abord' => ['reglerLesParametresDuMagasin', true],
            'la société validée avant les paramètres du magasin' => ['reglerLesParametresDuMagasin', false],
        ];
    }

    /**
     * Comme la validation d'une facture d'achat : la saisie du code et un
     * geste du propriétaire sur une fiche ou les paramètres du magasin,
     * qui verrouille la ligne de son magasin puis la sienne, au même
     * instant. Le second attend le premier, sans interblocage, et tous
     * deux passent.
     */
    #[DataProvider('gestesEtPremiersPendantLaSaisieDuCode')]
    #[Group('concurrence')]
    public function test_un_geste_du_proprietaire_pendant_la_saisie_du_code_passe_avant_ou_apres_elle_sans_interblocage(string $geste, bool $leGesteDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $societe = $this->inscrireUneSociete();
        $magasin = $this->creerLeMagasinDuSiege($societe);
        app(TenantContext::class)->set($magasin);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $documentId = $this->preparer($geste, $proprietaire);
        [$siren, $code, $email] = [(string) $societe->siren, $this->codeDuCourrier($societe), (string) $proprietaire->email];
        $magasinId = $magasin->id;
        $faire = static function () use ($geste, $magasinId, $documentId): void {
            self::faire($geste, $magasinId, $documentId);
        };
        $saisir = static function () use ($siren, $code, $email): void {
            app(TenantContext::class)->clear();
            app(SaisirCodeDeConfirmationAction::class)->handle($siren, $code, $email, self::MOT_DE_PASSE_CHOISI, '', '127.0.0.1');
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', $leGesteDAbord ? 'for no key update' : 'for update', $leGesteDAbord ? $saisir : $faire, $concurrente);

        $leGesteDAbord ? $faire() : $saisir();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertTrue(Societe::query()->findOrFail($societe->id)->estValidee());
        app(TenantContext::class)->set($magasin->fresh());
        $this->assertTrue($this->valide($geste, $documentId));
    }

    /**
     * Le brouillon ou la fiche du geste, saisi par le propriétaire avant
     * toute concurrence : son identifiant ; rien pour une création ou les
     * paramètres du magasin.
     */
    private function preparer(string $geste, User $proprietaire): int
    {
        $carnet = ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10];

        return match ($geste) {
            'validerUneFactureDAchat' => app(CreerFactureAchatAction::class)->handle($proprietaire, Fournisseur::factory()->create(), [[...$carnet, 'prix_unitaire_ht' => '2.00']])->id,
            'validerUnInventaire' => app(CreerInventaireOuvertureAction::class)->handle($proprietaire, [[...$carnet, 'cout_unitaire_ht' => '2.00']])->id,
            'modifierUneFicheProduit' => Produit::factory()->create(['nom' => 'Coque rouge'])->id,
            'creerUnClient', 'reglerLesParametresDuMagasin' => 0,
        };
    }

    /**
     * Le propriétaire fait le geste, dans le processus du test ou dans le
     * sien.
     */
    private static function faire(string $geste, int $magasinId, int $documentId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $proprietaire = User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail();

        match ($geste) {
            'validerUneFactureDAchat' => app(ValiderFactureAchatAction::class)->handle($proprietaire, FactureAchat::query()->findOrFail($documentId)),
            'validerUnInventaire' => app(ValiderInventaireOuvertureAction::class)->handle($proprietaire, InventaireOuverture::query()->findOrFail($documentId)),
            'modifierUneFicheProduit' => app(ModifierUneFicheAction::class)->handle($proprietaire, $produit = Produit::query()->findOrFail($documentId), FicheLue::de($produit)->valeurs(), ['nom' => 'Coque bleue']),
            'creerUnClient' => app(CreerUneFicheAction::class)->handle($proprietaire, Client::class, ['nom' => 'Nouveau client', 'type_client' => TypeClient::Particulier]),
            'reglerLesParametresDuMagasin' => app(ModifierLesParametresDuMagasinAction::class)->handle($proprietaire, PermissionRegistry::PARAMETRES_GERER, FicheLue::de($magasin)->valeurs(), ['delai_paiement_jours' => 45]),
        };
    }

    /**
     * Le geste s'est-il fait : le brouillon validé, la fiche modifiée, le
     * client créé, les paramètres réglés ?
     */
    private function valide(string $geste, int $documentId): bool
    {
        return match ($geste) {
            'validerUneFactureDAchat' => FactureAchat::query()->findOrFail($documentId)->statut === StatutFactureAchat::Validee,
            'validerUnInventaire' => InventaireOuverture::query()->findOrFail($documentId)->statut === StatutInventaireOuverture::Valide,
            'modifierUneFicheProduit' => Produit::query()->findOrFail($documentId)->nom === 'Coque bleue',
            'creerUnClient' => Client::query()->where('nom', 'Nouveau client')->exists(),
            'reglerLesParametresDuMagasin' => app(TenantContext::class)->magasin()->fresh()->delai_paiement_jours === 45,
        };
    }

    /**
     * Le geste passé le premier s'inscrit au journal d'audit avant la
     * clôture de l'inscription : la validation d'un inventaire d'ouverture
     * y précède la clôture (une facture d'achat validée ne s'y inscrit
     * pas).
     */
    private function assertGesteInscritAvantLaCloture(string $geste, Societe $societe): void
    {
        $cloture = $this->entreesDAudit($societe, ActionAuditee::InscriptionClose);
        $this->assertCount(1, $cloture);

        if ($geste === 'validerUnInventaire') {
            $validation = $this->entreesDAudit($societe, ActionAuditee::InventaireOuvertureValide);
            $this->assertCount(1, $validation);
            $this->assertLessThan($cloture[0]->id, $validation[0]->id);
        }
    }
}
