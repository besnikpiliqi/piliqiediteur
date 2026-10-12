<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\CorrigerSirenAvantValidationAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\EvaluateurDeVerification;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * Isolation des sociétés (BelongsToSociete + SocieteScope, TenantContext)
 * et, dans une société, de ses magasins : aucune société ne lit, n'ouvre
 * ni ne règle rien d'une autre, même du même SIREN ; deux magasins d'une
 * même société ne partagent que l'identité et les réglages de leur
 * société.
 */
class IsolationDesSocietesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les modèles de données propres à une société.
     *
     * @var list<class-string<Model>>
     */
    private const MODELES_DE_SOCIETE = [
        CodeConfirmation::class,
        DemandeAccordDirigeant::class,
        EntreeAudit::class,
        PieceVerification::class,
        TraceAudit::class,
        VerificationSociete::class,
    ];

    public function test_chaque_modele_de_societe_est_liste_ici(): void
    {
        $modeles = collect(File::allFiles(app_path()))
            ->map(fn (SplFileInfo $fichier): string => 'App\\'.Str::of($fichier->getRelativePathname())->beforeLast('.php')->replace('/', '\\'))
            ->filter(fn (string $classe): bool => class_exists($classe) && in_array(BelongsToSociete::class, class_uses_recursive($classe), true))
            ->sort()
            ->values()
            ->all();
        $listes = self::MODELES_DE_SOCIETE;
        sort($listes);

        $this->assertSame($modeles, $listes);
    }

    /**
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function modelesDeSociete(): array
    {
        return collect(self::MODELES_DE_SOCIETE)->mapWithKeys(fn (string $modele): array => [class_basename($modele) => [$modele]])->all();
    }

    /**
     * Sans société active, toute lecture d'un modèle de société échoue au
     * lieu de tout renvoyer.
     *
     * @param  class-string<Model>  $modele
     */
    #[DataProvider('modelesDeSociete')]
    public function test_societe_scope_echoue_ferme_sans_contexte(string $modele): void
    {
        app(TenantContext::class)->clear();

        $this->assertThrows(fn () => $modele::query()->get(), TenantContextMissingException::class);
    }

    public function test_une_societe_ne_lit_ni_le_journal_d_audit_ni_les_verifications_ni_les_demandes_d_une_autre(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $entreeDeB = $this->inscrireParUneRequete($magasinB->societe);

        [$entrees, $verifications, $traces, $parIdentifiant] = app(TenantContext::class)->runAsSociete($magasinA->societe, fn (): array => [
            EntreeAudit::query()->pluck('societe_id')->unique()->values()->all(),
            VerificationSociete::query()->pluck('societe_id')->unique()->values()->all(),
            TraceAudit::query()->count(),
            EntreeAudit::query()->find($entreeDeB->id),
        ]);

        $this->assertSame([[$magasinA->societe_id], [$magasinA->societe_id], 0, null], [$entrees, $verifications, $traces, $parIdentifiant]);
        $this->assertTrue(app(TenantContext::class)->runAsSociete($magasinB->societe, fn (): bool => TraceAudit::query()->whereKey($entreeDeB->id)->exists()));
    }

    /**
     * L'adresse de l'administration d'une autre société répond 404, sans
     * révéler qu'elle existe ; celle de sa propre société s'ouvre.
     */
    public function test_l_espace_d_une_autre_societe_repond_404(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $this->actingAs($this->proprietaireDe($magasinA));

        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasinA->societe))->assertOk();
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasinB->societe))->assertNotFound();
        $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $magasinB->societe))->assertNotFound();
    }

    /**
     * Le propriétaire d'une société a tous les droits dans ses magasins, et
     * aucun dans ceux d'une autre : l'adresse répond 404.
     */
    public function test_le_proprietaire_d_une_societe_n_ouvre_aucun_magasin_d_une_autre(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $proprietaireA = $this->proprietaireDe($magasinA);

        $this->actingAs($proprietaireA)->get("/app/{$magasinB->uuid}")->assertNotFound();

        $this->assertFalse($proprietaireA->canAccessTenant($magasinB));
        $this->assertFalse(app(TenantContext::class)->runAs($magasinB, fn (): bool => $proprietaireA->can(PermissionRegistry::CLIENT_VIEW)));
        $this->assertTrue(app(TenantContext::class)->runAs($magasinA, fn (): bool => $proprietaireA->can(PermissionRegistry::CLIENT_VIEW)));
    }

    /**
     * Les clés étrangères composites (societe_id, magasin_id) : la base
     * refuse d'inscrire au journal d'audit, au registre des vérifications
     * ou au registre des SIRET une ligne d'une société qui désigne le
     * magasin d'une autre.
     */
    public function test_la_base_refuse_de_rattacher_une_ligne_de_societe_au_magasin_d_une_autre_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $maintenant = now()->toDateTimeString();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('verifications_societe')->insert(['societe_id' => $magasinA->societe_id, 'magasin_id' => $magasinB->id, 'type' => 'sirene_etablissement', 'resultat' => 'concluante', 'created_at' => $maintenant])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('sirets_magasins')->insert(['societe_id' => $magasinA->societe_id, 'magasin_id' => $magasinB->id, 'siret' => IdentifiantsInsee::avecSaCle($magasinA->societe->siren.'0009'), 'reserve_le' => $maintenant, 'created_at' => $maintenant, 'updated_at' => $maintenant])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('journal_audit')->insert(['societe_id' => $magasinA->societe_id, 'magasin_id' => $magasinB->id, 'categorie' => 'operations', 'sequence' => 99, 'origine' => 'systeme', 'action' => 'magasin_modifie', 'cle_id' => 'tests', 'empreinte' => str_repeat('0', 64), 'created_at' => $maintenant])), QueryException::class);
        $this->assertThrows(
            fn () => app(TenantContext::class)->runAsSociete($magasinA->societe, fn () => DB::transaction(fn () => app(JournalAudit::class)->inscrire(ActionAuditee::MagasinModifie, null, $magasinB, null, ['nom' => 'B']))),
            LogicException::class,
            'Une entrée du journal d\'audit ne s\'inscrit que dans la chaîne d\'un magasin de la société active.',
        );
    }

    /**
     * Les clés étrangères composites de l'équipe d'un magasin : la base
     * refuse d'affecter un compte au magasin d'une autre société que la
     * sienne, quelle que soit la société écrite sur la ligne ; dans sa
     * propre société, la même ligne s'écrit.
     */
    public function test_la_base_refuse_d_affecter_un_compte_au_magasin_d_une_autre_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $autreMagasinA = $this->ouvrirUnAutreMagasin($magasinA, ['nom' => 'A bis']);
        $vendeurDeA = $this->membre($magasinA, RoleMagasin::Vendeur);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $ligne = fn (Magasin $magasin, int $societeId): array => ['societe_id' => $societeId, 'magasin_id' => $magasin->id, 'user_id' => $vendeurDeA->id, 'role' => RoleMagasin::Vendeur->value, 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert($ligne($magasinB, $magasinA->societe_id))), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert($ligne($magasinB, $magasinB->societe_id))), QueryException::class);

        $this->assertSame(0, DB::table('affectations')->where('magasin_id', $magasinB->id)->count());
        $this->assertTrue(DB::table('affectations')->insert($ligne($autreMagasinA, $magasinA->societe_id)));
    }

    /**
     * Un magasin d'une autre société glissé dans une action de la société
     * est refusé : il ne se modifie pas, et son NIC ne s'y corrige pas.
     */
    public function test_un_identifiant_de_magasin_d_une_autre_societe_force_dans_un_formulaire_est_refuse(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A', 'a_verifier' => true]);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B', 'a_verifier' => true]);
        $proprietaireA = $this->proprietaireDe($magasinA);
        $siretDeB = $magasinB->siret;
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(TenantContext::class)->runAsSociete($magasinA->societe, fn () => app(ModifierEtablissementAction::class)->handle($proprietaireA, $magasinB, ['nom' => 'Pris'])),
            LogicException::class,
        );
        $this->assertThrows(
            fn () => app(CorrigerSirenAvantValidationAction::class)->handle($proprietaireA, $magasinA->societe, '200000008', [$magasinB->id => '00017']),
            fn (ValidationException $refus): bool => array_keys($refus->errors()) === ["nics.{$magasinA->id}"],
        );

        $this->assertSame(['B', $siretDeB], [$magasinB->fresh()->nom, $magasinB->fresh()->siret]);
    }

    /**
     * Les gestes sur une société ne valent que pour la sienne : son
     * propriétaire ne voit ni ne règle une autre société, et rien ne lui
     * est permis dans son administration.
     */
    public function test_les_autorisations_de_societe_ne_valent_jamais_hors_de_sa_societe(): void
    {
        $proprietaireA = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'A']));
        $societeB = $this->ouvrirUneSociete(['nom' => 'B'])->societe;
        app(TenantContext::class)->clear();

        foreach (['view', 'modifierLesParametres', 'gererLesMagasins', 'corrigerLeSiren'] as $geste) {
            $this->assertFalse(Gate::forUser($proprietaireA)->allows($geste, $societeB), $geste);
            $this->assertFalse(app(TenantContext::class)->runAsSociete($societeB, fn (): bool => Gate::forUser($proprietaireA)->allows($geste, $societeB)), $geste);
        }
        $this->assertFalse($proprietaireA->canAccessTenant($societeB));
    }

    /**
     * Une société posée pour elle-même ne laisse jamais ouvrir le magasin
     * d'une autre : rien ne s'écrit dans un autre périmètre.
     */
    public function test_run_as_d_un_magasin_d_une_autre_societe_est_refuse_dans_un_contexte_de_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $contexte = app(TenantContext::class);
        $contexte->clear();

        $this->assertThrows(
            fn () => $contexte->runAsSociete($magasinA->societe, fn () => $contexte->runAs($magasinB, fn () => Client::factory()->create())),
            LogicException::class,
            'Le magasin d\'une autre société ne s\'ouvre pas dans le contexte de cette société.',
        );
        $contexte->poserSociete($magasinA->societe);
        $this->assertThrows(fn () => $contexte->set($magasinB), LogicException::class);

        $this->assertSame(1, Client::query()->withoutGlobalScopes()->where('magasin_id', $magasinB->id)->count());
    }

    /**
     * runAs() et runAsSociete() rendent, à la fin, le magasin et la société
     * posés avant eux, même après une erreur.
     */
    public function test_run_as_restaure_le_magasin_et_la_societe_poses_avant(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $autreDeA = $this->ouvrirUnAutreMagasin($magasinA, ['nom' => 'A2']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $contexte = app(TenantContext::class);
        $contexte->set($magasinA);

        $contexte->runAs($autreDeA, fn () => null);
        $this->assertSame([$magasinA->id, $magasinA->societe_id], [$contexte->id(), $contexte->societe()->id]);
        $contexte->runAsSociete($magasinB->societe, fn () => $this->assertFalse($contexte->has()));
        $this->assertSame([$magasinA->id, $magasinA->societe_id], [$contexte->id(), $contexte->societe()->id]);
        $this->assertThrows(fn () => $contexte->runAs($magasinB, fn () => throw new LogicException('panne')), LogicException::class, 'panne');
        $this->assertSame([$magasinA->id, $magasinA->societe_id], [$contexte->id(), $contexte->societe()->id]);

        $contexte->clear();
        $contexte->runAsSociete($magasinA->societe, fn () => $contexte->runAs($autreDeA, fn () => null));
        $this->assertSame([false, false], [$contexte->has(), $contexte->aUneSociete()]);
    }

    /**
     * La première vente qui verrouille le mois de fin d'exercice d'une
     * société est celle de l'un de ses magasins réels, jamais d'une autre
     * société ni de son magasin de démonstration.
     */
    public function test_la_lecture_de_la_premiere_vente_ne_compte_que_les_magasins_reels_de_la_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $autreDeA = $this->ouvrirUnAutreMagasin($magasinA, ['nom' => 'A2']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $this->vendre($magasinB);

        $this->assertSame([false, true], [$magasinA->societe->aVenduUneFois(), $magasinB->societe->aVenduUneFois()]);

        $this->vendre($autreDeA);
        $this->assertTrue($magasinA->societe->aVenduUneFois());

        $enVerification = $this->inscrireUneSociete();
        $this->actingAs($enVerification->proprietaire);
        $this->vendre(app(OuvrirLaDemonstrationAction::class)->handle($enVerification->proprietaire, $enVerification));
        $this->assertFalse($enVerification->fresh()->aVenduUneFois());
    }

    /**
     * L'évaluation de la vérification d'une société ne lit, des autres
     * sociétés du même SIREN, que trois réponses par oui ou non (une autre
     * est-elle validée, a-t-elle prouvé sa qualité, une société de ce SIREN
     * a-t-elle été révoquée ?) : chaque requête qui porte sur ce SIREN est
     * un « exists », et toute autre ne lit que la société évaluée.
     */
    public function test_l_evaluation_ne_lit_d_une_autre_societe_du_meme_siren_que_des_booleens(): void
    {
        $validee = $this->inscrireUneSociete();
        $this->verifierLIdentiteDuProprietaire($validee);
        $societe = $this->inscrireUneSociete($validee->siren);
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete;
        });

        $etat = app(EvaluateurDeVerification::class)->evaluer($societe);

        $surLeSiren = array_filter($requetes, fn (QueryExecuted $requete): bool => in_array($societe->siren, $requete->bindings, true));
        $this->assertCount(3, $surLeSiren);
        foreach ($requetes as $requete) {
            in_array($requete, $surLeSiren, true)
                ? $this->assertStringStartsWith('select exists', $requete->sql)
                : $this->assertContains($societe->id, $requete->bindings, $requete->sql);
        }
        $this->assertTrue($validee->fresh()->estValidee());
        $this->assertSame([true, false], [$etat->autreSocieteValidee, $etat->reclamationPossible()]);
    }

    /**
     * Une société qui s'inscrit avec le SIREN d'une société déjà validée
     * ne lit rien de ses réglages : son identité vient du répertoire
     * Sirene, ses réglages sont les siens.
     */
    public function test_aucun_reglage_d_une_autre_societe_n_est_lu_ni_revele(): void
    {
        $validee = Magasin::factory()->avecLaSociete(['siren' => '100000009', 'raison_sociale' => 'Raison choisie par l\'autre', 'regime_tva' => 'franchise_en_base', 'immatriculation' => 'RCS Nantes'])->create(['siret' => '10000000900017'])->societe;
        app(TenantContext::class)->clear();
        $proprietaire = User::factory()->for(Societe::factory()->state(['siren' => '100000009'])->sansIdentiteLegale())->create();

        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true, 'nic' => '00025'], $proprietaire);

        $this->assertSame([null, null, null], [$magasin->societe->raison_sociale, $magasin->societe->regime_tva, $magasin->societe->immatriculation]);
        $this->assertSame('Raison choisie par l\'autre', $validee->fresh()->raison_sociale);
        $this->assertNotSame($validee->id, $magasin->societe_id);
    }

    /**
     * Deux sociétés en attente du même SIREN restent isolées : chacune ne
     * voit que ses magasins, son journal et ses vérifications.
     */
    public function test_deux_inscriptions_en_attente_du_meme_siren_ne_voient_rien_l_une_de_l_autre(): void
    {
        $premiere = $this->ouvrirUneSociete(['nom' => 'Première', 'a_verifier' => true], User::factory()->for(Societe::factory()->state(['siren' => '100000009']))->create());
        $seconde = $this->ouvrirUneSociete(['nom' => 'Seconde', 'a_verifier' => true, 'nic' => '00025'], User::factory()->for(Societe::factory()->state(['siren' => '100000009']))->create());
        app(TenantContext::class)->clear();

        $this->assertSame([[$premiere->id], [$seconde->id]], [$premiere->societe->magasins()->pluck('id')->all(), $seconde->societe->magasins()->pluck('id')->all()]);
        $this->assertSame(
            [$premiere->societe_id],
            app(TenantContext::class)->runAsSociete($premiere->societe, fn (): array => [...EntreeAudit::query()->pluck('societe_id')->unique()->values()->all()]),
        );
        $this->actingAs($this->proprietaireDe($premiere))
            ->get(TableauDeBord::getUrl(panel: 'societe', tenant: $premiere->societe))
            ->assertSee('Première')
            ->assertDontSee('Seconde');
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $seconde->societe))->assertNotFound();
    }

    /**
     * Dans une même société, ce qu'un magasin crée (client, produit, vente,
     * mouvement de stock) reste invisible dans l'autre.
     */
    public function test_les_clients_produits_documents_et_stock_d_un_magasin_restent_invisibles_dans_l_autre(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $contexte = app(TenantContext::class);
        $contexte->set($lyon);
        $client = Client::factory()->create(['nom' => 'Client de Lyon']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 5]);
        $vente = app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);

        $vuDeVilleurbanne = $contexte->runAs($villeurbanne, fn (): array => [
            Client::query()->where('nom', 'Client de Lyon')->exists(),
            Produit::query()->find($produit->id),
            Facture::query()->find($vente->id),
            MouvementStock::query()->where('produit_id', $produit->id)->exists(),
        ]);

        $this->assertSame([false, null, null, false], $vuDeVilleurbanne);
        $this->assertTrue($contexte->runAs($lyon, fn (): bool => MouvementStock::query()->where('produit_id', $produit->id)->exists()));
    }

    /**
     * Une vente de Lyon, ouverte à l'adresse du magasin de Villeurbanne,
     * répond 404 à chaque compte qui a pourtant accès aux deux magasins :
     * le propriétaire, un responsable des deux, l'expert-comptable.
     */
    public function test_l_url_d_un_enregistrement_d_un_autre_magasin_de_la_societe_repond_404_meme_pour_un_compte_autorise_dans_les_deux(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $venteDeLyon = $this->vendre($lyon);

        foreach ([$this->proprietaireDe($lyon), $this->responsable($lyon, $villeurbanne), $this->expertComptable($lyon)] as $compte) {
            $this->flushSession();
            $this->actingAs($compte);
            $this->get(FactureResource::getUrl('view', ['record' => $venteDeLyon], tenant: $lyon))->assertOk();
            $this->get(FactureResource::getUrl('view', ['record' => $venteDeLyon], tenant: $villeurbanne))->assertNotFound();
        }
    }

    private function vendre(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(),
            Client::factory()->create(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
            ModePaiement::Especes,
        ));
    }

    /**
     * Une entrée du journal de la société, inscrite par une requête HTTP,
     * avec sa trace, et un contrôle Sirene à son registre des
     * vérifications.
     */
    private function inscrireParUneRequete(Societe $societe): EntreeAudit
    {
        Route::post("/test/auditer/{$societe->id}", fn (): int => app(TenantContext::class)->runAsSociete(
            $societe,
            fn (): int => DB::transaction(function () use ($societe): int {
                app(ControleSirene::class)->noterUnite($societe, app(ControleSirene::class)->unite($societe->siren));

                return app(JournalAudit::class)->inscrire(ActionAuditee::IdentiteModifiee, null, null, null, ['raison_sociale' => 'B'])->id;
            }),
        ));

        return EntreeAudit::withoutGlobalScopes()->findOrFail($this->post("/test/auditer/{$societe->id}")->getContent());
    }
}
