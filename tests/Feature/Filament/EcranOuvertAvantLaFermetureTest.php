<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Pages\CoordonneesMagasin;
use App\Filament\App\Pages\ParametresFactures;
use App\Filament\App\Pages\ParametresPrixDeVente;
use App\Filament\App\Pages\ParametresRelances;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\ClientResource\Pages\CreateClient;
use App\Filament\App\Resources\ClientResource\Pages\EditClient;
use App\Filament\App\Resources\ClientResource\Pages\ListClients;
use App\Filament\App\Resources\DevisResource\Pages\EditDevis;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\FactureAchatResource\Pages\EditFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FournisseurResource;
use App\Filament\App\Resources\FournisseurResource\Pages\CreateFournisseur;
use App\Filament\App\Resources\FournisseurResource\Pages\EditFournisseur;
use App\Filament\App\Resources\FournisseurResource\Pages\ListFournisseurs;
use App\Filament\App\Resources\ProduitResource;
use App\Filament\App\Resources\ProduitResource\Pages\EditProduit;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Filament\App\Resources\ServiceResource\Pages\ManageServices;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Livewire\Mechanisms\ComponentRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le bouton d'un brouillon ou d'une fiche, sur la liste, la page de
 * modification ou dans une action groupée, ouvert avant que le magasin ne
 * ferme ou que son auteur ne soit suspendu, et confirmé après : l'écran a
 * lu le magasin et son auteur à l'ouverture, encore ouvert et encore
 * autorisé, mais le geste passe par l'action du domaine, qui les relit en
 * base sous verrou au moment d'écrire. Le refus se lit en notification,
 * et le document ou la fiche reste tel qu'il était ; une ligne cochée que
 * l'action groupée n'a pas pu retirer reste cochée.
 *
 * De même pour le formulaire d'une fiche (la créer ou la modifier, sur sa
 * page, dans sa fenêtre ou dans la création rapide d'un client) ou d'une
 * page de paramètres du magasin, rempli avant et enregistré après : rien
 * ne s'écrit.
 *
 * Le navigateur, lui, ne garde pas le magasin d'avant : sa requête
 * suivante relit le magasin fermé, et une page de modification, de
 * création ou de paramètres revient au tableau de bord, qui dit pourquoi,
 * au lieu d'une page interdite (voir
 * test_un_formulaire_envoye_par_le_navigateur_apres_la_fermeture_revient_au_tableau_de_bord_qui_dit_pourquoi()).
 */
class EcranOuvertAvantLaFermetureTest extends TestCase
{
    use RefreshDatabase;

    private const MAGASIN_FERME = 'Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.';

