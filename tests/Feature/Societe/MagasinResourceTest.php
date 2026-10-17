<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MagasinResource\Pages\CreerMagasin;
use App\Filament\Societe\Resources\MagasinResource\Pages\ListMagasins;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\EtablissementsActifs;
use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les magasins dans l'espace de la société (voir MagasinResource) : leur
 * liste, limitée à la portée de la personne connectée, et la création d'un
 * magasin (voir CreerMagasin) : la liste des établissements actifs de son
 * SIREN, lue au répertoire Sirene factice ; le choix d'un établissement qui
 * préremplit NIC, nom, adresse et code ; la saisie du NIC quand la liste ne
 * suffit pas ; et, pour tout magasin, les contrôles de
 * CreerEtablissementAction.
 */
class MagasinResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ce qui trahirait l'autre société du même SIREN : sa raison sociale, le
     * nom et le code de son magasin, ou une marque sur son établissement.
     */
    private const TRACES_DE_L_AUTRE_SOCIETE = ['Concurrente', 'concurrente', 'CONCUR', CreerMagasin::MARQUE_DEJA_UN_MAGASIN];

    /**
     * La liste des magasins ne montre que les magasins réels de la portée
     * de la personne connectée, jamais la démonstration ni un magasin d'une
     * autre société.
     */
    public function test_la_liste_ne_montre_que_les_magasins_reels_de_la_portee(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'a_verifier' => true]);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($lyon), $lyon->societe);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $administrateur = $this->administrateur($lyon, [$lyon->id]);

        $this->dansLEspaceDeLaSociete($lyon->societe, $this->proprietaireDe($lyon));
        Livewire::test(ListMagasins::class)
            ->assertCanSeeTableRecords([$lyon, $villeurbanne])
            ->assertCanNotSeeTableRecords([$demonstration, $ailleurs]);

        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);
        Livewire::test(ListMagasins::class)
            ->assertCanSeeTableRecords([$lyon])
            ->assertCanNotSeeTableRecords([$villeurbanne, $demonstration, $ailleurs]);
    }

    public function test_la_liste_des_etablissements_actifs_vient_du_repertoire_sirene(): void
    {
        $societe = $this->inscrireUneSociete();
        $ferme = IdentifiantsInsee::avecSaCle($societe->siren.'0003');
        $this->repertoire()->ajouterUnEtablissement($ferme, 'FERME DEPUIS');
        $this->repertoire()->fermer($ferme);

        $page = $this->ouvrirLaCreation($societe);

        $page->assertSet('listeDisponible', true)
            ->assertSet('listeComplete', true)
            ->assertSeeText(['SIRET '.$this->siege($societe).' (siège)', 'SIRET '.$this->boutique($societe).' · '.FauxRepertoireSirene::ENSEIGNE_DE_LA_BOUTIQUE, '1 RUE DE LA REPUBLIQUE, 75001 PARIS'])
            ->assertDontSeeText(['FERME DEPUIS', $ferme]);
        $this->assertSame([$this->siege($societe), $this->boutique($societe)], array_map(strval(...), array_keys($page->get('etablissements'))));
    }

    /**
     * Choisir un établissement préremplit son NIC, le nom du magasin (son
     * enseigne, sinon « Magasin » et sa commune), son adresse et un code
     * tiré de sa commune ; la création suit, avec l'adresse du répertoire.
     */
    public function test_choisir_un_etablissement_preremplit_le_nic_le_nom_l_adresse_et_le_code(): void
    {
        $societe = $this->inscrireUneSociete();

        $page = $this->ouvrirLaCreation($societe)
            ->fillForm(['etablissement' => $this->siege($societe)])
            ->assertFormSet(['nic' => substr($this->siege($societe), 9), 'nom' => 'Magasin Paris', 'code' => 'PARIS'])
            ->assertSeeText('1 RUE DE LA REPUBLIQUE, 75001 PARIS')
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->assertFormSet(['nic' => substr($this->boutique($societe), 9), 'nom' => 'Boutique Republique', 'code' => 'PARIS'])
            ->call('create')
            ->assertHasNoFormErrors();

        $magasin = Magasin::query()->where('societe_id', $societe->id)->sole();
        $page->assertRedirect(MagasinResource::getUrl('index', panel: 'societe', tenant: $societe));
        $this->assertSame(
            ['Boutique Republique', 'PARIS', $this->boutique($societe), '1 RUE DE LA REPUBLIQUE', '75001', 'PARIS'],
            [$magasin->nom, $magasin->code, $magasin->siret, $magasin->adresse, $magasin->code_postal, $magasin->ville],
        );
        $this->assertNotNull($magasin->etablissement_verifie_le);
    }

    /**
     * Le code proposé est libre dans la société : un deuxième magasin de
     * la même commune reçoit le premier suffixe libre ; le code qu'une
     * autre société porte n'y change rien, et ne se devine pas.
     */
    public function test_le_code_propose_est_unique_dans_la_societe_et_ignore_les_autres_societes(): void
    {
        Magasin::factory()->create(['code' => 'PARIS']);
        $societe = $this->inscrireUneSociete();

        $this->ouvrirLaCreation($societe)
            ->fillForm(['etablissement' => $this->siege($societe)])
            ->assertFormSet(['code' => 'PARIS'])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->ouvrirLaCreation($societe)
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->assertFormSet(['code' => 'PARIS2']);
    }

    /**
     * Le nom et le code proposés suivent l'établissement choisi tant que la
     * personne ne les a pas changés ; ce qu'elle a saisi n'est jamais
     * écrasé, et un code saisi en minuscules s'enregistre en majuscules.
     */
    public function test_le_nom_et_le_code_proposes_suivent_l_etablissement_sans_ecraser_une_saisie(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->ouvrirLaCreation($societe)
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->fillForm(['code' => 'lyon1'])
            ->fillForm(['etablissement' => $this->siege($societe)])
            ->assertFormSet(['nom' => 'Magasin Paris', 'code' => 'lyon1'])
            ->fillForm(['nom' => 'Lyon Centre'])
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->assertFormSet(['nom' => 'Lyon Centre', 'code' => 'lyon1', 'nic' => substr($this->boutique($societe), 9)])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(['Lyon Centre', 'LYON1'], [Magasin::query()->sole()->nom, Magasin::query()->sole()->code]);
    }

    /**
     * Passer à la saisie du NIC vide le NIC, le nom et le code que proposait
     * l'établissement choisi avant, qui ne sont plus ceux du magasin, mais
     * garde ce que la personne a saisi : le magasin créé sur un NIC d'un
     * établissement de Lyon ne s'appelle pas comme celui de Paris et ne
     * prend pas son code.
     */
    public function test_passer_a_la_saisie_du_nic_vide_le_nom_et_le_code_proposes_sans_toucher_une_saisie(): void
    {
        $societe = $this->inscrireUneSociete();
        $lyon = IdentifiantsInsee::avecSaCle($societe->siren.'0004');
        $this->repertoire()->deplacer($lyon, '69002', 'LYON');

        $this->ouvrirLaCreation($societe)
            ->fillForm(['etablissement' => $this->siege($societe)])
            ->assertFormSet(['nom' => 'Magasin Paris', 'code' => 'PARIS'])
            ->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT])
            ->assertFormSet(['nic' => null, 'nom' => null, 'code' => null])
            ->fillForm(['etablissement' => $this->siege($societe)])
            ->fillForm(['nom' => 'Lyon Centre'])
            ->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT])
            ->assertFormSet(['nic' => null, 'nom' => 'Lyon Centre', 'code' => null])
            ->fillForm(['nic' => substr($lyon, 9)])
            ->call('create')
            ->assertHasNoFormErrors();

        $magasin = Magasin::query()->where('societe_id', $societe->id)->sole();
        $this->assertSame(['Lyon Centre', 'LYONCE', $lyon, 'LYON'], [$magasin->nom, $magasin->code, $magasin->siret, $magasin->ville]);
    }

    public function test_un_code_mal_forme_est_refuse_sans_rien_creer(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->ouvrirLaCreation($societe)
            ->fillForm(['etablissement' => $this->siege($societe), 'code' => 'LYON-1'])
            ->call('create')
            ->assertHasFormErrors(['code' => fn (array $reglesEnEchec, array $messages): bool => $messages === [CodeMagasinLibre::MESSAGE]]);

        $this->assertSame(0, Magasin::query()->count());
    }

    /**
     * L'établissement d'un magasin de la société est marqué et ne se
     * choisit pas ; une requête qui le choisit quand même est refusée par
     * la création, avec son message, sans rien créer.
     */
    public function test_un_etablissement_deja_magasin_de_la_societe_est_marque_et_refuse(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->creerLeMagasinDuSiege($societe);

        $this->ouvrirLaCreation($societe)
            ->assertSeeText($this->siege($societe).' (siège)')
            ->assertSeeText('1 RUE DE LA REPUBLIQUE, 75001 PARIS — '.CreerMagasin::MARQUE_DEJA_UN_MAGASIN)
            ->fillForm(['etablissement' => $this->siege($societe)])
            ->call('create')
            ->assertHasFormErrors(['nic' => fn (array $reglesEnEchec, array $messages): bool => $messages === [SiretsDesMagasins::MESSAGE_SIRET_RESERVE]]);

        $this->assertSame(1, Magasin::query()->count());
    }

    /**
     * La saisie du NIC suit les contrôles de toute création : un NIC à clé
     * invalide, un établissement fermé, inconnu ou que le répertoire
     * rattache à une autre entreprise sont refusés avec le motif du
     * répertoire, sans rien créer ; un établissement rattaché ailleurs
     * n'est jamais proposé.
     */
    public function test_la_saisie_du_nic_suit_les_memes_controles_et_refuse_un_etablissement_ferme_ou_d_une_autre_entreprise(): void
    {
        $societe = $this->inscrireUneSociete();
        $inconnu = IdentifiantsInsee::avecSaCle($societe->siren.'0005');
        $this->repertoire()->oublier($inconnu);
        $ailleurs = IdentifiantsInsee::avecSaCle($societe->siren.'0006');
        $this->repertoire()->ajouterUnEtablissement($ailleurs, 'RATTACHE AILLEURS');
        $this->repertoire()->rattacherA($ailleurs, SocieteFactory::sirenFictif());
        $page = $this->ouvrirLaCreation($societe)->assertDontSeeText('RATTACHE AILLEURS');

        $valide = IdentifiantsInsee::avecSaCle($societe->siren.'0004');

        foreach ([
            substr($valide, 9, 4).(((int) substr($valide, -1) + 1) % 10) => SiretValide::MESSAGE,
            substr(IdentifiantsInsee::avecSaCle($societe->siren.'9001'), 9) => ControleSirene::MOTIF_ETABLISSEMENT_FERME,
            substr($inconnu, 9) => ControleSirene::MOTIF_ETABLISSEMENT_INCONNU,
            substr($ailleurs, 9) => ControleSirene::MOTIF_AUTRE_ENTREPRISE,
        ] as $nic => $motif) {
            $page->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT, 'nic' => (string) $nic, 'nom' => 'Autre'])
                ->call('create')
                ->assertHasFormErrors(['nic' => fn (array $reglesEnEchec, array $messages): bool => $messages === [$motif]]);
        }

        $this->assertSame(0, Magasin::query()->count());
    }

    /**
     * Le répertoire qui ne répond pas ne propose aucun établissement : la
     * page le dit, et le NIC se saisit ; une liste partielle le dit aussi.
     */
    public function test_sans_la_liste_du_repertoire_le_nic_se_saisit(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->repertoire()->rendreInjoignable();
        $page = $this->ouvrirLaCreation($societe)
            ->assertSet('listeDisponible', false)
            ->assertSet('etablissements', [])
            ->assertSeeText(CreerMagasin::MESSAGE_LISTE_INDISPONIBLE);
        $this->repertoire()->rendreInjoignable(false);

        $page->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT, 'nic' => substr($this->boutique($societe), 9), 'nom' => 'Ma Boutique'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($this->boutique($societe), Magasin::query()->sole()->siret);
        $this->assertNotNull(Magasin::query()->sole()->etablissement_verifie_le);
        $this->repertoire()->listerEnPartie();
        $this->ouvrirLaCreation($societe)
            ->assertSet('listeComplete', false)
            ->assertSeeText(CreerMagasin::MESSAGE_LISTE_PARTIELLE);
    }

    /**
     * La liste lue au répertoire se garde pour la société : rouvrir la page,
     * même par un autre compte, ne la relit pas ; une autre société du même
     * SIREN lit la sienne, sans rien recevoir de la première.
     */
    public function test_rouvrir_la_creation_ne_relit_pas_la_liste_qui_se_garde_pour_la_societe(): void
    {
        $repertoire = $this->repertoireQuiCompte();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $societe = $magasin->societe;
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);

        $this->ouvrirLaCreation($societe)->assertSet('listeDisponible', true);
        $this->ouvrirLaCreation($societe)->assertSet('listeDisponible', true);
        $this->ouvrirLaCreation($societe, $administrateur)->assertSeeText($this->boutique($societe).' · '.FauxRepertoireSirene::ENSEIGNE_DE_LA_BOUTIQUE);
        $this->assertSame(1, $repertoire->listes);

        $this->ouvrirLaCreation($this->inscrireUneSociete($societe->siren))->assertSet('listeDisponible', true);
        $this->assertSame(2, $repertoire->listes);
    }

    /**
     * Chaque lecture de la liste au répertoire et chaque tentative de
     * création comptent : au-delà de la limite de la minute, la création
     * est refusée sans consulter le répertoire, et la liste qui n'est pas
     * gardée n'est pas lue, le NIC se saisissant ; la minute suivante, le
     * répertoire se consulte de nouveau.
     */
    public function test_au_dela_de_la_limite_la_page_ne_consulte_plus_le_repertoire(): void
    {
        $repertoire = $this->repertoireQuiCompte();
        $societe = $this->inscrireUneSociete();
        $repertoire->rendreInjoignable();
        $page = $this->ouvrirLaCreation($societe)->assertSet('listeDisponible', false);
        $repertoire->rendreInjoignable(false);
        $nicACleInvalide = substr($this->boutique($societe), 9, 4).(((int) substr($this->boutique($societe), -1) + 1) % 10);

        for ($tentative = 2; $tentative <= CreerMagasin::CONSULTATIONS_SIRENE_PAR_MINUTE; $tentative++) {
            $page->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT, 'nic' => $nicACleInvalide, 'nom' => 'Ma Boutique'])
                ->call('create')
                ->assertHasFormErrors(['nic' => fn (array $reglesEnEchec, array $messages): bool => $messages === [SiretValide::MESSAGE]]);
        }
        $etablissementsConsultes = $repertoire->etablissements;

        $page->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT, 'nic' => substr($this->boutique($societe), 9), 'nom' => 'Ma Boutique'])
            ->call('create')
            ->assertHasFormErrors(['nic' => fn (array $reglesEnEchec, array $messages): bool => $messages === [CreerMagasin::MESSAGE_TROP_DE_CONSULTATIONS]]);
        $this->ouvrirLaCreation($societe)
            ->assertSet('listeDisponible', false)
            ->assertSeeText(CreerMagasin::MESSAGE_LISTE_INDISPONIBLE);

        $this->assertSame([1, $etablissementsConsultes, 0], [$repertoire->listes, $repertoire->etablissements, Magasin::query()->count()]);

        $this->travel(1)->minutes();

        $this->ouvrirLaCreation($societe)
            ->assertSet('listeDisponible', true)
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame([2, $this->boutique($societe)], [$repertoire->listes, Magasin::query()->sole()->siret]);
    }

    /**
     * Une autre société du même SIREN, validée, a fait de la boutique son
     * magasin : ni la liste ni les messages n'en disent rien, et la société
     * en cours de vérification crée son magasin à cet établissement comme à
     * un autre (rien n'est réservé pour elle avant sa validation).
     */
    public function test_aucune_information_sur_une_autre_societe_n_apparait_dans_la_liste_ni_dans_les_messages(): void
    {
        $siren = SocieteFactory::sirenFictif();
        Magasin::factory()->avecLaSociete(['siren' => $siren, 'raison_sociale' => 'Concurrente SAS'])->create(['nom' => 'Boutique concurrente', 'code' => 'CONCUR', 'siret' => FauxRepertoireSirene::siretDeLaBoutique($siren)]);
        $societe = $this->inscrireUneSociete($siren);

        $this->ouvrirLaCreation($societe)
            ->assertDontSeeText(self::TRACES_DE_L_AUTRE_SOCIETE)
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->assertFormSet(['code' => 'PARIS'])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertDontSeeText(self::TRACES_DE_L_AUTRE_SOCIETE);

        $this->assertSame($this->boutique($societe), Magasin::query()->where('societe_id', $societe->id)->sole()->siret);
    }

    /**
     * Dans une société validée, l'établissement qu'une autre société du même
     * SIREN, révoquée depuis, a réservé reste proposé sans marque, et sa
     * création est refusée par le message de toute création, qui ne dit pas
     * qui l'a réservé.
     */
    public function test_un_siret_reserve_par_une_autre_societe_est_refuse_sans_rien_en_dire(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $autre = Magasin::factory()->avecLaSociete(['siren' => $siren, 'raison_sociale' => 'Concurrente SAS'])->create(['nom' => 'Boutique concurrente', 'code' => 'CONCUR', 'siret' => FauxRepertoireSirene::siretDeLaBoutique($siren)]);
        $autre->societe->forceFill(['statut' => StatutSociete::Revoquee])->save();
        $societe = $this->inscrireUneSociete($siren);
        $societe->forceFill(['validee_le' => now()])->save();

        $this->ouvrirLaCreation($societe->fresh())
            ->assertDontSeeText(self::TRACES_DE_L_AUTRE_SOCIETE)
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->call('create')
            ->assertHasFormErrors(['nic' => fn (array $reglesEnEchec, array $messages): bool => $messages === [SiretsDesMagasins::MESSAGE_SIRET_RESERVE]])
            ->assertDontSeeText(self::TRACES_DE_L_AUTRE_SOCIETE);

        $this->assertSame(0, Magasin::query()->where('societe_id', $societe->id)->count());
    }

    /**
     * La liste lue à l'ouverture de la page ne se remplace pas par une
     * requête : un établissement d'une autre entreprise ne s'y glisse pas.
     */
    public function test_la_liste_des_etablissements_ne_se_modifie_pas_par_une_requete(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->assertThrows(
            fn () => $this->ouvrirLaCreation($societe)->set('etablissements', [FauxRepertoireSirene::siretDuSiege(SocieteFactory::sirenFictif()) => ['nic' => '00011', 'enseigne' => null, 'adresse' => 'ailleurs', 'ville' => 'AILLEURS', 'siege' => true, 'territoire_gere' => true]]),
            CannotUpdateLockedPropertyException::class,
        );
    }

    /**
     * Seuls le propriétaire et un administrateur de tous les magasins
     * autorisé à les gérer ouvrent la création et créent ; ni un
     * administrateur d'une sélection, ni un administrateur sans cette
     * autorisation ; une société suspendue ne crée plus rien.
     */
    public function test_seuls_le_proprietaire_et_un_administrateur_de_tous_les_magasins_autorise_creent_un_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $autorise = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);
        $sansLAutorisation = $this->administrateur($magasin);
        $dUneSelection = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]);

        foreach ([$sansLAutorisation, $dUneSelection] as $compte) {
            $this->dansLEspaceDeLaSociete($societe, $compte);
            Livewire::test(ListMagasins::class)->assertActionHidden('create');
            Livewire::test(CreerMagasin::class)->assertForbidden();
        }

        $this->dansLEspaceDeLaSociete($societe, $autorise);
        Livewire::test(ListMagasins::class)->assertActionVisible('create');
        Livewire::test(CreerMagasin::class)
            ->fillForm(['etablissement' => $this->boutique($societe)])
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertSame(2, $societe->magasinsReels()->count());

        $societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $this->dansLEspaceDeLaSociete($societe->fresh(), $this->proprietaireDe($magasin));
        Livewire::test(CreerMagasin::class)->assertForbidden();
    }

    /**
     * La page de création, ouverte par le propriétaire dans l'espace de sa
     * société.
     */
    private function ouvrirLaCreation(Societe $societe, ?User $compte = null): Testable
    {
        $this->dansLEspaceDeLaSociete($societe, $compte ?? $societe->proprietaire()->firstOrFail());

        return Livewire::test(CreerMagasin::class);
    }

    /**
     * Le répertoire Sirene factice, qui compte ses consultations : les
     * listes d'établissements actifs, et les établissements, lus seuls ou
     * pour une liste.
     */
    private function repertoireQuiCompte(): FauxRepertoireSirene
    {
        $repertoire = new class extends FauxRepertoireSirene
        {
            public int $listes = 0;

            public int $etablissements = 0;

            public function etablissementsActifs(string $siren): EtablissementsActifs
            {
                $this->listes++;

                return parent::etablissementsActifs($siren);
            }

            public function etablissement(string $siret): ?EtablissementSirene
            {
                $this->etablissements++;

                return parent::etablissement($siret);
            }
        };
        $this->app->instance(RepertoireSirene::class, $repertoire);

        return $repertoire;
    }

    private function siege(Societe $societe): string
    {
        return FauxRepertoireSirene::siretDuSiege($societe->siren);
    }

    private function boutique(Societe $societe): string
    {
        return FauxRepertoireSirene::siretDeLaBoutique($societe->siren);
    }

    private function repertoire(): FauxRepertoireSirene
    {
        return app(RepertoireSirene::class);
    }
}
