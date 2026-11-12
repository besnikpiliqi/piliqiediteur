<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use Closure;
use Database\Factories\SocieteFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les gardes par lesquelles la base elle-même tient l'espace des cabinets
 * d'expertise comptable (voir GardesDesCabinets), même écrit par
 * DB::table() : chacun de leurs refus, et les transitions permises.
 */
class GardesDesCabinetsEnBaseTest extends TestCase
{
    use RefreshDatabase;

    private const GARDES = [GardesDesCabinets::CABINETS, GardesDesCabinets::INVITATIONS, GardesDesCabinets::LIENS, GardesDesCabinets::AUTORISATIONS, GardesDesCabinets::CLIENTS_CONFIES];

    /**
     * Les gardes telles que les migrations les ont posées, lues avant que
     * setUp() ne les repose.
     *
     * @var array<string, array{declencheurs: list<string>, fonction: string|null}>
     */
    private array $gardesDesMigrations = [];

    /**
     * Chaque test lit les gardes telles que les migrations les ont posées,
     * puis les repose par leur code (GardesDesCabinets::retirer() puis
     * poser(), qu'appellent leurs migrations), dans sa transaction : la
     * couverture relie ainsi ce code aux tests qui vérifient ce que les
     * gardes refusent, et les tests de mutation l'éprouvent par eux (voir
     * le README).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->gardesDesMigrations = $this->definitionsDesGardes(self::GARDES);

        foreach (self::GARDES as $garde) {
            GardesDesCabinets::retirer($garde);
            GardesDesCabinets::poser($garde);
        }
    }

    /**
     * Les migrations posent chaque garde, son déclencheur sur sa table et
     * sa fonction, telle que poser() l'écrit : ce que les autres tests de
     * ce fichier vérifient des gardes reposées vaut donc pour celles de la
     * base. Une migration qui ne pose pas sa garde, ou la pose autrement,
     * fait échouer ce test.
     */
    public function test_les_migrations_posent_chaque_garde_telle_que_poser_l_ecrit(): void
    {
        $reposees = $this->definitionsDesGardes(self::GARDES);

        $this->assertSame($reposees, $this->gardesDesMigrations);
        $this->assertNotContains([], array_column($reposees, 'declencheurs'));
        $this->assertNotContains(null, array_column($reposees, 'fonction'));
    }

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesQuiNeSontPasDesCabinets(): array
    {
        return ['commerce' => [TypeDActivite::Commerce], 'services' => [TypeDActivite::Services]];
    }

