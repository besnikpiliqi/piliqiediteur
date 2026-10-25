<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CaissesDeLaSociete;
use App\Domain\Facturation\Services\FacturesAEncaisserDeLaSociete;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Domain\Tenancy\Exceptions\TenantContextMissingException;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Carbon\CarbonImmutable;
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
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
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
        EntreeAudit::class,
        Identifiant::class,
        PieceVerification::class,
        TraceAudit::class,
        TransmissionPropriete::class,
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
     * propre société, la même ligne s'écrit pour un compte rattaché à ce
     * magasin (un compte de membre ne vaut que pour son magasin).
     */
    public function test_la_base_refuse_d_affecter_un_compte_au_magasin_d_une_autre_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $autreMagasinA = $this->ouvrirUnAutreMagasin($magasinA, ['nom' => 'A bis']);
        $vendeurDeA = $this->membre($magasinA, RoleMagasin::Vendeur);
        $compteDeABis = User::factory()->membreDe($autreMagasinA)->create();
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $ligne = fn (Magasin $magasin, int $societeId, User $compte): array => ['societe_id' => $societeId, 'magasin_id' => $magasin->id, 'user_id' => $compte->id, 'role' => RoleMagasin::Vendeur->value, 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert($ligne($magasinB, $magasinA->societe_id, $vendeurDeA))), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert($ligne($magasinB, $magasinB->societe_id, $vendeurDeA))), QueryException::class);

        $this->assertSame(0, DB::table('affectations')->where('magasin_id', $magasinB->id)->count());
        $this->assertTrue(DB::table('affectations')->insert($ligne($autreMagasinA, $magasinA->societe_id, $compteDeABis)));
    }

    /**
     * Un magasin d'une autre société glissé dans une action de la société
     * est refusé : il ne se modifie pas.
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
        $this->vendre(app(OuvrirLaDemonstrationAction::class)->handle($enVerification->proprietaire, $enVerification));
        $this->assertFalse($enVerification->fresh()->aVenduUneFois());
    }

    /**
     * Les factures à encaisser que lit l'espace d'administration d'une
     * société sont celles de ses seuls magasins : ni le magasin ni la somme
     * d'une autre société n'y entrent.
     */
    public function test_aucune_somme_d_une_autre_societe_n_entre_dans_les_factures_a_encaisser(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $this->emettreSansLaRegler($magasinA, 12);
        $this->emettreSansLaRegler($magasinB, 99);
        app(TenantContext::class)->clear();

        $vuParA = app(FacturesAEncaisserDeLaSociete::class)->pour($this->proprietaireDe($magasinA));
        $vuParB = app(FacturesAEncaisserDeLaSociete::class)->pour($this->proprietaireDe($magasinB));

        $this->assertSame([$magasinA->id => ['nombre' => 1, 'restant_du' => 1200, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $vuParA);
        $this->assertSame([$magasinB->id => ['nombre' => 1, 'restant_du' => 9900, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $vuParB);
    }

    /**
     * Les caisses que lit la page « Les caisses » de l'espace
     * d'administration d'une société (voir CaissesDeLaSociete) sont celles
     * de ses seuls magasins : ni le magasin, ni le relevé, ni la caisse
     * ouverte d'une autre société n'y entrent.
     */
    public function test_aucune_caisse_d_une_autre_societe_n_entre_dans_les_caisses_de_la_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $this->vendre($magasinA);
        $venteDeB = $this->vendre($magasinB);
        app(TenantContext::class)->runAs($magasinB, fn () => app(FermerSaCaisseAction::class)->handle(self::responsableDeTest($magasinB), self::responsableDeTest($magasinB)));
        app(TenantContext::class)->clear();

        $vuParA = app(CaissesDeLaSociete::class)->pour($this->proprietaireDe($magasinA), CarbonImmutable::today());
        $vuParB = app(CaissesDeLaSociete::class)->pour($this->proprietaireDe($magasinB), CarbonImmutable::today());

        $this->assertSame([[$magasinA->id, ['Responsable de test'], 0]], array_map(fn (array $caisses): array => [$caisses['magasin']->id, $caisses['caisses_ouvertes'], $caisses['releves']->count()], $vuParA));
        $this->assertSame([[$magasinB->id, [], 1]], array_map(fn (array $caisses): array => [$caisses['magasin']->id, $caisses['caisses_ouvertes'], $caisses['releves']->count()], $vuParB));
        $this->assertSame($venteDeB->total_ttc, $vuParB[0]['releves']->sole()->total_ttc);
    }

    /**
     * Le navigateur peut appeler toute méthode publique d'une page Livewire,
     * avec les paramètres qu'il veut, et lit ce qu'elle rend : le tableau
     * de bord de la société n'en a aucune qui rende l'adresse de la liste
     * des factures d'un magasin désigné par son identifiant. Le propriétaire
     * de A qui la demande pour le magasin de B est refusé, et l'uuid de B
     * ne sort ni de cet appel ni de la page.
     */
    public function test_le_tableau_de_bord_de_la_societe_ne_rend_l_adresse_d_aucun_magasin_designe_par_le_navigateur(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $this->dansLEspaceDeLaSociete($magasinA->societe, $this->proprietaireDe($magasinA));
        $page = Livewire::test(TableauDeBord::class)
            ->assertSee(htmlspecialchars(FactureResource::urlDesFacturesAEncaisser($magasinA)), escape: false)
            ->assertDontSee($magasinB->uuid);

        $this->expectException(MethodNotFoundException::class);

        $page->call('urlDesFacturesAEncaisser', $magasinB->id);
    }

    /**
     * La garde du SIREN ne lit, des autres sociétés de ce SIREN, qu'une
     * réponse par oui ou non (une autre inscription le garde-t-elle ?) :
     * chaque requête qui porte sur ce SIREN, à la demande d'inscription
     * comme à sa confirmation, est un « exists » ou une écriture limitée à
     * ce SIREN, jamais une lecture d'une autre société.
     */
    public function test_la_garde_du_siren_ne_lit_d_une_autre_societe_qu_un_booleen(): void
    {
        $gardien = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($gardien);
        app(TenantContext::class)->clear();
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete;
        });

        $this->assertThrows(fn () => $this->inscrireUneSociete($gardien->siren), ValidationException::class);

        $surLeSiren = array_values(array_filter($requetes, fn (QueryExecuted $requete): bool => in_array($gardien->siren, $requete->bindings, true)));
        $this->assertNotSame([], $surLeSiren);
        foreach ($surLeSiren as $requete) {
            $this->assertStringStartsWith('select exists', $requete->sql);
        }
    }

    /**
     * Un code ne vaut que pour la société de son courrier : saisi avec le
     * SIREN d'une autre inscription, il est refusé, aucune des deux n'est
     * validée, et l'essai faux ne se compte et ne s'inscrit que dans la
     * société dont le SIREN a été saisi ; le code juste reste intact.
     */
    public function test_un_code_ne_valide_que_la_societe_de_son_courrier(): void
    {
        $premiere = $this->inscrireUneSociete();
        $seconde = $this->inscrireUneSociete();
        $codeDeLaPremiere = $this->codeDuCourrier($premiere);
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(SaisirCodeDeConfirmationAction::class)->handle((string) $seconde->siren, $codeDeLaPremiere, 'tiers@ailleurs.fr', 'un-mot-de-passe-sur', 'un-mot-de-passe-sur', '127.0.0.1'),
            fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]],
        );

        $this->assertSame([false, false], [$premiere->fresh()->estValidee(), $seconde->fresh()->estValidee()]);
        $etat = fn (Societe $societe): array => app(TenantContext::class)->runAsSociete($societe, fn (): array => [
            CodeConfirmation::query()->sole()->tentatives,
            EntreeAudit::query()->where('action', ActionAuditee::CodeSaisi->value)->count(),
        ]);
        $this->assertSame([[0, 0], [1, 1]], [$etat($premiere), $etat($seconde)]);
        $this->assertSame((int) $premiere->proprietaire()->sole()->id, (int) $this->saisirLeCodeDuCourrier($premiere)->id);
    }

    /**
     * Le code d'un courrier d'une procédure de l'éditeur ne vaut que pour
     * la société de son courrier : saisi sur la page de son motif avec le
     * SIREN d'une autre société, il est refusé, l'essai faux ne se compte
     * que dans cette autre société, et rien n'est servi ni noté nulle part ;
     * avec son SIREN, il ne sert et ne s'inscrit que dans sa société.
     */
    public function test_un_code_de_procedure_ne_vaut_que_pour_la_societe_de_son_courrier(): void
    {
        $premiere = $this->inscrireUneSociete();
        $seconde = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($premiere);
        $this->saisirLeCodeDuCourrier($seconde);
        $editeur = new InterventionDeLEditeur('Récupération du compte du propriétaire', 'DOSSIER-2026-0002', ['alice']);
        foreach ([$premiere, $seconde] as $societe) {
            app(TenantContext::class)->clear();
            app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Recuperation, $editeur);
        }
        $codeDeLaPremiere = $this->codeDuCourrier($premiere, MotifCode::Recuperation);
        $etat = fn (Societe $societe): array => app(TenantContext::class)->runAsSociete($societe, fn (): array => [
            CodeConfirmation::query()->where('motif', MotifCode::Recuperation->value)->sole()->only(['tentatives', 'utilise_le']),
            VerificationSociete::query()->where('type', TypeVerification::Courrier->value)->count(),
        ]);
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $seconde->siren, MotifCode::Recuperation, $codeDeLaPremiere, '127.0.0.1'),
            fn (ValidationException $refus): bool => $refus->errors() === ['code' => [SaisirCodeDeConfirmationAction::MESSAGE_CODE_FAUX]],
        );

        $this->assertSame([[['tentatives' => 0, 'utilise_le' => null], 1], [['tentatives' => 1, 'utilise_le' => null], 1]], [$etat($premiere), $etat($seconde)]);

        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $premiere->siren, MotifCode::Recuperation, $codeDeLaPremiere, '127.0.0.1');

        $this->assertSame([2, 1], [$etat($premiere)[1], $etat($seconde)[1]]);
        $this->assertNotNull($etat($premiere)[0]['utilise_le']);
    }

    /**
     * La nouvelle inscription d'un SIREN libéré par la clôture d'une autre
     * ne lit rien de ses réglages : son identité vient du répertoire
     * Sirene, ses réglages sont les siens.
     */
    public function test_aucun_reglage_d_une_inscription_close_du_meme_siren_n_est_lu_ni_revele(): void
    {
        $close = $this->inscrireUneSociete();
        app(TenantContext::class)->runAsSociete($close, fn (): bool => $close->forceFill(['raison_sociale' => 'Raison choisie par l\'autre', 'regime_tva' => 'franchise_en_base', 'immatriculation' => 'RCS Nantes'])->save());
        app(CloreLInscriptionAction::class)->refuser($close, new InterventionDeLEditeur('Inscription faite par erreur.', 'DOSSIER-2026-0060', ['alice']));

        $nouvelle = $this->inscrireUneSociete($close->siren);

        $this->assertSame(['SOCIETE FICTIVE '.$close->siren, null, null], [$nouvelle->raison_sociale, $nouvelle->regime_tva, $nouvelle->immatriculation]);
        $this->assertSame('Raison choisie par l\'autre', $close->fresh()->raison_sociale);
        $this->assertNotSame($close->id, $nouvelle->id);
    }

    /**
     * Une inscription close et la nouvelle inscription du même SIREN
     * restent isolées : chacune ne voit que ses magasins, son journal et
     * ses vérifications, et l'espace de l'une répond 404 au propriétaire de
     * l'autre.
     */
    public function test_une_inscription_close_et_la_nouvelle_du_meme_siren_ne_voient_rien_l_une_de_l_autre(): void
    {
        $close = $this->inscrireUneSociete();
        $premiere = $this->ouvrirUneSociete(['nom' => 'Première', 'a_verifier' => true], $close->proprietaire()->sole());
        app(CloreLInscriptionAction::class)->refuser($close, new InterventionDeLEditeur('Inscription faite par erreur.', 'DOSSIER-2026-0061', ['alice']));
        $nouvelle = $this->inscrireUneSociete($close->siren);
        $seconde = $this->ouvrirUneSociete(['nom' => 'Seconde', 'a_verifier' => true, 'nic' => substr(FauxRepertoireSirene::siretDeLaBoutique((string) $close->siren), 9)], $nouvelle->proprietaire()->sole());
        app(TenantContext::class)->clear();

        $this->assertSame([[$premiere->id], [$seconde->id]], [$premiere->societe->magasins()->pluck('id')->all(), $seconde->societe->magasins()->pluck('id')->all()]);
        $this->assertSame(
            [[$premiere->societe_id], [$premiere->societe_id]],
            app(TenantContext::class)->runAsSociete($premiere->societe, fn (): array => [
                EntreeAudit::query()->pluck('societe_id')->unique()->values()->all(),
                VerificationSociete::query()->pluck('societe_id')->unique()->values()->all(),
            ]),
        );
        $this->actingAs($this->proprietaireDe($seconde))
            ->get(TableauDeBord::getUrl(panel: 'societe', tenant: $seconde->societe))
            ->assertSee('Seconde')
            ->assertDontSee('Première');
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $premiere->societe))->assertNotFound();
    }

    /**
     * La clôture d'une inscription ne retire que l'adresse de connexion de
     * son propre propriétaire, et n'inscrit ce retrait qu'au journal
     * d'audit de sa société : le propriétaire d'une inscription récente et
     * celui d'une société validée gardent leur adresse de connexion, et
     * leur journal ne reçoit rien.
     */
    public function test_la_cloture_d_une_inscription_ne_retire_que_l_adresse_de_son_proprietaire(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $echue = $this->inscrireUneSociete(null, 'echue@exemple.fr');
        $validee = $this->inscrireUneSociete(null, 'validee@exemple.fr');
        $this->saisirLeCodeDuCourrier($validee);
        $this->travelTo(self::aParis('2026-10-25 10:00:00'));
        $recente = $this->inscrireUneSociete(null, 'recente@exemple.fr');
        $this->travelTo(self::aParis('2026-11-01 10:00:00'));
        app(TenantContext::class)->clear();

        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        $this->assertSame(
            [null, 'validee@exemple.fr', 'recente@exemple.fr'],
            [$echue->proprietaire()->sole()->email, $validee->proprietaire()->sole()->email, $recente->proprietaire()->sole()->email],
        );
        $this->assertSame(
            [1, 0, 0],
            [count($this->entreesDAudit($echue, ActionAuditee::AdresseDeConnexionRetiree)), count($this->entreesDAudit($validee, ActionAuditee::AdresseDeConnexionRetiree)), count($this->entreesDAudit($recente, ActionAuditee::AdresseDeConnexionRetiree))],
        );
    }

    /**
     * La clôture d'une inscription n'annule que les transmissions de la
     * propriété de sa société, et n'inscrit leur annulation qu'à son
     * journal d'audit : celle d'une inscription récente reste valable, et
     * son administrateur devient propriétaire en suivant son lien.
     */
    public function test_la_cloture_d_une_inscription_n_annule_que_les_transmissions_de_sa_societe(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $echue = $this->inscrireUneSociete(null, 'echue@exemple.fr');
        $this->travelTo(self::aParis('2026-10-25 10:00:00'));
        $recente = $this->inscrireUneSociete(null, 'recente@exemple.fr');
        $administrateurDeLEchue = $this->administrateurPretAReprendre($this->creerLeMagasinDuSiege($echue), 'admin.echue@exemple.fr');
        $administrateurDeLaRecente = $this->administrateurPretAReprendre($this->creerLeMagasinDuSiege($recente), 'admin.recente@exemple.fr');
        $this->travelTo(self::aParis('2026-11-01 09:00:00'));
        $this->demanderLaTransmission($echue->proprietaire()->sole(), $administrateurDeLEchue, self::MOT_DE_PASSE_CHOISI);
        $jetonDeLaRecente = $this->demanderLaTransmission($recente->proprietaire()->sole(), $administrateurDeLaRecente, self::MOT_DE_PASSE_CHOISI);
        $this->travelTo(self::aParis('2026-11-01 10:00:00'));
        app(TenantContext::class)->clear();

        $this->artisan('app:clore-inscriptions-sans-code')->expectsOutput('1 inscription(s) close(s).')->assertSuccessful();

        $this->assertSame(
            [1, 0],
            [count($this->entreesDAudit($echue, ActionAuditee::TransmissionAnnulee)), count($this->entreesDAudit($recente, ActionAuditee::TransmissionAnnulee))],
        );
        app(TenantContext::class)->clear();
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateurDeLaRecente->refresh(), $jetonDeLaRecente, self::MOT_DE_PASSE_CHOISI);
        $this->assertTrue($recente->proprietaire()->sole()->is($administrateurDeLaRecente));
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
        $vente = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);

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
     * le propriétaire, un administrateur des deux, l'expert-comptable.
     */
    public function test_l_url_d_un_enregistrement_d_un_autre_magasin_de_la_societe_repond_404_meme_pour_un_compte_autorise_dans_les_deux(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $venteDeLyon = $this->vendre($lyon);

        foreach ([$this->proprietaireDe($lyon), $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], [], PermissionRegistry::toutes()), $this->expertComptable($lyon)] as $compte) {
            $this->flushSession();
            $this->actingAs($compte);
            $this->get(FactureResource::getUrl('view', ['record' => $venteDeLyon], tenant: $lyon))->assertOk();
            $this->get(FactureResource::getUrl('view', ['record' => $venteDeLyon], tenant: $villeurbanne))->assertNotFound();
        }
    }

    /**
     * À la connexion, un identifiant ne lit que la ligne de son identifiant
     * et le compte qu'elle désigne, dans quelque société qu'il soit, en une
     * seule requête (la même qu'il existe ou non) : aucune requête sur les
     * comptes ou les identifiants qui ne porte sur eux.
     */
    public function test_la_connexion_ne_lit_d_un_identifiant_que_le_compte_qu_il_designe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $this->membre($magasinA, RoleMagasin::Vendeur);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $vendeurDeB = $this->membre($magasinB, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeurDeB->identifiantDeConnexion();
        app(TenantContext::class)->clear();
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete;
        });

        $compte = auth()->getProvider()->retrieveByCredentials(['login' => substr($identifiant, 0, 4).' '.substr($identifiant, 4), 'password' => self::MOT_DE_PASSE_CHOISI]);

        $this->assertTrue($vendeurDeB->is($compte));
        $surLesComptes = array_values(array_filter($requetes, fn (QueryExecuted $requete): bool => preg_match('/from "?(users|identifiants)"?/', $requete->sql) === 1));
        $this->assertCount(1, $surLesComptes);
        $this->assertSame([$identifiant], $surLesComptes[0]->bindings);
        $this->assertMatchesRegularExpression('/^select \* from "users" where "id" in \(select "user_id" from "identifiants" where "identifiant" = \?\) limit 1$/', $surLesComptes[0]->sql);
    }

    /**
     * La page du lien de la transmission de la propriété ne lit que la
     * demande que désigne l'empreinte de son jeton, et ne la propose qu'à
     * son destinataire : jamais à un compte d'une autre société.
     */
    public function test_la_page_du_lien_de_transmission_ne_lit_que_la_demande_qu_il_designe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $administrateurDeA = $this->administrateurPretAReprendre($magasinA, 'repreneur-a@example.test');
        $jetonDeA = $this->demanderLaTransmission($this->proprietaireDe($magasinA), $administrateurDeA);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $administrateurDeB = $this->administrateurPretAReprendre($magasinB, 'repreneur-b@example.test');
        $this->demanderLaTransmission($this->proprietaireDe($magasinB), $administrateurDeB);
        app(TenantContext::class)->clear();
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete;
        });

        $demande = AccepterLaTransmissionDeLaProprieteAction::demandeDestineeA($administrateurDeA, $jetonDeA);

        $this->assertSame((int) $magasinA->societe_id, (int) $demande?->societe_id);
        $this->assertCount(1, $requetes);
        $this->assertSame([TransmissionPropriete::empreinte($jetonDeA)], $requetes[0]->bindings);
        $this->assertNull(AccepterLaTransmissionDeLaProprieteAction::demandeDestineeA($administrateurDeB, $jetonDeA));
        $this->actingAs($administrateurDeB)->get(route('filament.societe.transmission', ['jeton' => $jetonDeA]))
            ->assertOk()
            ->assertSee(AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN)
            ->assertDontSee('repreneur-a@example.test');
    }

    /**
     * Une personne qui vend dans deux magasins y a deux comptes : chacun
     * n'ouvre que son magasin et n'y voit que les ventes qu'il y a faites.
     */
    public function test_une_personne_de_deux_magasins_a_deux_comptes_et_ne_voit_dans_chacun_que_ce_qu_il_y_a_fait(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $aLyon = $this->membre($lyon, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $aVilleurbanne = $this->membre($villeurbanne, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $venteDeLyon = app(TenantContext::class)->runAs($lyon, fn (): Facture => self::vendreAuComptant($aLyon, Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes));
        $venteDeVilleurbanne = app(TenantContext::class)->runAs($villeurbanne, fn (): Facture => self::vendreAuComptant($aVilleurbanne, Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 15, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes));

        foreach ([[$aLyon, $lyon, $villeurbanne, $venteDeLyon, $venteDeVilleurbanne], [$aVilleurbanne, $villeurbanne, $lyon, $venteDeVilleurbanne, $venteDeLyon]] as [$compte, $sien, $autre, $saVente, $autreVente]) {
            $this->assertSame([[(int) $sien->id], false], [$compte->magasinsAccessibles()->modelKeys(), $compte->peutOuvrir($autre)]);
            $this->flushSession();
            $this->actingAs($compte);
            $this->get(FactureResource::getUrl('view', ['record' => $saVente], tenant: $sien))->assertOk();
            $this->get(FactureResource::getUrl('index', tenant: $autre))->assertNotFound();
            $this->assertSame([(int) $saVente->id], app(TenantContext::class)->runAs($sien, fn (): array => Facture::query()->visiblesPar($compte)->pluck('id')->map(fn ($id): int => (int) $id)->all()));
            $this->assertNotSame((int) $saVente->id, (int) $autreVente->id);
        }
        $this->assertNotSame($aLyon->identifiantDeConnexion(), $aVilleurbanne->identifiantDeConnexion());
    }

    /**
     * Un ticket de 12,00 € TTC réglé en espèces dans ce magasin par son
     * responsable de test : le propriétaire ne vend jamais, pas même dans
     * le magasin de démonstration.
     */
    private function vendre(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant(self::responsableDeTest($magasin),
            Client::factory()->create(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
            ModePaiement::Especes,
        ));
    }

    /**
     * Un ticket d'un article au prix TTC donné, émis dans ce magasin pour un
     * nouveau client particulier et laissé sans règlement.
     */
    private function emettreSansLaRegler(Magasin $magasin, float $prixTtc): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(),
            Client::factory()->create(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
        )));
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
