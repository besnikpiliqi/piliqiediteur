<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Actions\CreerUneFicheAction;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\SupprimerDevisAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ModifierInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Actions\RouvrirMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un geste sur un brouillon, un devis, une fiche, une vente émise ou le
 * panier de la caisse, et la fermeture ou la réouverture du magasin au
 * même instant, chacun dans son processus, sur une vraie base PostgreSQL.
 * Le geste relit la ligne du magasin sous un verrou partagé, après le
 * registre fiscal quand il y écrit, et la fermeture comme la réouverture
 * verrouillent le registre puis cette ligne (voir FermerMagasinAction et
 * RouvrirMagasinAction) : la seconde attend la première, sans
 * interblocage. Un geste qui passe avant la fermeture s'inscrit avant
 * elle ; un geste qui l'attendait est refusé, le magasin étant fermé, et
 * rien ne s'écrit. Un geste commencé dans un magasin fermé est refusé,
 * même s'il attendait sa réouverture.
 *
 * La suspension de l'auteur du geste au même instant : elle verrouille sa
 * ligne, que le geste relit sous verrou (voir LecteurDesDroits) ; un geste
 * qui l'attendait est refusé, son auteur étant suspendu, et rien ne
 * s'écrit.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class GestesPendantLaFermetureConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const MAGASIN_FERME = 'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.';

    /**
     * Chaque geste, s'il écrit au registre fiscal du magasin, dont il prend
     * alors le verrou en premier, et le verrou qu'il prend sur la ligne du
     * magasin : partagé, ou, pour les paramètres du magasin qu'il écrit,
     * exclusif de toute autre écriture de cette ligne.
     *
     * @var array<string, array{0: string, 1: bool, 2?: string}>
     */
    private const GESTES = [
        'créer une facture d\'achat' => ['creerUneFactureDAchat', false],
        'supprimer un brouillon de facture d\'achat' => ['supprimerUnBrouillonDAchat', false],
        'supprimer un brouillon de facture' => ['supprimerUnBrouillonDeFacture', true],
        'modifier un brouillon de facture' => ['modifierUnBrouillonDeFacture', true],
        'créer une facture' => ['creerUneFacture', false],
        'supprimer un devis' => ['supprimerUnDevis', false],
        'modifier un devis' => ['modifierUnDevis', false],
        'accepter un devis' => ['accepterUnDevis', false],
        'refuser un devis' => ['refuserUnDevis', false],
        'convertir un devis' => ['convertirUnDevis', false],
        'créer un devis' => ['creerUnDevis', false],
        'numéroter un devis' => ['numeroterUnDevis', false],
        'envoyer un devis par e-mail' => ['envoyerUnDevis', false],
        'noter un bordereau de détaxe' => ['noterUnBordereau', false],
        'noter une preuve d\'exonération' => ['noterUnePreuve', false],
        'archiver un client' => ['archiverUnClient', false],
        'restaurer un produit' => ['restaurerUnProduit', false],
        'retirer un article du panier de la caisse' => ['retirerUnArticleDuPanier', true],
        'abandonner la vente de la caisse' => ['abandonnerLaVente', true],
        'saisir un inventaire d\'ouverture' => ['saisirUnInventaire', true],
        'modifier un inventaire d\'ouverture' => ['modifierUnInventaire', true],
        'supprimer un inventaire d\'ouverture' => ['supprimerUnInventaire', false],
        'valider une facture d\'achat' => ['validerUneFactureDAchat', false],
        'corriger une facture d\'achat validée' => ['corrigerUneFactureDAchat', false],
        'modifier une fiche produit' => ['modifierUneFicheProduit', false],
        'modifier le taux d\'une fiche produit' => ['modifierLeTauxDUneFicheProduit', true],
        'modifier un client' => ['modifierUnClient', false],
        'modifier un fournisseur' => ['modifierUnFournisseur', false],
        'modifier un service' => ['modifierUnService', false],
        'créer un client' => ['creerUnClient', false],
        'créer un fournisseur' => ['creerUnFournisseur', false],
        'créer un service' => ['creerUnService', false],
        'créer un service depuis une vente' => ['trouverOuCreerUnService', false],
        'régler les paramètres du magasin' => ['reglerLesParametresDuMagasin', false, 'for no key update'],
    ];

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: string}>
     */
    public static function gestesEtOrdres(): array
    {
        $cas = [];

        foreach (self::GESTES as $libelle => $geste) {
            [$methode, $surLeRegistre] = $geste;
            $verrouDuMagasin = $geste[2] ?? 'for share';
            $cas["{$libelle}, le geste d'abord"] = [$methode, $surLeRegistre, true, $verrouDuMagasin];
            $cas["{$libelle}, l'autre geste d'abord"] = [$methode, $surLeRegistre, false, $verrouDuMagasin];
        }

        return $cas;
    }

    #[DataProvider('gestesEtOrdres')]
    #[Group('concurrence')]
    public function test_un_geste_simultane_a_la_fermeture_du_magasin_passe_avant_elle_ou_est_refuse_apres(string $geste, bool $surLeRegistre, bool $leGesteDAbord, string $verrouDuMagasin): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        self::responsableDeTest($magasin);
        $parametres = $this->preparer($geste);
        $magasinId = $magasin->id;
        $faire = static function () use ($geste, $magasinId, $parametres): void {
            self::faire($geste, $magasinId, $parametres);
        };
        $fermer = static function () use ($magasinId): void {
            self::fermer($magasinId);
        };

        $resultat = $this->croiser($leGesteDAbord, $surLeRegistre, $verrouDuMagasin, $faire, $fermer);

        app(TenantContext::class)->set($magasin);
        $this->assertSame(StatutMagasin::Ferme, $magasin->fresh()->statut);

        if ($leGesteDAbord) {
            $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        } else {
            $this->assertTrue($resultat->failed(), $resultat->output());
            $this->assertStringContainsString(self::MAGASIN_FERME, $resultat->errorOutput());
        }

        $this->verifier($geste, $leGesteDAbord, $parametres);
        $this->assertSame([], $this->abandonsEtRetraitsApresLaFermeture());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un geste commencé dans le magasin fermé, dont la requête a lu le
     * magasin fermé : passé avant la réouverture, il est refusé, le magasin
     * étant fermé ; s'il l'attendait, il est refusé aussi, la requête
     * l'ayant commencé dans un magasin en lecture seule (voir
     * ControleDesDroits), et la réouverture passe sans interblocage. Rien
     * ne s'écrit.
     */
    #[DataProvider('gestesEtOrdres')]
    #[Group('concurrence')]
    public function test_un_geste_commence_dans_le_magasin_ferme_est_refuse_avant_sa_reouverture_comme_apres(string $geste, bool $surLeRegistre, bool $leGesteDAbord, string $verrouDuMagasin): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        self::responsableDeTest($magasin);
        $parametres = $this->preparer($geste);
        $magasinId = $magasin->id;
        self::fermer($magasinId);
        $faire = static function () use ($geste, $magasinId, $parametres): void {
            self::faire($geste, $magasinId, $parametres);
        };
        $rouvrir = static function () use ($magasinId): void {
            self::rouvrir($magasinId);
        };

        if ($leGesteDAbord) {
            $this->assertThrows($faire, LogicException::class, self::MAGASIN_FERME);
            self::rouvrir($magasinId);
        } else {
            $resultat = $this->croiser(false, $surLeRegistre, $verrouDuMagasin, $faire, $rouvrir);
            $this->assertTrue($resultat->failed(), $resultat->output());
            $this->assertStringContainsString(LecteurDesDroits::REFUS, $resultat->errorOutput());
        }

        app(TenantContext::class)->set($magasin);
        $this->verifier($geste, false, $parametres);
        $this->assertSame(StatutMagasin::Actif, $magasin->fresh()->statut);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function gestesPendantLaSuspension(): array
    {
        return array_map(static fn (array $geste): array => [$geste[0]], self::GESTES);
    }

    /**
     * Le propriétaire suspend le responsable de test pendant que celui-ci
     * fait son geste, commencé dans un écran ouvert avant la suspension :
     * le geste attend la ligne de son auteur, puis est refusé.
     */
    #[DataProvider('gestesPendantLaSuspension')]
    #[Group('concurrence')]
    public function test_un_geste_qui_attendait_la_suspension_de_son_auteur_est_refuse_sans_rien_ecrire(string $geste): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $parametres = $this->preparer($geste);
        $magasinId = $magasin->id;
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', 'for update', static function () use ($geste, $magasinId, $parametres): void {
            self::faire($geste, $magasinId, $parametres);
        }, $concurrente);

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $responsable);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->failed(), $resultat->output());
        $this->assertStringContainsString(LecteurDesDroits::REFUS, $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertTrue($responsable->fresh()->estSuspendu());
        $this->verifier($geste, false, $parametres);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Le geste et l'autre geste au même instant, dans cet ordre, l'un dans
     * le processus du test, l'autre dans le sien : le second démarre dès
     * que le premier tient le verrou qu'il attendra, et attend qu'il l'ait
     * rendu. Ce verrou est le registre fiscal pour un geste qui y écrit ;
     * sinon la ligne du magasin, sous le verrou du geste quand il passe le
     * premier, ou verrouillée par la fermeture ou la réouverture. Rend le
     * résultat du processus concurrent.
     *
     * @param  Closure(): void  $faire
     * @param  Closure(): void  $autre
     */
    private function croiser(bool $leGesteDAbord, bool $surLeRegistre, string $verrouDuMagasin, Closure $faire, Closure $autre): ProcessResult
    {
        $concurrente = null;
        $second = $leGesteDAbord ? $autre : $faire;

        match (true) {
            $surLeRegistre => $this->concurrencerDesQueLeRegistreEstVerrouille($second, $concurrente),
            $leGesteDAbord => $this->concurrencerDesQueLaLigneEstVerrouillee('magasins', $verrouDuMagasin, $second, $concurrente),
            default => $this->concurrencerDesQueLaLigneEstVerrouillee('magasins', 'for update', $second, $concurrente),
        };

        $leGesteDAbord ? $faire() : $autre();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);

        return $concurrente->wait();
    }

    /**
     * Prépare le geste, dans le processus du test, avant toute
     * concurrence : rend ce dont il a besoin, en valeurs simples.
     *
     * @return array<string, int|string>
     */
    private function preparer(string $geste): array
    {
        $auteur = self::auteur();
        $produit = Produit::factory()->create(['nom' => 'Coque rouge', 'reference' => 'COQUE', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = fn (): Devis => app(CreerDevisAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $carnet = ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'];

        return match ($geste) {
            'creerUneFactureDAchat' => ['fournisseur' => Fournisseur::factory()->create()->id, 'produit' => $produit->id],
            'supprimerUnBrouillonDAchat' => ['id' => app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]])->id],
            'supprimerUnBrouillonDeFacture', 'modifierUnBrouillonDeFacture' => ['id' => app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]])->id, 'produit' => $produit->id],
            'creerUneFacture', 'creerUnDevis' => ['client' => Client::factory()->create()->id, 'produit' => $produit->id],
            'supprimerUnDevis', 'numeroterUnDevis', 'envoyerUnDevis' => ['id' => $devis()->id],
            'modifierUnDevis' => ['id' => $devis()->id, 'produit' => $produit->id],
            'accepterUnDevis', 'refuserUnDevis' => ['id' => app(EmettreDevisAction::class)->handle($auteur, $devis())->id],
            'convertirUnDevis' => ['id' => app(AccepterDevisAction::class)->handle($auteur, app(EmettreDevisAction::class)->handle($auteur, $devis()))->id],
            'noterUnBordereau' => ['id' => self::vendreAuComptant($auteur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 10]], ModePaiement::Especes)->id],
            'noterUnePreuve' => ['id' => app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], [
                'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
            ]))->id],
            'archiverUnClient' => ['id' => Client::factory()->create()->id],
            'restaurerUnProduit' => ['id' => tap($produit, fn (Produit $produit) => app(ArchiverUneFicheAction::class)->archiver($auteur, $produit))->id],
            'retirerUnArticleDuPanier', 'abandonnerLaVente' => tap(['jeton' => '9f1c2d7a-6b1e-4c3f-8d2a-1e5b7c9d0a11', 'produit' => $produit->id], fn (array $panier) => app(PanierDeCaisse::class)->enregistrer($auteur, $panier['jeton'], null, self::lignesDuPanier($produit->id, 2))),
            'saisirUnInventaire' => [],
            'modifierUnInventaire', 'supprimerUnInventaire' => ['id' => app(CreerInventaireOuvertureAction::class)->handle($auteur, [$carnet])->id],
            'validerUneFactureDAchat' => ['id' => app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]])->id],
            'corrigerUneFactureDAchat' => ['id' => app(ValiderFactureAchatAction::class)->handle($auteur, app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]]))->id, 'produit' => $produit->id],
            'modifierUneFicheProduit', 'modifierLeTauxDUneFicheProduit' => ['id' => $produit->id],
            'modifierUnClient' => ['id' => Client::factory()->create(['nom' => 'Martin'])->id],
            'modifierUnFournisseur' => ['id' => Fournisseur::factory()->create(['nom' => 'Grossiste'])->id],
            'modifierUnService' => ['id' => Service::factory()->create(['nom' => 'Diagnostic'])->id],
            'creerUnClient', 'creerUnFournisseur', 'creerUnService', 'trouverOuCreerUnService', 'reglerLesParametresDuMagasin' => [],
        };
    }

    /**
     * Le geste du responsable de test du magasin, obtenu par le test avant
     * de lancer l'autre processus (voir auteur()), dans le processus du
     * test ou dans le sien.
     *
     * @param  array<string, int|string>  $parametres
     */
    private static function faire(string $geste, int $magasinId, array $parametres): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $auteur = self::responsableDeTest($magasin);
        $carnet = ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 12, 'cout_unitaire_ht' => '2.00'];

        match ($geste) {
            'creerUneFactureDAchat' => app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::query()->findOrFail($parametres['fournisseur']), [['produit_id' => $parametres['produit'], 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]]),
            'supprimerUnBrouillonDAchat' => app(SupprimerFactureAchatAction::class)->handle($auteur, FactureAchat::query()->findOrFail($parametres['id'])),
            'supprimerUnBrouillonDeFacture' => app(SupprimerFactureAction::class)->handle($auteur, Facture::query()->findOrFail($parametres['id'])),
            'modifierUnBrouillonDeFacture' => app(ModifierFactureAction::class)->handle($auteur, $brouillon = Facture::query()->findOrFail($parametres['id']), $brouillon->client, [['produit_id' => $parametres['produit'], 'quantite' => 1]]),
            'creerUneFacture' => app(CreerFactureAction::class)->handle($auteur, Client::query()->findOrFail($parametres['client']), [['produit_id' => $parametres['produit'], 'quantite' => 1]]),
            'supprimerUnDevis' => app(SupprimerDevisAction::class)->handle($auteur, Devis::query()->findOrFail($parametres['id'])),
            'modifierUnDevis' => app(ModifierDevisAction::class)->handle($auteur, $devis = Devis::query()->findOrFail($parametres['id']), $devis->client, [['produit_id' => $parametres['produit'], 'quantite' => 3]]),
            'accepterUnDevis' => app(AccepterDevisAction::class)->handle($auteur, Devis::query()->findOrFail($parametres['id'])),
            'refuserUnDevis' => app(RefuserDevisAction::class)->handle($auteur, Devis::query()->findOrFail($parametres['id'])),
            'convertirUnDevis' => app(CreerFactureDepuisDevisAction::class)->handle($auteur, Devis::query()->findOrFail($parametres['id'])),
            'creerUnDevis' => app(CreerDevisAction::class)->handle($auteur, Client::query()->findOrFail($parametres['client']), [['produit_id' => $parametres['produit'], 'quantite' => 1]]),
            'numeroterUnDevis' => app(EmettreDevisAction::class)->handle($auteur, Devis::query()->findOrFail($parametres['id'])),
            'envoyerUnDevis' => app(EnvoyerDocumentAction::class)->handle($auteur, Devis::query()->findOrFail($parametres['id']), 'client@exemple.fr', null),
            'noterUnBordereau' => app(NoterBordereauDetaxeAction::class)->handle($auteur, Facture::query()->findOrFail($parametres['id']), '2026FR000123456'),
            'noterUnePreuve' => app(AjouterPreuveOperationAction::class)->handle($auteur, Facture::query()->findOrFail($parametres['id']), TypePreuve::Mrn, '26FR000000123456E1'),
            'archiverUnClient' => app(ArchiverUneFicheAction::class)->archiver($auteur, Client::query()->findOrFail($parametres['id'])),
            'restaurerUnProduit' => app(ArchiverUneFicheAction::class)->restaurer($auteur, Produit::withTrashed()->findOrFail($parametres['id'])),
            'retirerUnArticleDuPanier' => app(PanierDeCaisse::class)->enregistrer($auteur, (string) $parametres['jeton'], null, self::lignesDuPanier((int) $parametres['produit'], 1)),
            'abandonnerLaVente' => app(PanierDeCaisse::class)->abandonner($auteur, (string) $parametres['jeton'], null, self::lignesDuPanier((int) $parametres['produit'], 2)),
            'saisirUnInventaire' => app(CreerInventaireOuvertureAction::class)->handle($auteur, [$carnet]),
            'modifierUnInventaire' => app(ModifierInventaireOuvertureAction::class)->handle($auteur, InventaireOuverture::query()->findOrFail($parametres['id']), [$carnet]),
            'supprimerUnInventaire' => app(SupprimerInventaireOuvertureAction::class)->handle($auteur, InventaireOuverture::query()->findOrFail($parametres['id'])),
            'validerUneFactureDAchat' => app(ValiderFactureAchatAction::class)->handle($auteur, FactureAchat::query()->findOrFail($parametres['id'])),
            'corrigerUneFactureDAchat' => app(ModifierFactureAchatAction::class)->handle($auteur, $factureAchat = FactureAchat::query()->findOrFail($parametres['id']), $factureAchat->fournisseur, [['produit_id' => $parametres['produit'], 'quantite' => 8, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]]),
            'modifierUneFicheProduit' => self::modifierLaFiche($auteur, Produit::query()->findOrFail($parametres['id']), ['nom' => 'Coque bleue']),
            'modifierLeTauxDUneFicheProduit' => self::modifierLaFiche($auteur, Produit::query()->findOrFail($parametres['id']), ['taux_tva' => '5.5']),
            'modifierUnClient' => self::modifierLaFiche($auteur, Client::query()->findOrFail($parametres['id']), ['nom' => 'Martin et fils']),
            'modifierUnFournisseur' => self::modifierLaFiche($auteur, Fournisseur::query()->findOrFail($parametres['id']), ['nom' => 'Grossiste du Sud']),
            'modifierUnService' => self::modifierLaFiche($auteur, Service::query()->findOrFail($parametres['id']), ['nom' => 'Diagnostic complet']),
            'creerUnClient' => app(CreerUneFicheAction::class)->handle($auteur, Client::class, ['nom' => 'Nouveau client', 'type_client' => TypeClient::Particulier]),
            'creerUnFournisseur' => app(CreerUneFicheAction::class)->handle($auteur, Fournisseur::class, ['nom' => 'Nouveau fournisseur']),
            'creerUnService' => app(CreerUneFicheAction::class)->handle($auteur, Service::class, ['nom' => 'Nouveau service', 'actif' => true]),
            'trouverOuCreerUnService' => app(TrouverOuCreerServiceAction::class)->handle($auteur, 'Nouveau service'),
            'reglerLesParametresDuMagasin' => app(ModifierLesParametresDuMagasinAction::class)->handle($auteur, PermissionRegistry::PARAMETRES_GERER, FicheLue::de($magasin)->valeurs(), ['delai_paiement_jours' => 45]),
        };
    }

    /**
     * Le geste d'une page de modification ouverte sur cette fiche telle
     * qu'elle est en base (voir EnregistreLesCorrectionsDeLaFiche).
     *
     * @param  array<string, mixed>  $saisie
     */
    private static function modifierLaFiche(User $auteur, Client|Fournisseur|Produit|Service $fiche, array $saisie): void
    {
        app(ModifierUneFicheAction::class)->handle($auteur, $fiche, FicheLue::de($fiche)->valeurs(), $saisie);
    }

    /**
     * Ce que le geste a écrit, ou rien.
     *
     * @param  array<string, int|string>  $parametres
     */
    private function verifier(string $geste, bool $fait, array $parametres): void
    {
        match ($geste) {
            'creerUneFactureDAchat' => $this->assertSame($fait ? 1 : 0, FactureAchat::query()->count()),
            'supprimerUnBrouillonDAchat' => $this->assertSame($fait ? 0 : 1, FactureAchat::query()->count()),
            'supprimerUnBrouillonDeFacture' => $this->assertSame([$fait ? 0 : 1, $fait ? 1 : 0], [Facture::query()->count(), $this->nombreDEvenements('320', 'brouillon')]),
            'modifierUnBrouillonDeFacture' => $this->assertSame([$fait ? 1 : 2, $fait ? 1 : 0], [Facture::query()->findOrFail($parametres['id'])->lignes()->sole()->quantite, $this->nombreDEvenements('323', 'brouillon')]),
            'creerUneFacture', 'convertirUnDevis' => $this->assertSame($fait ? 1 : 0, Facture::query()->count()),
            'supprimerUnDevis' => $this->assertSame($fait, Devis::withTrashed()->findOrFail($parametres['id'])->trashed()),
            'modifierUnDevis' => $this->assertSame($fait ? 3 : 1, Devis::query()->findOrFail($parametres['id'])->lignes()->sole()->quantite),
            'accepterUnDevis' => $this->assertSame($fait ? StatutDevis::Accepte : StatutDevis::Envoye, Devis::query()->findOrFail($parametres['id'])->statut),
            'refuserUnDevis' => $this->assertSame($fait ? StatutDevis::Refuse : StatutDevis::Envoye, Devis::query()->findOrFail($parametres['id'])->statut),
            'creerUnDevis' => $this->assertSame($fait ? 1 : 0, Devis::query()->count()),
            'numeroterUnDevis' => $this->assertSame($fait, Devis::query()->findOrFail($parametres['id'])->numero !== null),
            'envoyerUnDevis' => $this->assertSame([$fait, $fait ? 1 : 0], [Devis::query()->findOrFail($parametres['id'])->numero !== null, EnvoiDocument::query()->count()]),
            'noterUnBordereau' => $this->assertSame($fait ? 1 : 0, BordereauDetaxe::query()->count()),
            'noterUnePreuve' => $this->assertSame($fait ? 1 : 0, PreuveOperation::query()->count()),
            'archiverUnClient' => $this->assertSame($fait, Client::withTrashed()->findOrFail($parametres['id'])->trashed()),
            'restaurerUnProduit' => $this->assertSame(! $fait, Produit::withTrashed()->findOrFail($parametres['id'])->trashed()),
            'retirerUnArticleDuPanier' => $this->assertSame($fait ? 1 : 0, $this->nombreDEvenements('323', 'caisse')),
            'abandonnerLaVente' => $this->assertSame($fait ? 1 : 0, $this->nombreDEvenements('320', 'caisse')),
            'saisirUnInventaire' => $this->assertSame($fait ? 1 : 0, InventaireOuverture::query()->count()),
            'modifierUnInventaire' => $this->assertSame($fait ? 12 : 10, InventaireOuverture::query()->findOrFail($parametres['id'])->lignes()->sole()->quantite),
            'supprimerUnInventaire' => $this->assertSame($fait ? 0 : 1, InventaireOuverture::query()->count()),
            'validerUneFactureDAchat' => $this->assertSame($fait ? StatutFactureAchat::Validee : StatutFactureAchat::Brouillon, FactureAchat::query()->findOrFail($parametres['id'])->statut),
            'corrigerUneFactureDAchat' => $this->assertSame($fait ? 8 : 5, FactureAchat::query()->findOrFail($parametres['id'])->lignes()->sole()->quantite),
            'modifierUneFicheProduit' => $this->assertSame($fait ? 'Coque bleue' : 'Coque rouge', Produit::query()->findOrFail($parametres['id'])->nom),
            'modifierLeTauxDUneFicheProduit' => $this->assertSame($fait ? '5.50' : '20.00', Produit::query()->findOrFail($parametres['id'])->taux_tva),
            'modifierUnClient' => $this->assertSame($fait ? 'Martin et fils' : 'Martin', Client::query()->findOrFail($parametres['id'])->nom),
            'modifierUnFournisseur' => $this->assertSame($fait ? 'Grossiste du Sud' : 'Grossiste', Fournisseur::query()->findOrFail($parametres['id'])->nom),
            'modifierUnService' => $this->assertSame($fait ? 'Diagnostic complet' : 'Diagnostic', Service::query()->findOrFail($parametres['id'])->nom),
            'creerUnClient' => $this->assertSame($fait ? 1 : 0, Client::query()->where('nom', 'Nouveau client')->count()),
            'creerUnFournisseur' => $this->assertSame($fait ? 1 : 0, Fournisseur::query()->where('nom', 'Nouveau fournisseur')->count()),
            'creerUnService', 'trouverOuCreerUnService' => $this->assertSame($fait ? 1 : 0, Service::query()->where('nom', 'Nouveau service')->count()),
            'reglerLesParametresDuMagasin' => $this->assertSame($fait ? 45 : 30, app(TenantContext::class)->magasin()->fresh()->delai_paiement_jours),
        };
    }

    /**
     * Le nombre d'événements de ce code et de cette origine au journal des
     * événements du magasin.
     */
    private function nombreDEvenements(string $code, string $origine): int
    {
        return EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', $code)->get()
            ->filter(fn (EnregistrementFiscal $evenement): bool => ($evenement->contenuSigne()['donnees']['details']['origine'] ?? null) === $origine)
            ->count();
    }

    /**
     * Les abandons de vente (JET 320) et les articles retirés (JET 323)
     * inscrits après la fermeture du magasin (JET 270 statut_magasin) : il
     * n'y en a jamais.
     *
     * @return list<string>
     */
    private function abandonsEtRetraitsApresLaFermeture(): array
    {
        $evenements = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->get();
        $fermeture = $evenements->first(fn (EnregistrementFiscal $evenement): bool => $evenement->type === '270' && ($evenement->contenuSigne()['donnees']['details']['parametre'] ?? null) === 'statut_magasin');
        $this->assertNotNull($fermeture);

        return $evenements
            ->filter(fn (EnregistrementFiscal $evenement): bool => $evenement->sequence > $fermeture->sequence && in_array($evenement->type, ['320', '323'], true))
            ->pluck('type')
            ->values()
            ->all();
    }

    /**
     * Les lignes du panier de la caisse : un article et, à deux, un second.
     *
     * @return array<string, array<string, int|string>>
     */
    private static function lignesDuPanier(int $produitId, int $articles): array
    {
        $ligne = ['produit_id' => $produitId, 'quantite' => 1, 'prix_unitaire_ttc' => '12.00', 'taux_tva' => '20', 'remise_pourcentage' => '0'];

        return array_slice(['premier' => $ligne, 'second' => $ligne], 0, $articles);
    }

    private static function fermer(int $magasinId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(FermerMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, MotifFermeture::Temporaire, 'password');
    }

    private static function rouvrir(int $magasinId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);

        app(RouvrirMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), $magasin, 'password');
    }
}
