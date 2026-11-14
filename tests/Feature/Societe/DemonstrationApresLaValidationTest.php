<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Jobs\PreparerLeTelechargementDesDocumentsJob;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\TelechargerLesDocuments as TelechargerLesDocumentsDuMagasin;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Pages\TelechargerLesDocuments as TelechargerLesDocumentsDeLaSociete;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Http\Controllers\TelechargerLesDocumentsController;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * T115 (lot DEMO-V, décision de Claude, D76) : une fois la société validée,
 * la démonstration disparaît de tous les écrans de la société et de ses
 * magasins (section du tableau de bord, étape de la liste, changement de
 * magasin, liste des magasins, téléchargement par période, « Vos
 * téléchargements » pour les demandes qui ne portent que sur elle, cloche)
 * et plus personne n'y entre : son adresse mène à une page introuvable.
 * Ses données restent en base, sans rien effacer (registre, journal des
 * événements, JET 100 et 105), pour la caisse sécurisée et l'attestation.
 */
class DemonstrationApresLaValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_fois_la_societe_validee_la_demonstration_disparait_de_ses_ecrans_sans_rien_effacer(): void
    {
        [$societe, $proprietaire, $reel, $demonstration, $ticket] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $administrateur = $this->administrateur($reel, nom: 'Alice Martin');
        $this->assertTrue($proprietaire->peutOuvrir($demonstration));
        $this->assertContains($demonstration->id, $administrateur->magasinsAccessibles()->modelKeys());

        $this->saisirLeCodeDuCourrier($societe->fresh());

        $this->ouvrir($proprietaire, TableauDeBord::getUrl(panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSeeText($reel->nom)
            ->assertDontSeeText(['Démonstration', 'démonstration', OuvrirLaDemonstrationAction::NOM])
            ->assertDontSee($demonstration->uuid);
        $this->ouvrir($proprietaire, MagasinResource::getUrl('index', panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSeeText($reel->nom)
            ->assertDontSeeText(OuvrirLaDemonstrationAction::NOM);

        foreach ([$proprietaire->fresh(), $administrateur->fresh()] as $compte) {
            $this->assertFalse($compte->peutOuvrir($demonstration->fresh()));
            $this->assertSame([$reel->id], $compte->getTenants(Filament::getPanel('app'))->modelKeys());
            $this->ouvrir($compte, Dashboard::getUrl(panel: 'app', tenant: $demonstration))->assertNotFound();
            $this->ouvrir($compte, FactureResource::getUrl('view', ['record' => $ticket], panel: 'app', tenant: $demonstration))->assertNotFound();
        }

        $this->assertNotNull(Magasin::query()->find($demonstration->id));
        $this->assertNotNull(Facture::withoutGlobalScope(MagasinScope::class)->find($ticket->id));
        $this->assertSame(
            [CodeEvenement::EntreeEnModeDemonstration->value, CodeEvenement::SortieDuModeDemonstration->value],
            array_values(array_intersect([CodeEvenement::EntreeEnModeDemonstration->value, CodeEvenement::SortieDuModeDemonstration->value], $this->codesDuJournal($demonstration))),
        );
    }

    /**
     * Avant la validation, le propriétaire télécharge les documents de sa
     * démonstration, seul magasin qui en a ; une fois la société validée,
     * la page de la société ne propose que ses magasins réels, sa demande
     * sur la démonstration sort de « Vos téléchargements » et son lien ne
     * la sert plus. La demande reste en base jusqu'à son effacement
     * planifié.
     */
    public function test_les_telechargements_de_la_demonstration_disparaissent_une_fois_la_societe_validee(): void
    {
        [$societe, $proprietaire, $reel, $demonstration] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $this->dansLeMagasin($demonstration, $proprietaire);
        Livewire::test(TelechargerLesDocumentsDuMagasin::class)
            ->set('types', [TypeDeDocumentTelechargeable::Ventes->value])
            ->set('format', FormatDuTelechargement::Csv->value)
            ->call('toutSelectionner')
            ->call('telecharger');
        $demande = TelechargementDeDocuments::query()->withoutGlobalScopes()->where('demande_par', $proprietaire->id)->sole();
        $this->assertSame([$demonstration->id], app(DocumentsDeLaPeriode::class)->magasinsDeLaSociete($proprietaire, $societe->fresh())->map(fn (Magasin $magasin): int => $magasin->id)->all());
        $this->assertSame([$demande->id], TelechargementDeDocuments::recentsDe($proprietaire, $societe->fresh())->pluck('id')->all());

        $this->saisirLeCodeDuCourrier($societe->fresh());

        $this->assertSame([$reel->id], app(DocumentsDeLaPeriode::class)->magasinsDeLaSociete($proprietaire->fresh(), $societe->fresh())->map(fn (Magasin $magasin): int => $magasin->id)->all());
        $this->assertSame([], TelechargementDeDocuments::recentsDe($proprietaire->fresh(), $societe->fresh())->pluck('id')->all());
        $this->ouvrir($proprietaire, TelechargerLesDocumentsDeLaSociete::getUrl(panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSeeText($reel->nom)
            ->assertDontSeeText(OuvrirLaDemonstrationAction::NOM)
            ->assertDontSee($demande->lien());
        $this->ouvrir($proprietaire, $demande->lien())->assertForbidden()->assertSeeText(TelechargerLesDocumentsController::SANS_DROIT);
        $this->assertNotNull(TelechargementDeDocuments::query()->withoutGlobalScopes()->find($demande->id));
    }

    /**
     * Une demande sur la démonstration encore en file à la validation (le
     * travailleur des téléchargements ne tournait pas) se clôt, quand il
     * passe, sans rien annoncer : aucune notification sur la démonstration
     * n'arrive dans la cloche après la validation, et aucun fichier ne
     * s'écrit.
     */
    public function test_une_demande_de_la_demonstration_preparee_apres_la_validation_se_clot_sans_rien_annoncer(): void
    {
        Queue::fake();
        Storage::fake(PreparerLeTelechargementDesDocumentsJob::DISQUE);
        [$societe, $proprietaire, , $demonstration] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $this->dansLeMagasin($demonstration, $proprietaire);
        Livewire::test(TelechargerLesDocumentsDuMagasin::class)
            ->set('types', [TypeDeDocumentTelechargeable::Ventes->value])
            ->set('format', FormatDuTelechargement::Csv->value)
            ->call('toutSelectionner')
            ->call('telecharger');
        $demande = TelechargementDeDocuments::query()->withoutGlobalScopes()->where('demande_par', $proprietaire->id)->sole();
        $this->assertSame(StatutDuTelechargement::EnPreparation, $demande->statut);
        Queue::assertPushed(PreparerLeTelechargementDesDocumentsJob::class);

        $this->saisirLeCodeDuCourrier($societe->fresh());
        app(TenantContext::class)->clear();
        app()->call([new PreparerLeTelechargementDesDocumentsJob($demande->id), 'handle']);

        $demande->refresh();
        $this->assertSame([StatutDuTelechargement::Echec, PreparerLeTelechargementDesDocumentsJob::MOTIF_DEMONSTRATION, null], [$demande->statut, $demande->motif_echec, $demande->chemin]);
        $this->assertSame(0, DatabaseNotification::query()->where('data->telechargement_id', $demande->id)->count());
        $this->assertSame(0, DatabaseNotification::query()->where('data->magasin_id', $demonstration->id)->count());
        $this->assertSame([], Storage::disk(PreparerLeTelechargementDesDocumentsJob::DISQUE)->allFiles());
    }

    /**
     * Les notifications à propos de la démonstration s'effacent des cloches
     * de la société à sa validation ; celles d'un magasin réel restent.
     */
    public function test_les_notifications_de_la_demonstration_s_effacent_a_la_validation(): void
    {
        [$societe, $proprietaire, $reel, $demonstration] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $proprietaire->notify(new FacturesPasseesEnRetard($demonstration, ['TK-DEMO-2026-000001']));
        $proprietaire->notify(new FacturesPasseesEnRetard($reel, ['FAC-LYON1-2026-000001']));
        $this->assertSame(2, $proprietaire->notifications()->count());

        $this->saisirLeCodeDuCourrier($societe->fresh());

        $this->assertSame([$reel->id], $proprietaire->notifications()->get()->map(fn ($notification): mixed => $notification->data['magasin_id'] ?? null)->filter()->values()->all());
    }

    /**
     * Un cabinet lié, à qui le client accorde le téléchargement des
     * documents par période, ne voit jamais sa démonstration, ni dans ses
     * magasins ni dans son téléchargement.
     */
    public function test_le_cabinet_lie_ne_voit_jamais_la_demonstration_de_son_client(): void
    {
        [$societe, , $reel, $demonstration] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $this->saisirLeCodeDuCourrier($societe->fresh());
        $collaborateur = $this->collaborateurDuCabinetLie($reel->fresh(), [...PermissionRegistry::parDefautDuCabinet($societe->type_activite), PermissionRegistry::DOCUMENTS_TELECHARGER]);

        $this->assertFalse($collaborateur->peutOuvrir($demonstration->fresh()));
        $this->assertSame([$reel->id], app(DocumentsDeLaPeriode::class)->magasinsDesClients($collaborateur)->flatten()->map(fn (Magasin $magasin): int => $magasin->id)->all());
    }

    /**
     * L'équipe de la société ne nomme plus la démonstration : le compte
     * d'entraînement retiré à la validation reste listé, sans magasin
     * (« — »), l'administrateur qui l'avait dans sa sélection n'y montre
     * que ses magasins réels, et la sélection d'un administrateur, à sa
     * création comme à sa modification, ne propose plus de la cocher.
     */
    public function test_l_equipe_de_la_societe_ne_nomme_plus_la_demonstration_une_fois_la_societe_validee(): void
    {
        [$societe, $proprietaire, $reel, $demonstration] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $entrainement = $this->responsableDeTest($demonstration);
        $administrateur = $this->administrateur($reel, [$reel->id, $demonstration->id], nom: 'Alice Martin');

        $this->saisirLeCodeDuCourrier($societe->fresh());

        $this->assertTrue($entrainement->fresh()?->estRetire());
        $this->ouvrir($proprietaire, MembreResource::getUrl('index', panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSeeText([$entrainement->name, 'Alice Martin', 'Boutique Lyon 1 (Administrateur)'])
            ->assertDontSeeText('Démonstration');

        foreach ([$entrainement, $administrateur] as $compte) {
            $this->ouvrir($proprietaire, MembreResource::getUrl('view', ['record' => $compte], panel: 'societe', tenant: $societe))
                ->assertOk()
                ->assertSeeText($compte->name)
                ->assertDontSeeText('Démonstration');
        }

        $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire->fresh());
        $this->assertSame([$reel->id => 'Boutique Lyon 1'], MembreResource::magasinsDeLaPortee());
        Livewire::test(ListMembres::class)
            ->mountAction('creerUnAdministrateur')
            ->setActionData(['portee' => 'selection'])
            ->assertSee('Boutique Lyon 1')
            ->assertDontSee('Démonstration');
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->mountAction('modifierLAdministrateur')
            ->assertActionDataSet(['portee' => 'selection', 'magasins' => [$reel->id]])
            ->assertSee('Boutique Lyon 1')
            ->assertDontSee('Démonstration');
    }

    /**
     * Avant la validation, la démonstration se choisit dans la portée d'un
     * administrateur ; une fois la société validée, l'action la refuse,
     * même forgée, sans rien créer.
     */
    public function test_la_demonstration_d_une_societe_validee_ne_se_choisit_plus_dans_la_portee_d_un_administrateur(): void
    {
        [$societe, $proprietaire, $reel, $demonstration] = $this->societeQuiSEstEntraineeDansSaDemonstration();
        $avant = app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Alice Martin'], [$demonstration->id], [], []);
        $this->assertSame([$demonstration->id], $avant->affectations()->pluck('magasin_id')->all());

        $this->saisirLeCodeDuCourrier($societe->fresh());

        foreach ([[$demonstration->id], [$reel->id, $demonstration->id]] as $selection) {
            try {
                app(CreerAdministrateurAction::class)->handle($proprietaire->fresh(), $societe->fresh(), ['name' => 'Bruno Petit'], $selection, [], []);
                $this->fail('La démonstration d\'une société validée est entrée dans la portée d\'un administrateur.');
            } catch (LogicException $refus) {
                $this->assertSame(PorteeDesAdministrateurs::MESSAGE_DEMONSTRATION_TERMINEE, $refus->getMessage());
            }
        }

        $this->assertFalse(User::query()->where('name', 'Bruno Petit')->exists());
    }

    /**
     * Une société inscrite, son magasin réel au siège, et sa démonstration,
     * où un compte d'entraînement a vendu un carnet au comptoir.
     *
     * @return array{Societe, User, Magasin, Magasin, Facture}
     */
    private function societeQuiSEstEntraineeDansSaDemonstration(): array
    {
        $this->travelTo(self::aParis('2026-11-03 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $reel = $this->creerLeMagasinDuSiege($societe->fresh(), 'Boutique Lyon 1');
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());
        $ticket = app(TenantContext::class)->runAs($demonstration, fn (): Facture => self::vendreAuComptant(
            $this->responsableDeTest($demonstration),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]],
            ModePaiement::Especes,
        ));
        app(TenantContext::class)->clear();

        return [$societe->fresh(), $proprietaire->fresh(), $reel->fresh(), $demonstration->fresh(), $ticket];
    }

    /**
     * Une requête de ce compte, dans une session neuve, comme depuis son
     * propre navigateur.
     */
    private function ouvrir(User $compte, string $adresse): TestResponse
    {
        $this->flushSession();
        app(TenantContext::class)->clear();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($compte->fresh())->get($adresse);
    }

    /**
     * Les codes du journal des événements du magasin, dans l'ordre.
     *
     * @return list<int|string>
     */
    private function codesDuJournal(Magasin $magasin): array
    {
        return EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)
            ->where('magasin_id', $magasin->id)
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->orderBy('sequence')
            ->pluck('type')
            ->all();
    }
}