    /**
     * Le document ou la fiche ; l'écran et son bouton ouverts ; ce qui doit
     * en rester ; le bouton : sur une ligne de la liste, sur la page, ou
     * une action groupée.
     *
     * @return array<string, array{0: Closure(): Model, 1: Closure(Model): Testable, 2: Closure(Model): void, 3: string}>
     */
    public static function boutons(): array
    {
        $brouillonDAchat = static fn (): FactureAchat => app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00', 'taux_tva' => 20]]);
        $brouillonDeFacture = static fn (): Facture => app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20])->id, 'quantite' => 1]]);
        $devis = static fn (): Devis => app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20])->id, 'quantite' => 1]]);
        $archive = static fn (Model $fiche): Model => tap($fiche, fn (Model $fiche) => app(ArchiverUneFicheAction::class)->archiver(self::auteur(), $fiche));
        $existe = static fn (string $classe): Closure => static fn (Model $document) => self::assertTrue($classe::query()->whereKey($document->getKey())->exists());
        $actif = static fn (Model $fiche) => self::assertFalse($fiche->fresh()->trashed());
        $archivee = static fn (Model $fiche) => self::assertTrue($fiche->fresh()->trashed());

        return [
            'supprimer un brouillon de facture d\'achat depuis la liste' => [$brouillonDAchat, static fn (Model $document) => Livewire::test(ListFacturesAchat::class)->mountTableAction('delete', $document), $existe(FactureAchat::class), 'ligne'],
            'supprimer un brouillon de facture d\'achat depuis sa modification' => [$brouillonDAchat, static fn (Model $document) => Livewire::test(EditFactureAchat::class, ['record' => $document->getRouteKey()])->mountAction('delete'), $existe(FactureAchat::class), 'page'],
            'supprimer des brouillons de facture d\'achat en masse' => [$brouillonDAchat, static fn (Model $document) => Livewire::test(ListFacturesAchat::class)->mountTableBulkAction('delete', [$document->getKey()]), $existe(FactureAchat::class), 'suppression en masse'],
            'supprimer un brouillon de facture depuis la liste' => [$brouillonDeFacture, static fn (Model $document) => Livewire::test(ListFactures::class)->mountTableAction('delete', $document), $existe(Facture::class), 'ligne'],
            'supprimer un brouillon de facture depuis sa modification' => [$brouillonDeFacture, static fn (Model $document) => Livewire::test(EditFacture::class, ['record' => $document->getRouteKey()])->mountAction('delete'), $existe(Facture::class), 'page'],
            'supprimer des brouillons de facture en masse' => [$brouillonDeFacture, static fn (Model $document) => Livewire::test(ListFactures::class)->mountTableBulkAction('delete', [$document->getKey()]), $existe(Facture::class), 'suppression en masse'],
            'supprimer un devis depuis sa modification' => [$devis, static fn (Model $document) => Livewire::test(EditDevis::class, ['record' => $document->getRouteKey()])->mountAction('delete'), $existe(Devis::class), 'page'],
            'supprimer des devis en masse' => [$devis, static fn (Model $document) => Livewire::test(ListDevis::class)->mountTableBulkAction('delete', [$document->getKey()]), $existe(Devis::class), 'suppression en masse'],
            'archiver un client depuis sa modification' => [static fn (): Client => Client::factory()->create(), static fn (Model $fiche) => Livewire::test(EditClient::class, ['record' => $fiche->getRouteKey()])->mountAction('archiver'), $actif, 'page'],
            'archiver des clients en masse' => [static fn (): Client => Client::factory()->create(), static fn (Model $fiche) => Livewire::test(ListClients::class)->mountTableBulkAction('archiver', [$fiche->getKey()]), $actif, 'archivage en masse'],
            'restaurer un client depuis la liste' => [static fn (): Model => $archive(Client::factory()->create()), static fn (Model $fiche) => Livewire::test(ListClients::class)->filterTable('trashed', false)->mountTableAction('restore', $fiche), $archivee, 'ligne'],
            'archiver un produit depuis sa modification' => [static fn (): Produit => Produit::factory()->create(), static fn (Model $fiche) => Livewire::test(EditProduit::class, ['record' => $fiche->getRouteKey()])->mountAction('archiver'), $actif, 'page'],
            'restaurer un produit depuis la liste' => [static fn (): Model => $archive(Produit::factory()->create()), static fn (Model $fiche) => Livewire::test(ListProduits::class)->filterTable('trashed', false)->mountTableAction('restore', $fiche), $archivee, 'ligne'],
            'restaurer un fournisseur depuis la liste' => [static fn (): Model => $archive(Fournisseur::factory()->create()), static fn (Model $fiche) => Livewire::test(ListFournisseurs::class)->filterTable('trashed', false)->mountTableAction('restore', $fiche), $archivee, 'ligne'],
        ];
    }

    /**
     * @param  Closure(): Model  $creer
     * @param  Closure(Model): Testable  $ouvrir
     * @param  Closure(Model): void  $resteTelQuIlEtait
     */
    #[DataProvider('boutons')]
    public function test_un_bouton_confirme_apres_la_fermeture_du_magasin_est_refuse_et_le_dit(Closure $creer, Closure $ouvrir, Closure $resteTelQuIlEtait, string $bouton): void
    {
        [$magasin, $responsable] = $this->dansLaBoutique();
        $enregistrement = $creer();
        $ecran = $ouvrir($enregistrement);

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), Magasin::query()->findOrFail($magasin->id), MotifFermeture::Temporaire, 'password');
        $this->assertFalse(app(TenantContext::class)->magasin()->estFerme());

        $this->confirmer($ecran, $bouton, self::MAGASIN_FERME);
        $resteTelQuIlEtait($enregistrement);
    }

    /**
     * @param  Closure(): Model  $creer
     * @param  Closure(Model): Testable  $ouvrir
     * @param  Closure(Model): void  $resteTelQuIlEtait
     */
    #[DataProvider('boutons')]
    public function test_un_bouton_confirme_apres_la_suspension_de_son_auteur_est_refuse_et_le_dit(Closure $creer, Closure $ouvrir, Closure $resteTelQuIlEtait, string $bouton): void
    {
        [$magasin, $responsable] = $this->dansLaBoutique();
        $enregistrement = $creer();
        $ecran = $ouvrir($enregistrement);

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), User::query()->findOrFail($responsable->id));
        $this->assertTrue(auth()->user()->estActif());

        $this->confirmer($ecran, $bouton, LecteurDesDroits::REFUS);
        $resteTelQuIlEtait($enregistrement);
    }

    /**
     * Le formulaire ouvert et rempli ; son enregistrement ; ce qui doit en
     * rester, ou ne pas exister.
     *
     * @return array<string, array{0: Closure(): Testable, 1: Closure(Testable): Testable, 2: Closure(): void}>
     */
    public static function formulaires(): array
    {
        $enregistrer = static fn (Testable $ecran): Testable => $ecran->call('save');
        $creer = static fn (Testable $ecran): Testable => $ecran->call('create');
        $fenetreDeLaListe = static fn (Testable $ecran): Testable => $ecran->callMountedTableAction();
        $fenetreDeLaPage = static fn (Testable $ecran): Testable => $ecran->callMountedAction();
        $fenetreDuChamp = static fn (Testable $ecran): Testable => $ecran->callMountedFormComponentAction();
        $nouveauClient = ['nom' => 'Nouveau client', 'type_client' => TypeClient::Particulier->value];
        $aucun = static fn (string $classe, string $nom): Closure => static fn () => self::assertSame(0, $classe::withoutGlobalScopes()->where('nom', $nom)->count());
        $magasin = static fn (): Magasin => app(TenantContext::class)->magasin()->fresh();

        return [
            'modifier un client sur sa page' => [static fn (): Testable => Livewire::test(EditClient::class, ['record' => Client::factory()->create(['nom' => 'Martin'])->getRouteKey()])->fillForm(['nom' => 'Martin et fils']), $enregistrer, $aucun(Client::class, 'Martin et fils')],
            'modifier un fournisseur sur sa page' => [static fn (): Testable => Livewire::test(EditFournisseur::class, ['record' => Fournisseur::factory()->create(['nom' => 'Grossiste'])->getRouteKey()])->fillForm(['nom' => 'Grossiste du Sud']), $enregistrer, $aucun(Fournisseur::class, 'Grossiste du Sud')],
            'modifier un produit sur sa page' => [static fn (): Testable => Livewire::test(EditProduit::class, ['record' => Produit::factory()->create(['nom' => 'Coque'])->getRouteKey()])->fillForm(['nom' => 'Coque rouge']), $enregistrer, $aucun(Produit::class, 'Coque rouge')],
            'modifier un service dans sa fenêtre' => [static fn (): Testable => Livewire::test(ManageServices::class)->mountTableAction('edit', Service::factory()->create(['nom' => 'Diagnostic']))->setTableActionData(['nom' => 'Diagnostic complet']), $fenetreDeLaListe, $aucun(Service::class, 'Diagnostic complet')],
            'créer un client sur sa page' => [static fn (): Testable => Livewire::test(CreateClient::class)->fillForm($nouveauClient), $creer, $aucun(Client::class, 'Nouveau client')],
            'créer un fournisseur sur sa page' => [static fn (): Testable => Livewire::test(CreateFournisseur::class)->fillForm(['nom' => 'Nouveau fournisseur']), $creer, $aucun(Fournisseur::class, 'Nouveau fournisseur')],
            'créer un service dans sa fenêtre' => [static fn (): Testable => Livewire::test(ManageServices::class)->mountAction('create')->setActionData(['nom' => 'Nouveau service', 'actif' => true]), $fenetreDeLaPage, $aucun(Service::class, 'Nouveau service')],
            'créer un client depuis la caisse' => [static fn (): Testable => Livewire::test(Caisse::class)->mountFormComponentAction('client_id', 'createOption')->setFormComponentActionData($nouveauClient), $fenetreDuChamp, $aucun(Client::class, 'Nouveau client')],
            'créer un client depuis une facture' => [static fn (): Testable => Livewire::test(CreateFacture::class)->mountFormComponentAction('client_id', 'createOption')->setFormComponentActionData($nouveauClient), $fenetreDuChamp, $aucun(Client::class, 'Nouveau client')],
            'créer un client depuis le tableau des clients' => [static fn (): Testable => Livewire::test(SelecteurDeClients::class)->mountTableAction('nouveauClient')->setTableActionData($nouveauClient), $fenetreDeLaListe, $aucun(Client::class, 'Nouveau client')],
            'créer un service depuis une vente' => [static fn (): Testable => Livewire::test(CreateFacture::class)->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')->setFormComponentActionData(['nouveau_service' => 'Nouveau service']), $fenetreDuChamp, $aucun(Service::class, 'Nouveau service')],
            'régler les factures' => [static fn (): Testable => Livewire::test(ParametresFactures::class)->fillForm(['delai_paiement_jours' => '45']), $enregistrer, static fn () => self::assertSame(30, $magasin()->delai_paiement_jours)],
            'régler les coordonnées' => [static fn (): Testable => Livewire::test(CoordonneesMagasin::class)->fillForm(['telephone' => '04 72 00 00 00']), $enregistrer, static fn () => self::assertNull($magasin()->telephone)],
            'régler les prix de vente' => [static fn (): Testable => Livewire::test(ParametresPrixDeVente::class)->fillForm(['actif' => true]), $enregistrer, static fn () => self::assertFalse($magasin()->prix_vente_automatique)],
            'régler les relances' => [static fn (): Testable => Livewire::test(ParametresRelances::class)->fillForm(['actif' => true]), $enregistrer, static fn () => self::assertFalse($magasin()->relances_automatiques)],
        ];
    }

    /**
     * @param  Closure(): Testable  $ouvrir
     * @param  Closure(Testable): Testable  $enregistrer
     * @param  Closure(): void  $rienNEstEcrit
     */
    #[DataProvider('formulaires')]
    public function test_un_formulaire_enregistre_apres_la_fermeture_du_magasin_est_refuse_et_le_dit(Closure $ouvrir, Closure $enregistrer, Closure $rienNEstEcrit): void
    {
        [$magasin] = $this->dansLaBoutique();
        $ecran = $ouvrir();

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), Magasin::query()->findOrFail($magasin->id), MotifFermeture::Temporaire, 'password');
        $this->assertFalse(app(TenantContext::class)->magasin()->estFerme());

        $enregistrer($ecran)->assertNotified(self::MAGASIN_FERME);
        $rienNEstEcrit();
    }

    /**
     * @param  Closure(): Testable  $ouvrir
     * @param  Closure(Testable): Testable  $enregistrer
     * @param  Closure(): void  $rienNEstEcrit
     */
    #[DataProvider('formulaires')]
    public function test_un_formulaire_enregistre_apres_la_suspension_de_son_auteur_est_refuse_et_le_dit(Closure $ouvrir, Closure $enregistrer, Closure $rienNEstEcrit): void
    {
        [$magasin, $responsable] = $this->dansLaBoutique();
        $ecran = $ouvrir();

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), User::query()->findOrFail($responsable->id));
        $this->assertTrue(auth()->user()->estActif());

        $enregistrer($ecran)->assertNotified(LecteurDesDroits::REFUS);
        $rienNEstEcrit();
    }

    /**
     * La page envoyée par le navigateur, son adresse, ce qu'on y saisit,
     * son geste, et ce qui ne doit pas s'écrire.
     *
     * @return array<string, array{0: class-string, 1: Closure(Magasin): string, 2: array<string, string>, 3: string, 4: Closure(): void}>
     */
    public static function pagesEnvoyeesParLeNavigateur(): array
    {
        $aucun = static fn (string $classe, string $nom): Closure => static fn () => self::assertSame(0, $classe::withoutGlobalScopes()->where('nom', $nom)->count());
        $magasin = static fn (): Magasin => app(TenantContext::class)->magasin()->fresh();

        return [
            'modifier un client sur sa page' => [EditClient::class, static fn (Magasin $boutique): string => ClientResource::getUrl('edit', ['record' => Client::factory()->create(['nom' => 'Martin'])], tenant: $boutique), ['data.nom' => 'Martin et fils'], 'save', $aucun(Client::class, 'Martin et fils')],
            'modifier un fournisseur sur sa page' => [EditFournisseur::class, static fn (Magasin $boutique): string => FournisseurResource::getUrl('edit', ['record' => Fournisseur::factory()->create(['nom' => 'Grossiste'])], tenant: $boutique), ['data.nom' => 'Grossiste du Sud'], 'save', $aucun(Fournisseur::class, 'Grossiste du Sud')],
            'modifier un produit sur sa page' => [EditProduit::class, static fn (Magasin $boutique): string => ProduitResource::getUrl('edit', ['record' => Produit::factory()->create(['nom' => 'Coque'])], tenant: $boutique), ['data.nom' => 'Coque rouge'], 'save', $aucun(Produit::class, 'Coque rouge')],
            'créer un client sur sa page' => [CreateClient::class, static fn (Magasin $boutique): string => ClientResource::getUrl('create', tenant: $boutique), ['data.nom' => 'Nouveau client', 'data.type_client' => TypeClient::Particulier->value], 'create', $aucun(Client::class, 'Nouveau client')],
            'créer un fournisseur sur sa page' => [CreateFournisseur::class, static fn (Magasin $boutique): string => FournisseurResource::getUrl('create', tenant: $boutique), ['data.nom' => 'Nouveau fournisseur'], 'create', $aucun(Fournisseur::class, 'Nouveau fournisseur')],
            'régler les factures' => [ParametresFactures::class, static fn (Magasin $boutique): string => ParametresFactures::getUrl(tenant: $boutique), ['data.delai_paiement_jours' => '45'], 'save', static fn () => self::assertSame(30, $magasin()->delai_paiement_jours)],
            'régler les coordonnées' => [CoordonneesMagasin::class, static fn (Magasin $boutique): string => CoordonneesMagasin::getUrl(tenant: $boutique), ['data.telephone' => '04 72 00 00 00'], 'save', static fn () => self::assertNull($magasin()->telephone)],
            'régler les prix de vente' => [ParametresPrixDeVente::class, static fn (Magasin $boutique): string => ParametresPrixDeVente::getUrl(tenant: $boutique), ['data.actif' => true], 'save', static fn () => self::assertFalse($magasin()->prix_vente_automatique)],
            'régler les relances' => [ParametresRelances::class, static fn (Magasin $boutique): string => ParametresRelances::getUrl(tenant: $boutique), ['data.actif' => true], 'save', static fn () => self::assertFalse($magasin()->relances_automatiques)],
        ];
    }

    /**
     * Ce que reçoit le navigateur : la page, ouverte avant la fermeture du
     * magasin, s'envoie après par une vraie requête Livewire, qui relit le
     * magasin fermé et redemande le droit d'écrire de la page avant tout
     * geste. Au lieu d'une page interdite, rien ne s'exécute, et la
     * personne revient au tableau de bord du magasin, qui dit pourquoi
     * (voir ReponseDansUnMagasinEnLectureSeule).
     *
     * @param  class-string  $page
     * @param  Closure(Magasin): string  $adresse
     * @param  array<string, string>  $saisie
     * @param  Closure(): void  $rienNEstEcrit
     */
    #[DataProvider('pagesEnvoyeesParLeNavigateur')]
    public function test_un_formulaire_envoye_par_le_navigateur_apres_la_fermeture_revient_au_tableau_de_bord_qui_dit_pourquoi(string $page, Closure $adresse, array $saisie, string $geste, Closure $rienNEstEcrit): void
    {
        [$magasin] = $this->dansLaBoutique();
        $instantane = $this->instantaneLivewire($this->get($adresse($magasin))->assertOk()->getContent(), app(ComponentRegistry::class)->getName($page));

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), Magasin::query()->findOrFail($magasin->id), MotifFermeture::Temporaire, 'password');
        $reponse = $this->requeteLivewire($instantane, $saisie, $geste);

        $tableauDeBord = Filament::getPanel('app')->getUrl($magasin);
        $reponse->assertOk()->assertJsonPath('components.0.effects.redirect', $tableauDeBord);
        $this->assertSame($instantane, $reponse->json('components.0.snapshot'));
        $this->get($tableauDeBord)->assertOk()->assertSee(self::MAGASIN_FERME);
        $rienNEstEcrit();
    }

    /**
     * Le responsable de la boutique, connecté dans son panneau.
     *
     * @return array{0: Magasin, 1: User}
     */
    private function dansLaBoutique(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $this->dansLeMagasin($magasin, $responsable);

        return [$magasin, $responsable];
    }

    /**
     * Confirme le bouton ouvert : le refus de l'action se lit en
     * notification ; une action groupée dit combien de lignes cochées
     * sont restées, et ne les décoche pas.
     */
    private function confirmer(Testable $ecran, string $bouton, string $refus): void
    {
        match ($bouton) {
            'ligne' => $ecran->callMountedTableAction()->assertNotified($refus),
            'page' => $ecran->callMountedAction()->assertNotified($refus),
            'suppression en masse' => $ecran->callMountedTableBulkAction()->assertNotified('1 ligne cochée n\'a pas été supprimée')->assertNotDispatched('deselectAllTableRecords'),
            'archivage en masse' => $ecran->callMountedTableBulkAction()->assertNotified('1 ligne cochée n\'a pas été archivée')->assertNotDispatched('deselectAllTableRecords'),
        };
    }
}
