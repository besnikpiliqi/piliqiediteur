<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Actions\ChangerSiretAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MagasinResource\Pages\CreerMagasin;
use App\Filament\Societe\Resources\MagasinResource\Pages\ListMagasins;
use App\Filament\Societe\Resources\MagasinResource\Pages\ModifierMagasin;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\EtablissementsActifs;
use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les magasins dans l'espace de la société (voir MagasinResource) : leur
 * liste, limitée à la portée de la personne connectée ; la création d'un
 * magasin (voir CreerMagasin) : la liste des établissements actifs de son
 * SIREN, lue au répertoire Sirene factice, le choix d'un établissement qui
 * préremplit NIC, nom, adresse et code, la saisie du NIC quand la liste ne
 * suffit pas, et les contrôles de CreerEtablissementAction ; la fiche d'un
 * magasin (voir ModifierMagasin) : sa modification (voir
 * ModifierEtablissementAction), son changement de SIRET (voir
 * ChangerSiretAction), l'établissement principal et les gestes de son
 * cycle de vie, que FermerEtRouvrirMagasinTest détaille.
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
     * Le mot de passe du propriétaire des sociétés des tests (voir
     * UserFactory).
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

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
     * même par un autre compte, ne la relit pas ; une autre société lit la
     * sienne, sans rien recevoir de la première.
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

        $this->ouvrirLaCreation($this->inscrireUneSociete())->assertSet('listeDisponible', true);
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
     * Une inscription close du même SIREN avait fait de la boutique son
     * magasin : ni la liste ni les messages n'en disent rien, et la
     * nouvelle inscription crée son magasin à cet établissement comme à un
     * autre (rien n'était réservé pour la société close, jamais validée).
     */
    public function test_aucune_information_sur_une_autre_societe_n_apparait_dans_la_liste_ni_dans_les_messages(): void
    {
        $siren = SocieteFactory::sirenFictif();
        $close = $this->inscrireUneSociete($siren);
        app(TenantContext::class)->runAsSociete($close, fn (): bool => $close->forceFill(['raison_sociale' => 'Concurrente SAS'])->save());
        $this->ouvrirUneSociete(['nom' => 'Boutique concurrente', 'code' => 'CONCUR', 'nic' => substr(FauxRepertoireSirene::siretDeLaBoutique($siren), 9), 'a_verifier' => true], $close->proprietaire()->sole());
        app(CloreLInscriptionAction::class)->refuser($close, new InterventionDeLEditeur('Inscription faite par erreur.', 'DOSSIER-2026-0062', ['alice']));
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
     * La création ne demande que le NIC : le SIREN est celui de la
     * société, que la page affiche sans le laisser saisir, et le SIRET du
     * magasin commence toujours par lui.
     */
    public function test_la_creation_se_fait_par_le_nic_apres_le_siren_fixe(): void
    {
        $societe = $this->inscrireUneSociete();

        $this->ouvrirLaCreation($societe)
            ->assertSeeText("Les établissements actifs de votre SIREN {$societe->siren}")
            ->assertFormFieldDoesNotExist('siren')
            ->fillForm(['etablissement' => CreerMagasin::AUTRE_ETABLISSEMENT, 'nic' => substr($this->boutique($societe), 9), 'nom' => 'Ma Boutique'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($societe->siren.substr($this->boutique($societe), 9), Magasin::query()->where('societe_id', $societe->id)->sole()->siret);
    }

    /**
     * Le magasin réenregistre son propre SIRET sans se le voir refuser.
     */
    public function test_le_magasin_reenregistre_son_propre_siret(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);

        $this->ouvrirLaFiche($magasin)
            ->fillForm(['nom' => 'Ma Boutique de Lyon', 'nic' => substr($magasin->siret, 9)])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Ma Boutique de Lyon', $magasin->siret], [$magasin->fresh()->nom, $magasin->fresh()->siret]);
    }

    public function test_le_code_saisi_en_minuscules_s_enregistre_en_majuscules(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->ouvrirLaFiche($magasin)->fillForm(['code' => 'lyon1'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    public function test_le_pays_saisi_en_minuscules_s_enregistre_en_majuscules(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->ouvrirLaFiche($magasin)->fillForm(['pays' => 'fr', 'code_postal' => '13002', 'ville' => 'Marseille'])->call('save')->assertHasNoFormErrors();

        $this->assertSame(['FR', '13002'], [$magasin->fresh()->pays, $magasin->fresh()->code_postal]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function codesRefuses(): array
    {
        return [
            'code d\'une lettre' => ['L', CodeMagasinLibre::MESSAGE],
            'code avec un tiret' => ['LYON-1', CodeMagasinLibre::MESSAGE],
            'code d\'un autre magasin de la société' => ['LYON1', CodeMagasinLibre::MESSAGE_DEJA_UTILISE],
            'code d\'un magasin fermé de la société' => ['ANCIEN', CodeMagasinLibre::MESSAGE_DEJA_UTILISE],
        ];
    }

    /**
     * Un code n'appartient qu'à un seul magasin de la société, même fermé ;
     * refusé, il n'est pas enregistré.
     */
    #[DataProvider('codesRefuses')]
    public function test_un_code_refuse_n_est_pas_enregistre(string $code, string $message): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Lyon', 'code' => 'LYON1']);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Ancien', 'code' => 'ANCIEN'])->forceFill(['statut' => StatutMagasin::Ferme])->save();

        $this->ouvrirLaFiche($magasin)
            ->fillForm(['code' => $code])
            ->call('save')
            ->assertHasFormErrors(['code' => fn (array $reglesEnEchec, array $messages): bool => $messages === [$message]]);

        $this->assertSame('MABOUT', $magasin->fresh()->code);
    }

    /**
     * Dès le premier document numéroté, le code figure dans les numéros :
     * la fiche ne le laisse plus saisir, et le modèle refuse tout
     * changement.
     */
    public function test_le_code_se_verrouille_au_premier_document_numerote(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->numeroterUnPremierDevis($magasin);

        $this->ouvrirLaFiche($magasin)
            ->assertFormFieldIsDisabled('code')
            ->assertSeeText('Il figure déjà dans les numéros des documents du magasin : il ne change plus.');

        $this->assertThrows(fn () => $magasin->fresh()->update(['code' => 'AUTRE']), LogicException::class, 'Le code du magasin figure déjà dans les numéros de ses documents : il ne change plus.');
        $this->assertSame('MABOUT', $magasin->fresh()->code);
        $this->assertTrue($magasin->fresh()->aNumeroteUnDocument());
    }

    /**
     * Une référence de produit (PRD-000001) ne porte pas le code : le
     * premier produit ne le verrouille pas.
     */
    public function test_un_premier_produit_ne_verrouille_pas_le_code(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        Produit::factory()->create(['reference' => null]);

        $this->ouvrirLaFiche($magasin)->assertFormFieldIsEnabled('code')->fillForm(['code' => 'LYON1'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Un premier document numéroté pendant que la fiche est ouverte : le
     * code saisi n'est pas enregistré, le reste l'est ; l'action le refuse
     * aussi sous son verrou, quelle que soit la requête qui le lui donne.
     */
    public function test_un_code_devenu_definitif_pendant_la_saisie_n_est_pas_enregistre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $fiche = $this->ouvrirLaFiche($magasin)->assertFormFieldIsEnabled('code');
        $this->numeroterUnPremierDevis($magasin);

        $fiche->fillForm(['code' => 'AUTRE', 'nom' => 'Ma Boutique de Lyon'])->call('save')->assertHasNoFormErrors()->assertFormSet(['code' => 'MABOUT']);
        app(ModifierEtablissementAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), ['code' => 'AUTRE', 'nom' => 'Lyon Centre']);

        $this->assertSame(['MABOUT', 'Lyon Centre'], [$magasin->fresh()->code, $magasin->fresh()->nom]);
    }

    /**
     * Un magasin qui n'a encore rien vendu ne numérote aucun document la
     * nuit, aucune clôture de caisse en particulier : son code reste
     * modifiable jusqu'à son premier document.
     */
    public function test_un_magasin_sans_vente_garde_son_code_modifiable_apres_les_commandes_de_la_nuit(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'regime_tva' => RegimeTva::ReelSimplifie]);
        $this->travelTo('2026-10-10 01:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $this->ouvrirLaFiche($magasin)->fillForm(['code' => 'LYON1'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Seuls les documents du magasin rendent son code définitif : le
     * premier document d'un autre magasin de la société ne le verrouille
     * pas.
     */
    public function test_le_premier_document_d_un_autre_magasin_ne_rend_pas_le_code_definitif(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre boutique']);
        $this->numeroterUnPremierDevis($autre);

        $this->ouvrirLaFiche($magasin)->fillForm(['code' => 'LYON3'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('LYON3', $magasin->fresh()->code);
        $this->assertTrue($autre->fresh()->aNumeroteUnDocument());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valeursPrisesPendantLEnregistrement(): array
    {
        return [
            'code' => ['code'],
            'SIRET' => ['nic'],
        ];
    }

    /**
     * Un code ou un SIRET, libre à la validation, pris par un autre magasin
     * de la société juste avant l'enregistrement : refusé comme par la
     * validation, sur son champ, sans erreur du serveur, et rien n'est
     * enregistré.
     */
    #[DataProvider('valeursPrisesPendantLEnregistrement')]
    public function test_une_valeur_prise_pendant_l_enregistrement_est_refusee_comme_par_la_validation(string $champ): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $siret = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0005');
        $saisie = ['code' => ['code' => 'LYON1'], 'nic' => ['nic' => substr($siret, 9)]][$champ];
        $fiche = $this->ouvrirLaFiche($magasin);
        $concurrentEnCours = false;
        Magasin::updating(function () use ($magasin, $siret, $saisie, &$concurrentEnCours): void {
            if (! $concurrentEnCours) {
                $concurrentEnCours = true;
                Magasin::factory()->for($magasin->societe)->create(isset($saisie['code']) ? ['code' => 'LYON1'] : ['siret' => $siret]);
            }
        });

        $fiche->fillForm([...$saisie, 'nom' => 'Ma Boutique de Lyon'])
            ->call('save')
            ->assertHasFormErrors([$champ => fn (array $reglesEnEchec, array $messages): bool => $messages === [$champ === 'code' ? CodeMagasinLibre::MESSAGE_DEJA_UTILISE : SiretsDesMagasins::MESSAGE_SIRET_RESERVE]]);

        $this->assertSame(['MABOUT', 'Ma Boutique'], [$magasin->fresh()->code, $magasin->fresh()->nom]);
    }

    /**
     * Un doublon qui n'est ni celui du code ni celui du SIRET n'est
     * attribué à aucun champ : le refus ne dit pas à tort « Ce code est
     * déjà utilisé », et rien n'est enregistré.
     */
    public function test_un_autre_doublon_n_est_pas_attribue_au_code(): void
    {
        $autre = Magasin::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $fiche = $this->ouvrirLaFiche($magasin)->fillForm(['nom' => 'Ma Boutique de Lyon']);
        Magasin::updating(fn (Magasin $enregistre) => $enregistre->forceFill(['uuid' => $autre->uuid]));

        $this->assertThrows(fn () => $fiche->call('save'), UniqueConstraintViolationException::class);

        $this->assertSame('Ma Boutique', $magasin->fresh()->nom);
    }

    /**
     * Une adresse, ou un nouvel établissement, qui change le territoire de
     * TVA du magasin (son taux par défaut et ses règles de vente) est
     * refusé : un tel déménagement passe par un nouveau magasin.
     */
    public function test_un_changement_d_adresse_qui_change_le_territoire_de_tva_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $fortDeFrance = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007');
        $this->repertoire()->deplacer($fortDeFrance, '97200', 'FORT-DE-FRANCE');

        $this->ouvrirLaFiche($magasin)
            ->fillForm(['code_postal' => '97200', 'ville' => 'Fort-de-France'])
            ->call('save')
            ->assertHasFormErrors(['code_postal' => fn (array $reglesEnEchec, array $messages): bool => $messages === [ModifierEtablissementAction::MESSAGE_TERRITOIRE_CHANGE]])
            ->callAction('changerLeSiret', ['nic' => substr($fortDeFrance, 9), 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertNotified(ChangerSiretAction::MESSAGE_TERRITOIRE_CHANGE);

        $this->assertSame(['75001', $magasin->siret], [$magasin->fresh()->code_postal, $magasin->fresh()->siret]);
    }

    /**
     * Le NIC se corrige tant que la société n'est pas validée, contrôlé au
     * répertoire Sirene, et le changement de SIRET s'inscrit au journal des
     * événements du magasin ; une fois la société validée, la fiche ne le
     * propose plus et l'action le refuse.
     */
    public function test_le_nic_se_corrige_avant_la_validation_seulement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $ancien = $magasin->siret;
        $nic = substr(IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0005'), 9);

        $this->ouvrirLaFiche($magasin)->fillForm(['nic' => $nic])->call('save')->assertHasNoFormErrors();

        $this->assertSame($magasin->societe->siren.$nic, $magasin->fresh()->siret);
        $this->assertSame(
            [['parametre' => 'siret', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $magasin->societe->siren.$nic]],
            $this->changementsDeSiret($magasin),
        );
        $magasin->societe->forceFill(['validee_le' => now()])->save();
        $this->ouvrirLaFiche($magasin->fresh())->assertFormFieldIsHidden('nic');
        $this->assertThrows(
            fn () => app(ModifierEtablissementAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), ['nic' => substr($ancien, 9)]),
            LogicException::class,
            ModifierEtablissementAction::MESSAGE_SIRET_SOCIETE_VALIDEE,
        );
    }

    /**
     * Ce qui change, et seulement cela, s'inscrit au journal d'audit, avec
     * l'ancienne et la nouvelle valeur.
     */
    public function test_la_modification_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);

        $this->ouvrirLaFiche($magasin)->fillForm(['nom' => 'Ma Boutique de Lyon', 'code' => 'MABOUT'])->call('save')->assertHasNoFormErrors();

        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::MagasinModifie)[0];
        $this->assertSame([['nom' => 'Ma Boutique'], ['nom' => 'Ma Boutique de Lyon'], $magasin->id], [$entree->anciennes_valeurs, $entree->nouvelles_valeurs, (int) $entree->magasin_id]);
    }

    /**
     * Le magasin déménage : le mot de passe est redemandé, le répertoire
     * Sirene doit confirmer le nouvel établissement (fermé ou INSEE
     * injoignable : refusé, rien ne change) ; le magasin garde ses
     * documents, son code et son identifiant, prend l'adresse du
     * répertoire, et son ancien SIRET reste réservé : aucun autre magasin
     * ne le reprend.
     */
    public function test_changer_le_siret_exige_sirene_garde_le_magasin_et_reserve_l_ancien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $ticket = $this->vendre($magasin);
        $ancien = (string) $magasin->siret;
        $ferme = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'9005');
        $nouveau = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007');
        $this->repertoire()->deplacer($nouveau, '75011', 'PARIS');
        $fiche = $this->ouvrirLaFiche($magasin);

        $fiche->callAction('changerLeSiret', ['nic' => substr($nouveau, 9), 'mot_de_passe' => 'devine-au-hasard'])->assertNotified(MotDePasseRedemande::MESSAGE);
        $fiche->callAction('changerLeSiret', ['nic' => substr($ferme, 9), 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])->assertNotified(ControleSirene::MOTIF_ETABLISSEMENT_FERME);
        $this->repertoire()->rendreInjoignable();
        $fiche->callAction('changerLeSiret', ['nic' => substr($nouveau, 9), 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])->assertNotified(ChangerSiretAction::MESSAGE_INSEE_INJOIGNABLE);
        $this->assertSame($ancien, $magasin->fresh()->siret);
        $this->repertoire()->rendreInjoignable(false);
        $fiche->callAction('changerLeSiret', ['nic' => substr($nouveau, 9), 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])->assertNotified("Le SIRET du magasin est désormais {$nouveau}.");

        $demenage = $magasin->fresh();
        $this->assertSame([$nouveau, 'MABOUT', '75011', 'PARIS'], [$demenage->siret, $demenage->code, $demenage->code_postal, $demenage->ville]);
        $this->assertNotNull($demenage->etablissement_verifie_le);
        $this->assertSame([$ticket->id], app(TenantContext::class)->runAs($demenage, fn (): array => Facture::query()->pluck('id')->all()));
        $this->assertSame(
            [[$ancien, true, false], [$nouveau, false, false]],
            SiretMagasin::query()->where('magasin_id', $magasin->id)->orderBy('id')->get()->map(fn (SiretMagasin $ligne): array => [$ligne->siret, $ligne->retire_le !== null, $ligne->libere_le !== null])->all(),
        );
        $this->assertTrue(SiretsDesMagasins::estReserve($ancien));
        $this->assertThrows(fn () => $this->ouvrirUnAutreMagasin($magasin, ['nic' => substr($ancien, 9)]), fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [SiretsDesMagasins::MESSAGE_SIRET_RESERVE]]);
    }

    /**
     * Le changement de SIRET s'inscrit au journal des événements du
     * magasin (JET 270) et au journal d'audit ; les documents émis gardent
     * l'ancien SIRET qu'ils ont signé, les suivants portent le nouveau.
     */
    public function test_un_changement_de_siret_s_inscrit_au_jet_270_et_les_documents_emis_gardent_l_ancien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $avant = $this->vendre($magasin);
        $ancien = (string) $magasin->siret;
        $nouveau = IdentifiantsInsee::avecSaCle($magasin->societe->siren.'0007');

        $this->ouvrirLaFiche($magasin)->callAction('changerLeSiret', ['nic' => substr($nouveau, 9), 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE]);

        $this->assertSame([['parametre' => 'siret', 'magasin' => ['id' => $magasin->id, 'nom' => 'Ma Boutique'], 'ancienne_valeur' => $ancien, 'nouvelle_valeur' => $nouveau]], $this->changementsDeSiret($magasin));
        $entree = $this->entreesDAudit($magasin->societe, ActionAuditee::SiretChange)[0];
        $this->assertSame([$ancien, $nouveau], [$entree->anciennes_valeurs['siret'], $entree->nouvelles_valeurs['siret']]);
        $apres = $this->vendre($magasin);
        app(TenantContext::class)->runAs($magasin, function () use ($magasin, $avant, $apres, $ancien, $nouveau): void {
            $this->assertSame([$ancien, $ancien], [$avant->fresh()->emetteur()->siret, $avant->fresh()->enregistrementDeVente->contenuSigne()['magasin']['siret']]);
            $this->assertSame([$nouveau, $nouveau], [$apres->fresh()->emetteur()->siret, $apres->fresh()->enregistrementDeVente->contenuSigne()['magasin']['siret']]);
            $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
        });
    }

    /**
     * L'établissement principal n'est qu'une indication : au plus un par
     * société, il passe d'un magasin à l'autre, s'inscrit au journal
     * d'audit, et ne change ni les réglages de la société ni l'émetteur
     * d'aucun magasin ; un administrateur d'une sélection ne le déplace
     * pas.
     */
    public function test_l_etablissement_principal_n_a_aucun_effet_sur_les_reglages(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $dUneSelection = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], [AutorisationsSociete::MAGASINS_GERER]);
        $reglages = $lyon->societe->fresh()->only(['regime_tva', 'option_debits_tva', 'mois_fin_exercice', 'raison_sociale']);
        $emetteurs = [Emetteur::duMagasin($lyon->fresh()), Emetteur::duMagasin($villeurbanne->fresh())];

        $this->ouvrirLaFiche($lyon)->callAction('choisirCommePrincipal');
        $this->ouvrirLaFiche($villeurbanne)->callAction('choisirCommePrincipal');

        $this->assertSame([false, true], [$lyon->fresh()->est_etablissement_principal, $villeurbanne->fresh()->est_etablissement_principal]);
        $this->assertSame($reglages, $lyon->societe->fresh()->only(['regime_tva', 'option_debits_tva', 'mois_fin_exercice', 'raison_sociale']));
        $this->assertEquals($emetteurs, [Emetteur::duMagasin($lyon->fresh()), Emetteur::duMagasin($villeurbanne->fresh())]);
        $this->assertSame(
            [[null, 'Lyon'], ['Lyon', 'Villeurbanne']],
            array_map(fn (EntreeAudit $entree): array => [$entree->anciennes_valeurs['etablissement_principal']['nom'] ?? null, $entree->nouvelles_valeurs['etablissement_principal']['nom']], $this->entreesDAudit($lyon->societe, ActionAuditee::EtablissementPrincipalChoisi)),
        );
        $this->ouvrirLaFiche($lyon, $dUneSelection)->assertActionHidden('choisirCommePrincipal');
        $this->ouvrirLaFiche($villeurbanne, $dUneSelection)->assertActionHidden('retirerCommePrincipal');
        $this->ouvrirLaFiche($villeurbanne)->callAction('retirerCommePrincipal');
        $this->assertSame(0, Magasin::query()->where('societe_id', $lyon->societe_id)->where('est_etablissement_principal', true)->count());
    }

    /**
     * La fiche d'un magasin d'une autre société, ou hors de la portée de
     * l'administrateur, n'existe pas pour lui : 404, sans rien en dire.
     */
    public function test_le_magasin_d_une_autre_societe_repond_404(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsSelection = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors sélection']);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Boutique ailleurs']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]);

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $this->get(MagasinResource::getUrl('edit', ['record' => $ailleurs, 'tenant' => $magasin->societe]))->assertNotFound();
        $this->get(MagasinResource::getUrl('edit', ['record' => $magasin, 'tenant' => $magasin->societe]))->assertOk();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->get(MagasinResource::getUrl('edit', ['record' => $horsSelection, 'tenant' => $magasin->societe]))->assertNotFound();
        $this->get(MagasinResource::getUrl('edit', ['record' => $magasin, 'tenant' => $magasin->societe]))->assertOk();
    }

    /**
     * La fiche ne s'ouvre qu'à qui gère les magasins de sa portée, tant que
     * la société n'est pas en lecture seule ; la liste ne propose « Gérer »
     * qu'à lui.
     */
    public function test_seul_qui_gere_les_magasins_ouvre_la_fiche_d_un_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $sansLAutorisation = $this->administrateur($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $sansLAutorisation);
        Livewire::test(ListMagasins::class)->assertTableActionHidden('edit', $magasin);
        Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])->assertForbidden();

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(ListMagasins::class)->assertTableActionVisible('edit', $magasin);
        $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $this->dansLEspaceDeLaSociete($magasin->societe->fresh(), $this->proprietaireDe($magasin));
        Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()])->assertForbidden();
    }

    /**
     * Les gestes du cycle de vie ne s'offrent que selon le compte et l'état
     * du magasin : fermer un magasin ouvert ; le céder, ouvert ou déjà fermé
     * (réponse M1 de l'éditeur), pour le seul propriétaire ; rouvrir un
     * magasin fermé, jamais un magasin cédé, dont la fiche ne s'enregistre
     * plus. Fermé depuis la fiche, le magasin le dit dans son état.
     */
    public function test_les_gestes_du_cycle_de_vie_s_offrent_selon_le_compte_et_l_etat_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $cede = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique cédée']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);

        $this->ouvrirLaFiche($magasin, $administrateur)
            ->assertActionVisible('fermer')
            ->assertActionHidden('ceder')
            ->assertActionHidden('rouvrir');
        $this->ouvrirLaFiche($magasin)
            ->assertActionVisible('ceder')
            ->callAction('fermer', ['motif' => MotifFermeture::Temporaire->value, 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertNotified('Le magasin Ma Boutique est fermé.')
            ->assertActionHidden('fermer')
            ->assertActionVisible('ceder')
            ->assertActionVisible('rouvrir')
            ->assertSeeText('Fermé temporairement');
        $this->ouvrirLaFiche($magasin, $administrateur)
            ->assertActionHidden('ceder')
            ->assertActionVisible('rouvrir');
        $this->ouvrirLaFiche($cede)
            ->callAction('ceder', ['confirmation' => 'Boutique cédée', 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertActionHidden('rouvrir')
            ->assertActionHidden('changerLeSiret')
            ->assertActionHidden('save')
            ->assertFormFieldIsDisabled('nom')
            ->assertSeeText('Cédé');

        $this->assertSame([StatutMagasin::Ferme, true], [$magasin->fresh()->statut, $cede->fresh()->aEteCede()]);
        $this->ouvrirLaFiche($magasin)
            ->callAction('rouvrir', ['mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertNotified('Le magasin Ma Boutique est rouvert.');
        $this->assertSame(StatutMagasin::Actif, $magasin->fresh()->statut);
    }

    /**
     * Un magasin fermé se cède depuis sa fiche, sans être rouvert : la
     * fenêtre le dit, le magasin passe à « Cédé », et ni la réouverture ni
     * une nouvelle cession ne s'offrent plus.
     */
    public function test_un_magasin_ferme_se_cede_depuis_sa_fiche(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->ouvrirLaFiche($magasin)->callAction('fermer', ['motif' => MotifFermeture::Cessation->value, 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE]);

        $this->ouvrirLaFiche($magasin)
            ->mountAction('ceder')
            ->assertSeeText('le magasin, déjà fermé, ne se rouvrira plus, et il n\'est ni rouvert ni recontrôlé au répertoire Sirene pour être cédé.')
            ->setActionData(['confirmation' => 'Ma Boutique', 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->callMountedAction()
            ->assertNotified('Le magasin Ma Boutique est cédé : la valeur de son stock sorti vous est remise dans vos notifications.')
            ->assertActionHidden('ceder')
            ->assertActionHidden('rouvrir')
            ->assertSeeText('Cédé');

        $this->assertSame(MotifFermeture::Cession, $magasin->fresh()->motif_fermeture);
    }

    /**
     * Avant la validation de la société, la fiche ne propose jamais de céder
     * le magasin ; fermé, elle propose de le rouvrir (réponse M2 de
     * l'éditeur), et le dit : il ne vendra qu'une fois la société validée.
     */
    public function test_avant_la_validation_la_fiche_rouvre_un_magasin_ferme_sans_jamais_proposer_de_le_ceder(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);

        $this->ouvrirLaFiche($magasin)
            ->assertActionHidden('ceder')
            ->callAction('fermer', ['motif' => MotifFermeture::Temporaire->value, 'mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertActionHidden('ceder')
            ->mountAction('rouvrir')
            ->assertSeeText('Votre société n\'étant pas encore validée, le magasin rouvert ne vend qu\'après sa validation, qui le met en activité avec vos autres magasins ouverts.')
            ->setActionData(['mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->callMountedAction()
            ->assertNotified('Le magasin Ma Boutique est rouvert : il vendra une fois votre société validée.')
            ->assertActionHidden('rouvrir');

        $this->assertSame(StatutMagasin::Actif, $magasin->fresh()->statut);
    }

    /**
     * La fiche du magasin, ouverte dans l'espace de sa société par ce
     * compte, le propriétaire à défaut.
     */
    private function ouvrirLaFiche(Magasin $magasin, ?User $compte = null): Testable
    {
        $this->dansLEspaceDeLaSociete($magasin->societe()->firstOrFail(), $compte ?? $this->proprietaireDe($magasin));

        return Livewire::test(ModifierMagasin::class, ['record' => $magasin->getRouteKey()]);
    }

    /**
     * Le premier devis du magasin, numéroté par son propriétaire : son code
     * figure désormais dans un numéro.
     */
    private function numeroterUnPremierDevis(Magasin $magasin): void
    {
        app(TenantContext::class)->runAs($magasin, function () use ($magasin): void {
            $proprietaire = $this->proprietaireDe($magasin);
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
            $devis = app(CreerDevisAction::class)->handle($proprietaire, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
            app(EmettreDevisAction::class)->handle($proprietaire, $devis);
        });
    }

    /**
     * Un ticket de 12,00 € TTC au comptoir du magasin, vendu par son
     * responsable de test : le propriétaire ne vend jamais.
     */
    private function vendre(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin->fresh(), fn (): Facture => self::vendreAuComptant(
            self::responsableDeTest($magasin),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]],
            ModePaiement::Especes,
        ));
    }

    /**
     * Les changements de SIRET que le journal des événements du magasin a
     * inscrits (JET 270), dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function changementsDeSiret(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', '270')
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->filter(fn (array $details): bool => $details['parametre'] === 'siret')
            ->values()
            ->all());
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