    #[DataProvider('typesQuiNeSontPasDesCabinets')]
    public function test_la_base_refuse_une_ligne_de_cabinet_pour_une_societe_qui_n_est_pas_un_cabinet(TypeDActivite $type): void
    {
        $societe = $this->ouvrirUneSociete(['nom' => 'Boutique', 'type' => $type])->societe;

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('cabinets')->insert(['societe_id' => $societe->id, 'created_at' => now(), 'updated_at' => now()])), QueryException::class, self::refus(GardesDesCabinets::CABINETS, 'seul un cabinet d\'expertise comptable a une vérification de cabinet'));
    }

    public function test_la_base_refuse_un_lien_dont_le_cabinet_n_est_pas_un_cabinet_ou_dont_le_client_est_un_cabinet_ou_vers_soi(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $client = $this->ouvrirUneSociete(['nom' => 'Cliente'])->societe;

        foreach ([
            [$client->id, $client->id, 'un lien ne se fait qu\'avec un cabinet d\'expertise comptable'],
            [$autreCabinet->id, $cabinet->id, 'un cabinet d\'expertise comptable n\'est jamais le client d\'un cabinet'],
            [$cabinet->id, $cabinet->id, 'un cabinet d\'expertise comptable n\'est jamais le client d\'un cabinet'],
        ] as [$societeId, $cabinetId, $raison]) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert($this->lien($societeId, $cabinetId))), QueryException::class, self::refus(GardesDesCabinets::LIENS, $raison));
        }

        foreach ([$this->lien($client->id, $cabinet->id), [...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']] as $lien) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$lien, 'cabinet_id' => null])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'seule une déclaration du client, en attente ou terminée sans rattachement, n\'a pas de cabinet'));
        }
    }

    public function test_la_base_refuse_un_jeton_de_rattachement_sur_un_lien_qui_n_est_pas_une_demande_du_cabinet_en_cours(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();

        $jeton = self::refus(GardesDesCabinets::LIENS, 'le jeton d\'un lien ne vaut que pour une demande de rattachement en cours');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'demande_par_le_client', 'jeton_hache' => str_repeat('a', 64)])), QueryException::class, $jeton);
        $id = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'jeton_hache' => str_repeat('b', 64)]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->update(['etat' => 'refuse'])), QueryException::class, $jeton);
    }

    public function test_la_base_refuse_une_transition_de_lien_non_permise_et_la_suppression_d_un_lien(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $id = DB::table('liens_cabinet')->insertGetId($this->lien($client->id, $cabinet->id));
        DB::table('liens_cabinet')->where('id', $id)->update(['etat' => 'refuse']);

        $naissance = self::refus(GardesDesCabinets::LIENS, 'un lien naît d\'une demande, d\'une déclaration du client, ou actif par une invitation acceptée');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->update(['etat' => 'actif'])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'transition du lien non permise'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'termine'])), QueryException::class, $naissance);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'demande_du_cabinet'])), QueryException::class, $naissance);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->update(['origine' => 'invitation'])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'le client, l\'origine et le SIREN déclaré d\'un lien ne changent jamais'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $id)->delete()), QueryException::class, 'liens_cabinet : suppression définitive refusée');

        // Une déclaration de l'expert-comptable de la société ne devient
        // jamais refusée, retirée ni expirée, et un lien actif ne redevient
        // jamais une déclaration.
        $declaration = DB::table('liens_cabinet')->insertGetId($this->declaration($client->id));

        foreach (['refuse', 'retire', 'expire'] as $etat) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => $etat])), QueryException::class, GardesDesCabinets::LIENS);
        }

        DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'termine', 'termine_le' => now()]);
        $actif = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $actif)->update(['etat' => 'declare'])), QueryException::class, GardesDesCabinets::LIENS);
        $this->assertSame(3, DB::table('liens_cabinet')->count());
    }

    /**
     * La table, sa colonne qui désigne le cabinet, la colonne écrite et sa
     * valeur, qui rendent le cabinet non vérifié.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function cabinetsNonVerifies(): array
    {
        return [
            'suspendu' => ['societes', 'id', 'statut', 'suspendue'],
            'résilié' => ['societes', 'id', 'statut', 'resiliee'],
            'non validé' => ['societes', 'id', 'validee_le', null],
            'activité non contrôlée' => ['cabinets', 'societe_id', 'activite_controlee_le', null],
            'radié de l\'Ordre' => ['cabinets', 'societe_id', 'ordre_retire_le', '2026-11-01 10:00:00+00'],
        ];
    }

    /**
     * Un lien ne naît ni ne devient actif avec un cabinet qui n'est pas
     * vérifié : suspendu ou résilié (décision de l'éditeur du 11/11/2026 :
     * ses liens restent bloqués), non validé, son activité non contrôlée
     * ou radié de l'Ordre ; une demande reste alors une demande.
     */
    #[DataProvider('cabinetsNonVerifies')]
    public function test_la_base_refuse_qu_un_lien_naisse_ou_devienne_actif_avec_un_cabinet_non_verifie(string $table, string $cle, string $colonne, ?string $valeur): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $demande = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'jeton_hache' => str_repeat('d', 64)]);
        DB::table($table)->where($cle, $cabinet->id)->update([$colonne => $valeur]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation'])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'un lien ne naît actif qu\'avec un cabinet vérifié, actif et vérifié à l\'Ordre'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $demande)->update(['etat' => 'actif', 'jeton_hache' => null])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'un lien ne devient actif qu\'avec un cabinet vérifié, actif et vérifié à l\'Ordre'));
        $this->assertSame(['demande_par_le_cabinet'], DB::table('liens_cabinet')->pluck('etat')->all());
    }

    /**
     * Un client n'a qu'un lien en cours (règle réécrite par la décision de
     * l'éditeur du 01/11/2026 sur l'expert-comptable de la société) : son
     * cabinet lié ou son expert-comptable déclaré, jamais deux, quel que
     * soit l'ordre.
     */
    public function test_la_base_refuse_un_second_lien_en_cours_actif_ou_declare_pour_un_client(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $declaration = [...$this->lien($client->id, $cabinet->id), 'cabinet_id' => null, 'etat' => 'declare', 'origine' => 'declaration_du_client', 'siren_cabinet' => '000000927', 'expire_le' => null];
        DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $autreCabinet->id), 'etat' => 'actif', 'origine' => 'invitation'])), QueryException::class, 'liens_cabinet_un_cabinet_par_client');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert($declaration)), QueryException::class, 'liens_cabinet_un_cabinet_par_client');

        $autreClient = $this->ouvrirUneSociete(['nom' => 'Autre cliente', 'siren' => SocieteFactory::sirenFictif()])->societe;
        DB::table('liens_cabinet')->insert([...$declaration, 'uuid' => (string) Str::uuid(), 'societe_id' => $autreClient->id]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$declaration, 'uuid' => (string) Str::uuid(), 'societe_id' => $autreClient->id, 'siren_cabinet' => '000000935'])), QueryException::class, 'liens_cabinet_un_cabinet_par_client');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($autreClient->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation'])), QueryException::class, 'liens_cabinet_un_cabinet_par_client');
    }

    /**
     * Une autorisation ne s'accorde que sur un lien actif ou une
     * déclaration en attente de l'expert-comptable de la société (règle
     * élargie par la décision de l'éditeur du 01/11/2026), jamais sur une
     * demande ni sur une déclaration terminée.
     */
    public function test_la_base_refuse_une_autorisation_hors_du_cabinet_ou_sur_un_lien_ni_actif_ni_declare(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $actif = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre'])->societe;
        $demande = DB::table('liens_cabinet')->insertGetId($this->lien($autre->id, $cabinet->id));
        $refusHorsDuLien = self::refus(GardesDesCabinets::AUTORISATIONS, 'une autorisation ne s\'accorde que sur un lien actif ou une déclaration en attente');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $client->id, 'lien_id' => $actif, 'autorisation' => PermissionRegistry::FACTURE_CREATE])), QueryException::class, self::refus(GardesDesCabinets::AUTORISATIONS, 'une autorisation hors de celles d\'un cabinet lié ne s\'accorde jamais'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $autre->id, 'lien_id' => $demande, 'autorisation' => PermissionRegistry::FACTURE_VIEW])), QueryException::class, $refusHorsDuLien);
        DB::table('autorisations_cabinet')->insert(['societe_id' => $client->id, 'lien_id' => $actif, 'autorisation' => PermissionRegistry::CLIENT_VIEW]);

        $declaration = DB::table('liens_cabinet')->insertGetId($this->declaration($autre->id));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $autre->id, 'lien_id' => $declaration, 'autorisation' => PermissionRegistry::FACTURE_CREATE])), QueryException::class, self::refus(GardesDesCabinets::AUTORISATIONS, 'une autorisation hors de celles d\'un cabinet lié ne s\'accorde jamais'));
        DB::table('autorisations_cabinet')->insert(['societe_id' => $autre->id, 'lien_id' => $declaration, 'autorisation' => PermissionRegistry::CLIENT_VIEW]);
        DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'termine', 'termine_le' => now()]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $autre->id, 'lien_id' => $declaration, 'autorisation' => PermissionRegistry::FOURNISSEUR_VIEW])), QueryException::class, $refusHorsDuLien);
        $this->assertSame(2, DB::table('autorisations_cabinet')->count());
    }

    /**
     * La base refuse, à la validation de la transaction, « voir » les
     * factures ou les clôtures de caisse d'un cabinet sans « voir celles de
     * toute l'équipe », ou l'inverse, qu'une ligne s'écrive ou s'efface :
     * un cabinet les voit toutes ou aucune (décision de l'éditeur du
     * 01/11/2026). La migration pose cette garde différée.
     */
    public function test_la_base_refuse_une_paire_incomplete_des_autorisations_d_un_cabinet(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $lien = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $ligne = fn (string $autorisation): array => ['societe_id' => $client->id, 'lien_id' => $lien, 'autorisation' => $autorisation];
        $aLaValidation = fn (Closure $ecriture): Closure => $this->dansUnPointDeSauvegarde(function () use ($ecriture): void {
            $ecriture();
            DB::statement('SET CONSTRAINTS '.GardesDesCabinets::PAIRES.' IMMEDIATE');
        });
        $refus = self::refus(GardesDesCabinets::PAIRES, 'voir les factures ou les clôtures de caisse s\'accorde à un cabinet avec celles de toute l\'équipe, jamais l\'un sans l\'autre');

        $this->assertSame(1, DB::table('pg_trigger')->where('tgname', GardesDesCabinets::PAIRES)->count());
        foreach ([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES] as $seule) {
            $this->assertThrows($aLaValidation(fn () => DB::table('autorisations_cabinet')->insert($ligne($seule))), QueryException::class, $refus);
            DB::statement('SET CONSTRAINTS '.GardesDesCabinets::PAIRES.' DEFERRED');
        }

        $aLaValidation(fn () => DB::table('autorisations_cabinet')->insert([$ligne(PermissionRegistry::FACTURE_VIEW), $ligne(PermissionRegistry::FACTURE_VIEW_TOUTES)]))();
        $this->assertThrows($aLaValidation(fn () => DB::table('autorisations_cabinet')->where('autorisation', PermissionRegistry::FACTURE_VIEW_TOUTES)->delete()), QueryException::class, $refus);
        DB::statement('SET CONSTRAINTS '.GardesDesCabinets::PAIRES.' DEFERRED');
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES], DB::table('autorisations_cabinet')->orderBy('autorisation')->pluck('autorisation')->all());
    }

    public function test_la_base_refuse_une_autorisation_d_articles_pour_un_client_sans_articles(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $client = $this->ouvrirUneSociete(['nom' => 'Atelier', 'type' => TypeDActivite::Services])->societe;
        $lien = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);

        foreach (array_intersect(PermissionRegistry::duCabinet(TypeDActivite::Commerce), PermissionRegistry::articles()) as $autorisation) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_cabinet')->insert(['societe_id' => $client->id, 'lien_id' => $lien, 'autorisation' => $autorisation])), QueryException::class, self::refus(GardesDesCabinets::AUTORISATIONS, 'une autorisation des articles ne s\'accorde pas chez un client sans articles'));
        }
    }

    public function test_la_base_refuse_de_confier_un_client_a_un_compte_qui_n_est_pas_collaborateur_ou_un_lien_inactif(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $actif = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre'])->societe;
        $demande = DB::table('liens_cabinet')->insertGetId($this->lien($autre->id, $cabinet->id));
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $collaborateur = $this->collaborateur($cabinet);
        $ligne = fn (int $lien, int $compte): array => ['societe_id' => $cabinet->id, 'lien_id' => $lien, 'user_id' => $compte, 'confie_par' => $proprietaire->id, 'confie_par_nom' => 'Claire Martin', 'created_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clients_confies')->insert($ligne($actif, $proprietaire->id))), QueryException::class, self::refus(GardesDesCabinets::CLIENTS_CONFIES, 'un client ne se confie qu\'à un collaborateur du cabinet'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('clients_confies')->insert($ligne($demande, $collaborateur->id))), QueryException::class, self::refus(GardesDesCabinets::CLIENTS_CONFIES, 'seul un client lié se confie'));
        DB::table('clients_confies')->insert($ligne($actif, $collaborateur->id));
        $this->assertSame(1, DB::table('clients_confies')->count());
    }

    public function test_la_base_refuse_une_invitation_d_une_societe_qui_n_est_pas_un_cabinet_ou_dont_la_date_de_fin_contredit_l_etat(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $proprietaireClient = $client->proprietaire()->sole();
        $invitation = fn (int $societe, int $auteur): array => ['uuid' => (string) Str::uuid(), 'societe_id' => $societe, 'siren' => '552100554', 'email' => 'a@b.test', 'jeton_hache' => Str::random(64), 'invitee_par' => $auteur, 'invitee_par_nom' => 'x', 'etat' => 'ouverte', 'expire_le' => now(), 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('invitations_cabinet')->insert($invitation($client->id, $proprietaireClient->id))), QueryException::class, self::refus(GardesDesCabinets::INVITATIONS, 'seul un cabinet d\'expertise comptable invite une entreprise'));
        $id = DB::table('invitations_cabinet')->insertGetId($invitation($cabinet->id, $this->proprietaireDuCabinet($cabinet)->id));
        $sansDateOuDateSansFin = self::refus(GardesDesCabinets::INVITATIONS, 'une invitation close a toujours sa date de fin, une invitation ouverte n\'en a pas');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('invitations_cabinet')->where('id', $id)->update(['etat' => 'expiree'])), QueryException::class, $sansDateOuDateSansFin);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('invitations_cabinet')->where('id', $id)->update(['close_le' => now()])), QueryException::class, $sansDateOuDateSansFin);
        DB::table('invitations_cabinet')->where('id', $id)->update(['etat' => 'expiree', 'close_le' => now()]);
        $this->assertSame('expiree', DB::table('invitations_cabinet')->value('etat'));
    }

    public function test_les_transitions_permises_passent(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $acceptee = DB::table('liens_cabinet')->insertGetId([...$this->lien($client->id, $cabinet->id), 'jeton_hache' => str_repeat('c', 64)]);
        DB::table('liens_cabinet')->where('id', $acceptee)->update(['etat' => 'actif', 'jeton_hache' => null]);
        DB::table('liens_cabinet')->where('id', $acceptee)->update(['etat' => 'termine']);

        foreach (['refuse', 'retire', 'expire'] as $issue) {
            $id = DB::table('liens_cabinet')->insertGetId($this->lien($client->id, $cabinet->id));
            DB::table('liens_cabinet')->where('id', $id)->update(['etat' => $issue]);
        }

        $this->assertSame(['termine', 'refuse', 'retire', 'expire'], DB::table('liens_cabinet')->orderBy('id')->pluck('etat')->all());

        // Une déclaration de l'expert-comptable de la société se termine,
        // ou se rattache au cabinet vérifié de son SIREN.
        $autreClient = $this->ouvrirUneSociete(['nom' => 'Autre cliente', 'siren' => SocieteFactory::sirenFictif()])->societe;
        $terminee = DB::table('liens_cabinet')->insertGetId($this->declaration($autreClient->id));
        DB::table('liens_cabinet')->where('id', $terminee)->update(['etat' => 'termine', 'termine_le' => now()]);
        $rattachee = DB::table('liens_cabinet')->insertGetId([...$this->declaration($autreClient->id), 'siren_cabinet' => $cabinet->siren]);
        DB::table('liens_cabinet')->where('id', $rattachee)->update(['etat' => 'actif', 'cabinet_id' => $cabinet->id, 'actif_depuis' => now()]);

        $this->assertSame([['termine', null], ['actif', $cabinet->id]], DB::table('liens_cabinet')->whereIn('id', [$terminee, $rattachee])->orderBy('id')->get(['etat', 'cabinet_id'])->map(fn (object $lien): array => [$lien->etat, $lien->cabinet_id])->all());
    }

    /**
     * Le message d'un refus d'une garde, tel que la base le donne.
     */
    public function test_la_base_refuse_une_declaration_avec_un_cabinet_sans_siren_ou_vers_son_propre_siren(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $declaration = $this->declaration($client->id);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$declaration, 'cabinet_id' => $cabinet->id])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'une déclaration en attente n\'a pas encore de cabinet'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$declaration, 'siren_cabinet' => null])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'seule une déclaration du client porte le SIREN de son cabinet'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$declaration, 'siren_cabinet' => $client->siren])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'une société ne déclare pas son propre SIREN'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'cabinet_id' => null])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'seule une déclaration du client, en attente ou terminée sans rattachement, n\'a pas de cabinet'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$declaration, 'etat' => 'actif', 'cabinet_id' => $cabinet->id])), QueryException::class, GardesDesCabinets::LIENS);

        DB::table('liens_cabinet')->insert($declaration);
        $this->assertSame(1, DB::table('liens_cabinet')->where('etat', 'declare')->count());
    }

    public function test_la_base_refuse_une_declaration_dans_un_cabinet(): void
    {
        [$cabinet] = $this->cabinetEtClient();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert($this->declaration($cabinet->id))), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'un cabinet d\'expertise comptable n\'est jamais le client d\'un cabinet'));
    }

    public function test_la_base_refuse_une_declaration_dans_une_societe_qui_attend_son_code(): void
    {
        $enAttente = $this->inscrireUneSociete();

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert($this->declaration($enAttente->id))), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'seule une société validée déclare son expert-comptable'));
    }

    public function test_la_base_refuse_qu_une_declaration_prenne_fin_ou_se_rattache_avec_un_compte_d_expert_comptable_ouvert(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);
        $declaration = DB::table('liens_cabinet')->where('etat', 'declare')->value('id');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'termine', 'termine_le' => now()])), QueryException::class, self::refus(GardesDesCabinets::DECLARATION_SANS_COMPTE_OUVERT, 'les comptes de l\'expert-comptable de la société se retirent avant que sa déclaration prenne fin ou se rattache'));
    }

    public function test_la_base_refuse_un_compte_attache_qui_n_est_pas_expert_comptable_ou_a_une_declaration_terminee(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->declarerSonExpertComptable($magasin);
        $declaration = DB::table('liens_cabinet')->where('etat', 'declare')->value('id');
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $ligne = ['societe_id' => $magasin->societe_id, 'lien_id' => $declaration, 'created_at' => now(), 'updated_at' => now()];

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('comptes_expert_comptable')->insert([...$ligne, 'user_id' => $vendeur->id])), QueryException::class, self::refus(GardesDesCabinets::COMPTES_EXPERT_COMPTABLE, 'seul un compte d\'expert-comptable de la société s\'attache à une déclaration'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('comptes_expert_comptable')->where('user_id', $expert->id)->update(['lien_id' => $declaration + 1000])), QueryException::class);
    }

    public function test_la_base_refuse_qu_un_compte_prenne_ou_quitte_le_role_d_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->declarerSonExpertComptable($magasin);
        $administrateur = $this->administrateur($magasin);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $expert->id)->update(['role_societe' => 'administrateur'])), QueryException::class, self::refus(GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION, 'le rôle d\'expert-comptable de la société ne se prend ni ne se quitte'));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $administrateur->id)->update(['role_societe' => 'expert_comptable', 'email' => 'x@example.test'])), QueryException::class, self::refus(GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION, 'le rôle d\'expert-comptable de la société ne se prend ni ne se quitte'));
    }

    public function test_la_base_refuse_un_expert_comptable_ouvert_sans_adresse_mais_pas_un_compte_retire(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->declarerSonExpertComptable($magasin);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $expert->id)->update(['email' => null])), QueryException::class, self::refus(GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION, 'un expert-comptable de la société a une adresse tant que son compte n\'est pas retiré, où partent les liens de son mot de passe'));

        DB::table('users')->where('id', $expert->id)->update(['statut' => 'retire', 'email' => null]);
        $this->assertNull(DB::table('users')->where('id', $expert->id)->value('email'));
    }

    public function test_la_base_refuse_un_lien_du_mot_de_passe_sans_echeance_ou_hors_d_une_declaration_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->declarerSonExpertComptable($magasin);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('comptes_expert_comptable')->where('user_id', $expert->id)->update(['jeton_expire_le' => null])), QueryException::class, self::refus(GardesDesCabinets::COMPTES_EXPERT_COMPTABLE, 'un lien du mot de passe a toujours son échéance'));
    }

    public function test_la_base_refuse_d_effacer_ou_de_changer_la_date_du_premier_mot_de_passe_choisi(): void
    {
        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->expertComptable($magasin);

        foreach ([null, self::aParis('2026-11-06 10:00:00')] as $date) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('comptes_expert_comptable')->where('user_id', $expert->id)->update(['mot_de_passe_choisi_le' => $date])), QueryException::class, self::refus(GardesDesCabinets::COMPTES_EXPERT_COMPTABLE, 'la date du premier mot de passe choisi ne change plus'));
        }
    }

    public function test_la_base_refuse_la_suppression_d_un_compte_attache_a_une_declaration(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->declarerSonExpertComptable($magasin);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('comptes_expert_comptable')->where('user_id', $expert->id)->delete()), QueryException::class);
        $this->assertSame(1, DB::table('comptes_expert_comptable')->count());
    }

    public function test_la_base_refuse_une_declaration_a_cote_d_un_lien_actif_ou_d_une_autre_declaration(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        DB::table('liens_cabinet')->insert([...$this->lien($client->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert($this->declaration($client->id))), QueryException::class, 'liens_cabinet_un_cabinet_par_client');

        $autreClient = $this->ouvrirUneSociete(['nom' => 'Autre cliente', 'siren' => SocieteFactory::sirenFictif()])->societe;
        DB::table('liens_cabinet')->insert($this->declaration($autreClient->id));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->insert([...$this->declaration($autreClient->id), 'siren_cabinet' => '000000935'])), QueryException::class, 'liens_cabinet_un_cabinet_par_client');

        // Une demande de lien en cours n'est pas un lien en cours : elle
        // côtoie la déclaration.
        DB::table('liens_cabinet')->insert($this->lien($autreClient->id, $cabinet->id));
        $this->assertSame(['declare', 'demande_par_le_cabinet'], DB::table('liens_cabinet')->where('societe_id', $autreClient->id)->orderBy('id')->pluck('etat')->all());
    }

    public function test_la_base_refuse_de_rattacher_une_declaration_a_un_cabinet_d_un_autre_siren(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $declaration = DB::table('liens_cabinet')->insertGetId($this->declaration($client->id));

        $this->assertNotSame('000000927', $cabinet->siren);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'actif', 'cabinet_id' => $cabinet->id, 'actif_depuis' => now()])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'une déclaration ne se rattache qu\'au cabinet de son SIREN'));
        $this->assertSame(['declare', null], [DB::table('liens_cabinet')->where('id', $declaration)->value('etat'), DB::table('liens_cabinet')->where('id', $declaration)->value('cabinet_id')]);
    }

    /**
     * Une déclaration ne se rattache qu'à un cabinet entièrement vérifié :
     * jamais à une inscription qui attend son code, à un cabinet dont
     * l'inscription à l'Ordre n'est pas vérifiée, radié ou suspendu.
     */
    #[DataProvider('cabinetsDuSirenNonVerifies')]
    public function test_la_base_refuse_de_rattacher_une_declaration_a_un_cabinet_non_verifie(string $table, string $cle, string $colonne, ?string $valeur): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $declaration = DB::table('liens_cabinet')->insertGetId([...$this->declaration($client->id), 'siren_cabinet' => $cabinet->siren]);
        DB::table($table)->where($cle, $cabinet->id)->update([$colonne => $valeur]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'actif', 'cabinet_id' => $cabinet->id, 'actif_depuis' => now()])), QueryException::class, self::refus(GardesDesCabinets::LIENS, 'un lien ne devient actif qu\'avec un cabinet vérifié, actif et vérifié à l\'Ordre'));
        $this->assertSame('declare', DB::table('liens_cabinet')->where('id', $declaration)->value('etat'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string|null}>
     */
    public static function cabinetsDuSirenNonVerifies(): array
    {
        return [
            'inscription qui attend son code' => ['societes', 'id', 'validee_le', null],
            'Ordre non vérifié' => ['cabinets', 'societe_id', 'ordre_verifie_le', null],
            'cabinet radié' => ['cabinets', 'societe_id', 'ordre_retire_le', '2026-11-01 10:00:00+00'],
            'cabinet suspendu' => ['societes', 'id', 'statut', 'suspendue'],
        ];
    }

    /**
     * La comparaison du SIREN déclaré à celui de la société ne vaut qu'à
     * l'insertion : une correction du SIREN du client par la procédure de
     * l'éditeur, qui le rend égal au SIREN déclaré, ne bloque pas la fin
     * de la déclaration.
     */
    public function test_une_correction_du_siren_du_client_ne_bloque_pas_la_fin_de_sa_declaration(): void
    {
        $client = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($client);
        $declaration = DB::table('liens_cabinet')->insertGetId($this->declaration($client->id));
        app(RepertoireSirene::class)->exercer('000000927', '47.11B');
        $corrigee = $this->corrigerLeSirenParLEditeur($client, '000000927');
        $this->assertSame('000000927', $corrigee->siren);

        DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'termine', 'termine_le' => now()]);

        $this->assertSame('termine', DB::table('liens_cabinet')->where('id', $declaration)->value('etat'));
    }

    public function test_la_base_refuse_de_changer_le_siren_declare_l_origine_ou_le_cabinet_d_un_lien(): void
    {
        [$cabinet, $client] = $this->cabinetEtClient();
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux');
        $declaration = DB::table('liens_cabinet')->insertGetId($this->declaration($client->id));
        $fixe = self::refus(GardesDesCabinets::LIENS, 'le client, l\'origine et le SIREN déclaré d\'un lien ne changent jamais');
        $cabinetFixe = self::refus(GardesDesCabinets::LIENS, 'le cabinet d\'un lien ne change jamais ; celui d\'une déclaration ne se pose qu\'à son rattachement');

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['siren_cabinet' => '000000935'])), QueryException::class, $fixe);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['origine' => 'invitation', 'siren_cabinet' => null])), QueryException::class, GardesDesCabinets::LIENS);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'termine', 'termine_le' => now(), 'cabinet_id' => $cabinet->id])), QueryException::class, $cabinetFixe);

        $autreClient = $this->ouvrirUneSociete(['nom' => 'Autre cliente', 'siren' => SocieteFactory::sirenFictif()])->societe;
        $actif = DB::table('liens_cabinet')->insertGetId([...$this->lien($autreClient->id, $cabinet->id), 'etat' => 'actif', 'origine' => 'invitation']);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $actif)->update(['cabinet_id' => $autreCabinet->id])), QueryException::class, $cabinetFixe);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $actif)->update(['origine' => 'demande_du_cabinet'])), QueryException::class, $fixe);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('liens_cabinet')->where('id', $actif)->update(['siren_cabinet' => $cabinet->siren])), QueryException::class, GardesDesCabinets::LIENS);

        $this->assertSame([['000000927', null], [null, $cabinet->id]], DB::table('liens_cabinet')->orderBy('id')->get(['siren_cabinet', 'cabinet_id'])->map(fn (object $lien): array => [$lien->siren_cabinet, $lien->cabinet_id])->all());
    }

    public function test_la_base_refuse_de_rouvrir_un_compte_d_expert_comptable_dont_la_declaration_n_est_plus_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $expert = $this->declarerSonExpertComptable($magasin);
        $declaration = DB::table('liens_cabinet')->where('etat', 'declare')->value('id');
        DB::table('comptes_expert_comptable')->where('user_id', $expert->id)->update(['jeton_hache' => null, 'jeton_expire_le' => null]);
        DB::table('users')->where('id', $expert->id)->update(['statut' => 'retire']);
        DB::table('liens_cabinet')->where('id', $declaration)->update(['etat' => 'termine', 'termine_le' => now()]);

        foreach (['actif', 'suspendu'] as $statut) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $expert->id)->update(['statut' => $statut])), QueryException::class, self::refus(GardesDesCabinets::EXPERT_COMPTABLE_DE_SA_DECLARATION, 'un compte d\'expert-comptable de la société n\'est ouvert que tant que sa déclaration est en attente'));
        }

        $this->assertSame('retire', DB::table('users')->where('id', $expert->id)->value('statut'));
    }

    /**
     * Une déclaration de l'expert-comptable d'une société, en colonnes.
     *
     * @return array<string, mixed>
     */
    private function declaration(int $societeId): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'societe_id' => $societeId,
            'cabinet_id' => null,
            'siren_cabinet' => '000000927',
            'origine' => 'declaration_du_client',
            'etat' => 'declare',
            'demande_le' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private static function refus(string $garde, string $raison): string
    {
        return "{$garde} : {$raison}";
    }

    /**
     * @return array{0: Societe, 1: Societe}
     */
    private function cabinetEtClient(): array
    {
        return [$this->ouvrirUnCabinet(), $this->ouvrirUneSociete(['nom' => 'Cliente'])->societe];
    }

    /**
     * Une demande de rattachement du cabinet, en colonnes.
     *
     * @return array<string, mixed>
     */
    private function lien(int $societeId, int $cabinetId): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'societe_id' => $societeId,
            'cabinet_id' => $cabinetId,
            'origine' => 'demande_du_cabinet',
            'etat' => 'demande_par_le_cabinet',
            'demande_le' => now(),
            'expire_le' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
