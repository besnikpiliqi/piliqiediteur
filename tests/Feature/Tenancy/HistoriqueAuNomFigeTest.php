<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AjouterPreuveOperationAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\PaiementResource\Pages\ListPaiements;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * L'historique garde le nom de chaque personne tel qu'il était au moment
 * de chaque opération (voir FigeLeNomDeSesAuteurs) : un changement de nom
 * dans le profil, un compte refusé ou repris par une autre société ne
 * change rien à ce que montrent les écrans, le contenu signé et le journal
 * d'audit.
 */
class HistoriqueAuNomFigeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque colonne qui désigne une personne dans l'historique, et la
     * colonne de son nom figé.
     *
     * @var array<string, array<string, string>>
     */
    private const NOMS_FIGES = [
        'factures' => ['cree_par' => 'cree_par_nom'],
        'avoirs' => ['cree_par' => 'cree_par_nom'],
        'paiements' => ['cree_par' => 'cree_par_nom'],
        'clotures_caisse' => ['cree_par' => 'cree_par_nom'],
        'clotures_caisse_personnelles' => ['vendeur_id' => 'vendeur_nom', 'fermee_par' => 'fermee_par_nom'],
        'factures_achat' => ['cree_par' => 'cree_par_nom'],
        'avoirs_fournisseur' => ['cree_par' => 'cree_par_nom'],
        'inventaires_ouverture' => ['cree_par' => 'cree_par_nom', 'valide_par' => 'valide_par_nom'],
        'mouvements_stock' => ['cree_par' => 'cree_par_nom'],
        'envois_documents' => ['envoye_par' => 'envoye_par_nom'],
        'preuves_operation' => ['note_par' => 'note_par_nom'],
        'bordereaux_detaxe' => ['note_par' => 'note_par_nom'],
        'consultations_vies' => ['consultee_par' => 'consultee_par_nom'],
        'periodes_activite' => ['ouverte_par' => 'ouverte_par_nom', 'fermee_par' => 'fermee_par_nom'],
        'verifications_societe' => ['auteur_id' => 'auteur_nom', 'beneficiaire_id' => 'beneficiaire_nom'],
        'pieces_verification' => ['deposee_par' => 'deposee_par_nom'],
        'codes_confirmation' => ['demande_par' => 'demande_par_nom'],
        'demandes_accord_dirigeant' => ['demande_par' => 'demande_par_nom'],
        'demandes_rattachement' => ['demande_par' => 'demande_par_nom'],
    ];

    /**
     * Les colonnes vers un compte qui ne sont pas de l'historique : des
     * droits actuels, un panier en cours, le jeton d'une demande traitée,
     * un lien interne jamais affiché,
     * ou un nom déjà porté ailleurs (le contenu signé, le libellé de
     * l'audit).
     *
     * @var list<string>
     */
    private const HORS_HISTORIQUE = [
        'users.cree_par',
        'users.provisoire_donne_par',
        'users.statut_change_par',
        'paniers_caisse.user_id',
        'actions_traitees.user_id',
        'affectations.user_id',
        'affectations.nomme_par',
        'autorisations_societe.user_id',
        'journal_audit.auteur_id',
        'enregistrements_fiscaux.operateur_id',
        'mandats_pdp.accorde_par',
        'demandes_rattachement.user_id',
    ];

    public function test_les_ecrans_de_l_historique_affichent_le_nom_tel_qu_il_etait_au_moment_de_l_operation(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $responsable = $this->nomme($this->responsable($magasin), 'Claire Martin');
        $vendeur = $this->nomme($this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes()), 'Paul Martin');
        $ticket = $this->vendre($vendeur, Client::factory()->create(['email' => 'client@exemple.fr']), []);
        $paiement = app(EnregistrerPaiementAction::class)->handle($vendeur, $ticket->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => today()->toDateString()], [['facture_id' => $ticket->id, 'montant' => '12.00']]);
        app(NoterBordereauDetaxeAction::class)->handle($vendeur, $ticket, 'BD-2026-0001');
        app(EnvoyerDocumentAction::class)->handle($vendeur, $ticket, 'client@exemple.fr', null);
        $versLaMartinique = $this->vendre($vendeur, Client::factory()->create(), ['nature_operation' => NatureOperation::LivraisonDom, 'livraison_adresse' => '1 rue Victor Hugo', 'livraison_code_postal' => '97200', 'livraison_ville' => 'Fort-de-France', 'livraison_pays' => 'FR']);
        app(AjouterPreuveOperationAction::class)->handle($vendeur, $versLaMartinique, TypePreuve::Cn23, 'CN23-0001');
        $releve = app(FermerSaCaisseAction::class)->handle($responsable, $vendeur);

        $this->changerSonNomDansSonProfil($vendeur, 'Paul Durand');
        $this->changerSonNomDansSonProfil($responsable, 'Claire Dubois');

        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);
        Livewire::test(ListFactures::class)
            ->assertTableColumnStateSet('cree_par_nom', 'Paul Martin', $ticket)
            ->assertTableColumnStateSet('cree_par_nom', 'Paul Martin', $versLaMartinique);
        Livewire::test(ListPaiements::class)->assertTableColumnStateSet('cree_par_nom', 'Paul Martin', $paiement);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertTableColumnStateSet('vendeur_nom', 'Paul Martin', $releve)
            ->assertTableColumnStateSet('mode_fermeture', 'Fermée par Claire Martin', $releve);
        $this->get(FactureResource::getUrl('view', ['record' => $ticket]))
            ->assertOk()
            ->assertSeeText('par Paul Martin')
            ->assertSeeText('Demandé par')
            ->assertDontSeeText('Paul Durand');
        $this->get(FactureResource::getUrl('view', ['record' => $versLaMartinique]))
            ->assertOk()
            ->assertSeeText('par Paul Martin')
            ->assertDontSeeText('Paul Durand');
        $this->get(FactureResource::getUrl('index'))->assertDontSeeText('Paul Durand');
    }

    /**
     * Le vendeur n'avait jamais confirmé son adresse : il refuse son compte
     * après avoir vendu, puis, repris ailleurs, change de nom. L'historique
     * de la société, son contenu signé et son journal d'audit gardent le
     * nom de la vente.
     */
    public function test_un_compte_refuse_ou_repris_ne_change_pas_le_nom_affiche_dans_l_historique_de_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->nomme($this->membre($magasin, RoleMagasin::Vendeur), 'Paul Martin');
        $ticket = $this->vendre($vendeur, Client::factory()->create(), []);
        app(RefuserSonCompteAction::class)->handle($vendeur->fresh());
        $auditAvant = $this->journalDAudit($magasin);
        $contenuAvant = $this->enregistrementDe($ticket)->contenu;

        $vendeur->forceFill(['name' => 'Paul Durand'])->save();

        $this->assertSame('Paul Martin', $ticket->fresh()->cree_par_nom);
        $this->assertSame($contenuAvant, $this->enregistrementDe($ticket)->contenu);
        $this->assertSame(['id' => $vendeur->id, 'nom' => 'Paul Martin'], $this->enregistrementDe($ticket)->contenuSigne()['operateur']);
        $this->assertSame($auditAvant, $this->journalDAudit($magasin));
        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);
        Livewire::test(ListFactures::class)->assertTableColumnStateSet('cree_par_nom', 'Paul Martin', $ticket);
    }

    /**
     * Chaque table d'historique a, pour chaque colonne qui désigne une
     * personne, la colonne de son nom figé, nullable comme elle, et son
     * modèle les déclare ; aucune autre colonne vers un compte n'échappe à
     * la liste.
     */
    public function test_chaque_colonne_qui_designe_une_personne_dans_l_historique_a_son_nom_fige(): void
    {
        $modeles = $this->modelesParTable();

        foreach (self::NOMS_FIGES as $table => $couples) {
            $colonnes = collect(Schema::getColumns($table))->keyBy('name');

            foreach ($couples as $colonne => $colonneDuNom) {
                $this->assertTrue($colonnes->has($colonneDuNom), "{$table}.{$colonneDuNom} manque.");
                $this->assertSame($colonnes[$colonne]['nullable'], $colonnes[$colonneDuNom]['nullable'], "{$table}.{$colonneDuNom} n'a pas la nullabilité de {$colonne}.");
            }

            $this->assertArrayHasKey($table, $modeles, "Aucun modèle pour {$table}.");
            $this->assertContains(FigeLeNomDeSesAuteurs::class, class_uses_recursive($modeles[$table]), "{$modeles[$table]} ne fige pas les noms.");
            $declarees = (new ReflectionMethod($modeles[$table], 'colonnesAuNomFige'))->invoke(null);
            $this->assertSame($couples, $declarees, "{$modeles[$table]} ne déclare pas les couples attendus.");
        }

        $versUnCompte = collect(Schema::getTableListing(schemaQualified: false))
            ->flatMap(fn (string $table): array => collect(Schema::getForeignKeys($table))
                ->filter(fn (array $cle): bool => $cle['foreign_table'] === 'users')
                ->map(fn (array $cle): string => $table.'.'.Arr::last($cle['columns']))
                ->all())
            ->sort()
            ->values()
            ->all();
        $listees = collect(self::NOMS_FIGES)
            ->flatMap(fn (array $couples, string $table): array => array_map(fn (string $colonne): string => "{$table}.{$colonne}", array_keys($couples)))
            ->merge(self::HORS_HISTORIQUE)
            ->sort()
            ->values()
            ->all();
        $this->assertSame($listees, $versUnCompte);
    }

    /**
     * Le nom figé ne se modifie ni seul ni par une modification de la
     * ligne : seul un changement de la personne désignée le repose, depuis
     * la base.
     */
    public function test_le_nom_fige_ne_se_modifie_jamais_apres_coup(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->nomme($this->membre($magasin, RoleMagasin::Vendeur), 'Paul Martin');
        $proprietaire = $this->nomme($this->proprietaireDe($magasin), 'Alice Martin');
        $brouillon = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes());

        $this->assertThrows(fn () => $brouillon->forceFill(['cree_par_nom' => 'Quelqu\'un d\'autre'])->save(), LogicException::class, 'Le nom figé d\'une personne dans l\'historique ne se modifie jamais.');
        $this->assertThrows(fn () => $brouillon->fresh()->forceFill(['notes' => 'Note', 'cree_par_nom' => 'Paul Durand'])->save(), LogicException::class, 'Le nom figé d\'une personne dans l\'historique ne se modifie jamais.');
        $this->assertSame('Paul Martin', $brouillon->fresh()->cree_par_nom);

        $brouillon->fresh()->forceFill(['cree_par' => $proprietaire->id, 'cree_par_nom' => 'Nom inventé'])->save();
        $this->assertSame('Alice Martin', $brouillon->fresh()->cree_par_nom);

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle($proprietaire, $brouillon->fresh())->forceFill(['cree_par_nom' => 'Paul Durand'])->save(), LogicException::class);
        $this->assertSame('Alice Martin', Facture::query()->sole()->cree_par_nom);
    }

    private function nomme(User $compte, string $nom): User
    {
        $compte->forceFill(['name' => $nom])->save();

        return $compte;
    }

    /**
     * Le compte change son nom, et lui seul, dans son profil, en donnant
     * son mot de passe.
     */
    private function changerSonNomDansSonProfil(User $compte, string $nom): void
    {
        $this->actingAs($compte);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['name' => $nom, 'email' => $compte->email, 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($nom, $compte->fresh()->name);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function vendre(User $vendeur, Client $client, array $attributs): Facture
    {
        return app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $client, $this->lignes(), $attributs));
    }

    /**
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    private function enregistrementDe(Facture $facture): EnregistrementFiscal
    {
        return EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->where('document_type', $facture->getMorphClass())->where('document_id', $facture->id)->sole();
    }

    /**
     * Les entrées du journal d'audit de la société du magasin, telles
     * qu'elles sont en base.
     *
     * @return list<array<string, mixed>>
     */
    private function journalDAudit(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAsSociete($magasin->societe, fn (): array => EntreeAudit::query()->orderBy('id')->get()->map(fn (EntreeAudit $entree): array => $entree->getAttributes())->all());
    }

    /**
     * Le modèle de chaque table, lu dans app/.
     *
     * @return array<string, class-string<Model>>
     */
    private function modelesParTable(): array
    {
        return collect(File::allFiles(app_path()))
            ->map(fn (SplFileInfo $fichier): string => 'App\\'.Str::of($fichier->getRelativePathname())->beforeLast('.php')->replace('/', '\\'))
            ->filter(fn (string $classe): bool => class_exists($classe) && is_subclass_of($classe, Model::class) && ! (new ReflectionClass($classe))->isAbstract())
            ->mapWithKeys(fn (string $classe): array => [(new $classe)->getTable() => $classe])
            ->all();
    }
}
