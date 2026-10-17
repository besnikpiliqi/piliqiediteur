<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\VerificationSociete;
use App\Domain\Tenancy\Rules\CodeMagasinLibre;
use App\Domain\Tenancy\Rules\SiretValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\SiretsDesMagasins;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\EtablissementSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Closure;
use Database\Factories\MagasinFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La création d'un magasin, établissement d'une société (voir
 * CreerEtablissementAction) : par son propriétaire, avec le SIREN de la
 * société et le seul NIC, contrôlé tout de suite au répertoire Sirene
 * factice.
 */
class CreerEtablissementActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_cree_un_magasin_avec_le_siren_de_la_societe(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $societe = $premier->societe;
        $nic = $this->nic($societe, '0002');

        $magasin = $this->creer($premier, ['nom' => 'Lyon Part-Dieu', 'nic' => $nic]);

        $this->assertSame(
            [$societe->id, $societe->siren.$nic, 'Lyon Part-Dieu', StatutMagasin::Actif],
            [(int) $magasin->societe_id, $magasin->siret, $magasin->nom, $magasin->statut],
        );
        $this->assertNotNull($magasin->etablissement_verifie_le);
        $this->assertSame(['1 RUE DE LA REPUBLIQUE', '75001', 'PARIS'], [$magasin->adresse, $magasin->code_postal, $magasin->ville]);
        $this->assertTrue($magasin->peutVendre());
    }

    /**
     * Seuls le propriétaire et un administrateur de tous les magasins
     * autorisé à les gérer créent un magasin : ni un responsable, qui n'a
     * aucun droit de la société, ni un administrateur sans cette
     * autorisation ou d'une sélection, ni le propriétaire d'une autre
     * société ; rien n'est créé.
     */
    public function test_sans_autorisation_aucun_magasin_ne_se_cree(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $societe = $magasin->societe;
        $responsable = $this->responsable($magasin);
        $administrateurSansLAutorisation = $this->administrateur($magasin);
        $administrateurDUneSelection = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER]);
        $autreProprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ailleurs']));
        app(TenantContext::class)->clear();

        foreach ([$responsable, $administrateurSansLAutorisation, $administrateurDUneSelection, $autreProprietaire] as $auteur) {
            $this->assertThrows(
                fn () => app(CreerEtablissementAction::class)->handle($auteur, $societe, ['nom' => 'Lyon 2', 'nic' => $this->nic($societe, '0002')]),
                LogicException::class,
                CreerEtablissementAction::MESSAGE_SANS_AUTORISATION,
            );
        }

        $this->assertSame(1, $societe->magasins()->count());
    }

    /**
     * Le magasin naît toujours dans la société de l'action, quel que soit
     * l'identifiant de société que porte la requête.
     */
    public function test_un_societe_id_force_dans_la_requete_est_ignore(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $autreSociete = $this->ouvrirUneSociete(['nom' => 'Ailleurs'])->societe;

        $magasin = $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $this->nic($premier->societe, '0002'), 'societe_id' => $autreSociete->id]);

        $this->assertSame($premier->societe_id, $magasin->societe_id);
        $this->assertSame(1, $autreSociete->magasins()->count());
    }

    /**
     * @return array<string, array{0: Closure(Magasin): string}>
     */
    public static function siretsDejaPris(): array
    {
        return [
            'celui d\'un magasin fermé de la société' => [static function (Magasin $premier): string {
                $premier->forceFill(['statut' => StatutMagasin::Ferme])->save();

                return $premier->siret;
            }],
            'un ancien SIRET d\'un magasin de la société, resté réservé' => [static function (Magasin $premier): string {
                $ancien = IdentifiantsInsee::avecSaCle($premier->societe->siren.'0007');
                (new SiretMagasin)->forceFill(['societe_id' => $premier->societe_id, 'magasin_id' => $premier->id, 'siret' => $ancien, 'reserve_le' => now()->subYear(), 'retire_le' => now()])->save();

                return $ancien;
            }],
        ];
    }

    /**
     * Un SIRET déjà pris par un magasin de la société, ou réservé autrefois
     * par l'un d'eux, ne sert jamais à un autre magasin : le refus ne dit
     * pas qui l'a.
     *
     * @param  Closure(Magasin): string  $prendre
     */
    #[DataProvider('siretsDejaPris')]
    public function test_un_siret_reserve_ne_sert_jamais_a_un_autre_magasin(Closure $prendre): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $siret = $prendre($premier);

        $this->assertThrows(
            fn () => $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => substr($siret, 9)]),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [SiretsDesMagasins::MESSAGE_SIRET_RESERVE]],
        );

        $this->assertSame(1, Magasin::query()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valeursPrisesPendantLeControleSirene(): array
    {
        return [
            'code' => ['code'],
            'SIRET' => ['nic'],
        ];
    }

    /**
     * Un code ou un SIRET, libre au premier contrôle, pris par une création
     * concurrente dans la société pendant le contrôle Sirene : refusé sous
     * le verrou de la société avec le message de la validation, sans
     * erreur du serveur, et rien n'est créé ni inscrit au journal d'audit.
     */
    #[DataProvider('valeursPrisesPendantLeControleSirene')]
    public function test_une_valeur_prise_pendant_le_controle_sirene_est_refusee_comme_par_la_validation(string $champ): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $societe = $premier->societe;
        $nic = $this->nic($societe, '0005');
        $this->pendantLeControleSirene(fn () => Magasin::factory()->for($societe)->create($champ === 'code' ? ['code' => 'PARIS'] : ['siret' => $societe->siren.$nic]));
        $entrees = $this->nombreDEntreesAudit($societe);

        $this->assertThrows(
            fn () => $this->creer($premier, ['nom' => 'Paris', 'code' => 'PARIS', 'nic' => $nic]),
            fn (ValidationException $refus): bool => $refus->errors() === [$champ => [$champ === 'code' ? CodeMagasinLibre::MESSAGE_DEJA_UTILISE : SiretsDesMagasins::MESSAGE_SIRET_RESERVE]],
        );

        $this->assertSame(2, Magasin::query()->where('societe_id', $societe->id)->count());
        $this->assertSame($entrees, $this->nombreDEntreesAudit($societe));
    }

    /**
     * @return array<string, array{0: Closure(string): string, 1: string}>
     */
    public static function etablissementsRefuses(): array
    {
        return [
            'fermé' => [static fn (string $siren): string => substr(IdentifiantsInsee::avecSaCle($siren.'9001'), 9), ControleSirene::MOTIF_ETABLISSEMENT_FERME],
            'inconnu' => [static function (string $siren): string {
                $siret = IdentifiantsInsee::avecSaCle($siren.'0005');
                app(RepertoireSirene::class)->oublier($siret);

                return substr($siret, 9);
            }, ControleSirene::MOTIF_ETABLISSEMENT_INCONNU],
        ];
    }

    /**
     * Le répertoire Sirene est consulté avant toute écriture : un
     * établissement fermé ou inconnu est refusé, sans magasin, sans client
     * comptoir, sans entrée au journal d'audit.
     *
     * @param  Closure(string): string  $nic
     */
    #[DataProvider('etablissementsRefuses')]
    public function test_un_etablissement_ferme_ou_inconnu_a_l_insee_est_refuse_sans_rien_creer(Closure $nic, string $motif): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $nicRefuse = $nic($premier->societe->siren);
        $entrees = $this->nombreDEntreesAudit($premier->societe);

        $this->assertThrows(
            fn () => $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $nicRefuse]),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [$motif]],
        );

        $this->assertSame(1, Magasin::query()->count());
        $this->assertSame(1, Client::query()->withoutGlobalScopes()->count());
        $this->assertSame($entrees, $this->nombreDEntreesAudit($premier->societe));
    }

    /**
     * Les établissements de La Poste (SIREN 356000000) ne suivent pas la
     * clé de Luhn : la somme de leurs chiffres est un multiple de 5. Le NIC
     * saisi pour un magasin se contrôle ainsi.
     */
    public function test_un_siret_de_la_poste_se_controle_par_la_somme_de_ses_chiffres(): void
    {
        $proprietaire = User::factory()->for(Societe::factory()->state(['siren' => '356000000']))->create();
        $societe = $proprietaire->societe;
        app(TenantContext::class)->clear();

        $this->assertThrows(
            fn () => app(CreerEtablissementAction::class)->handle($proprietaire, $societe, ['nom' => 'Bureau de poste', 'nic' => '00002']),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [SiretValide::MESSAGE]],
        );
        $magasin = app(CreerEtablissementAction::class)->handle($proprietaire, $societe, ['nom' => 'Bureau de poste', 'nic' => '00001']);

        $this->assertSame('35600000000001', $magasin->siret);
    }

    /**
     * L'INSEE qui ne répond pas laisse créer le magasin « établissement à
     * vérifier », sans adresse : il ne vend pas avant la reprise du
     * contrôle.
     */
    public function test_l_insee_injoignable_cree_un_etablissement_a_verifier(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        app(RepertoireSirene::class)->rendreInjoignable();

        $magasin = $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $this->nic($premier->societe, '0002')]);

        $this->assertSame([null, null], [$magasin->etablissement_verifie_le, $magasin->adresse]);
        $this->assertFalse($magasin->peutVendre());
        $this->assertSame(
            ResultatVerification::Indisponible,
            app(TenantContext::class)->runAsSociete($premier->societe, fn (): ResultatVerification => VerificationSociete::query()->where('magasin_id', $magasin->id)->sole()->resultat),
        );
    }

    /**
     * Un code n'appartient qu'à un magasin de la société : pris dans la
     * société, il est refusé ; une autre société porte le sien librement.
     * Saisi en minuscules, entre des espaces, il s'enregistre en
     * majuscules, sans eux.
     */
    public function test_le_code_est_unique_dans_la_societe_mais_libre_dans_une_autre(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre', 'code' => 'LYON1']);
        $autreSociete = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'code' => 'PARIS']);

        $this->assertThrows(
            fn () => $this->creer($premier, ['nom' => 'Lyon 2', 'code' => 'lyon1', 'nic' => $this->nic($premier->societe, '0002')]),
            fn (ValidationException $refus): bool => $refus->errors() === ['code' => [CodeMagasinLibre::MESSAGE_DEJA_UTILISE]],
        );
        $chezLAutre = $this->creer($autreSociete, ['nom' => 'Lyon', 'code' => ' lyon1 ', 'nic' => $this->nic($autreSociete->societe, '0002')]);

        $this->assertSame('LYON1', $chezLAutre->code);
        $this->assertSame(1, $premier->societe->magasins()->count());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nomsEtCodesProposes(): array
    {
        return [
            'nom de deux mots' => ['Ma Boutique', 'MABOUT'],
            'nom accentué avec des chiffres' => ['Été 2026', 'ETE202'],
            'nom sans lettre ni chiffre' => ['#', 'MAG'],
        ];
    }

    /**
     * Sans code choisi, le magasin reçoit celui que propose son nom : ses
     * six premières lettres ou chiffres, sans accent, en majuscules.
     */
    #[DataProvider('nomsEtCodesProposes')]
    public function test_un_magasin_sans_code_choisi_recoit_le_code_propose_par_son_nom(string $nom, string $codeAttendu): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Piliqi Store']);

        $magasin = $this->creer($premier, ['nom' => $nom, 'nic' => $this->nic($premier->societe, '0002')]);

        $this->assertSame($codeAttendu, $magasin->fresh()->code);
    }

    /**
     * Sans code choisi, le magasin reçoit celui que propose son nom, et,
     * déjà pris dans la société, le premier suffixe libre.
     */
    public function test_un_code_propose_deja_pris_recoit_le_premier_suffixe_libre(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Piliqi Store']);

        $second = $this->creer($premier, ['nom' => 'Piliqi Store', 'nic' => $this->nic($premier->societe, '0002')]);
        $troisieme = $this->creer($premier, ['nom' => 'Piliqi Store', 'nic' => $this->nic($premier->societe, '0003')]);

        $this->assertSame(['PILIQI', 'PILIQI2', 'PILIQI3'], [$premier->code, $second->code, $troisieme->code]);
    }

    /**
     * Vente au comptoir (particulier anonyme) : un client générique existe
     * dès la création du magasin, dans ce magasin seulement.
     */
    public function test_le_client_comptoir_est_cree_avec_le_magasin(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);

        $magasin = $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $this->nic($premier->societe, '0002')]);

        $comptoir = app(TenantContext::class)->runAs($magasin, fn (): Client => Client::query()->sole());
        $this->assertSame([Client::NOM_COMPTOIR, TypeClient::Particulier, true], [$comptoir->nom, $comptoir->type_client, $comptoir->est_comptoir]);
    }

    public function test_la_creation_s_inscrit_au_journal_d_audit(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $nic = $this->nic($premier->societe, '0002');
        $this->actingAs($this->proprietaireDe($premier));

        $magasin = $this->creer($premier, ['nom' => 'Lyon 2', 'code' => 'LYON2', 'nic' => $nic]);

        $entree = app(TenantContext::class)->runAsSociete($premier->societe, fn (): EntreeAudit => EntreeAudit::query()->where('action', ActionAuditee::MagasinCree->value)->where('magasin_id', $magasin->id)->sole());
        $this->assertSame(
            [CategorieAudit::Operations, 'membre', $this->proprietaireDe($premier)->id, 'magasin', $magasin->id, null],
            [$entree->categorie, $entree->origine, (int) $entree->auteur_id, $entree->cible_type, (int) $entree->cible_id, $entree->anciennes_valeurs],
        );
        $this->assertSame([
            'adresse' => '1 RUE DE LA REPUBLIQUE',
            'code_magasin' => 'LYON2',
            'code_postal' => '75001',
            'complement_adresse' => null,
            'etablissement_verifie' => true,
            'nom' => 'Lyon 2',
            'pays' => 'FR',
            'siret' => $premier->societe->siren.$nic,
            'ville' => 'PARIS',
        ], $entree->nouvelles_valeurs);
    }

    /**
     * Dans une société validée, le propriétaire a d'emblée tous les droits
     * dans le nouveau magasin : le journal des événements du magasin
     * l'inscrit (JET 130), avant nul, après relu en base ; le SIRET se
     * réserve et la première période d'activité s'ouvre.
     */
    public function test_un_nouveau_magasin_journalise_les_droits_implicites(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $proprietaire = $this->proprietaireDe($premier);

        $magasin = $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $this->nic($premier->societe, '0002')]);

        $toutes = PermissionRegistry::toutes();
        sort($toutes);
        $droits = app(TenantContext::class)->runAs($magasin, fn (): array => json_decode(EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', (string) CodeEvenement::ModificationDesDroits->value)->sole()->contenu, true)['donnees']);
        $this->assertSame(['id' => $proprietaire->id, 'nom' => $proprietaire->name], $droits['details']['membre']);
        $this->assertNull($droits['details']['avant']);
        $this->assertSame(['role_societe' => 'proprietaire', 'role_magasin' => null, 'statut' => 'actif', 'permissions' => $toutes], $droits['details']['apres']);
        $this->assertSame($magasin->siret, SiretMagasin::query()->where('magasin_id', $magasin->id)->sole()->siret);
        $this->assertSame(1, PeriodeActivite::query()->where('magasin_id', $magasin->id)->whereNull('fin')->count());
    }

    /**
     * Dans une société en cours de vérification, rien ne se réserve ni ne
     * s'ouvre encore : la validation s'en chargera.
     */
    public function test_dans_une_societe_a_verifier_rien_ne_se_reserve_ni_ne_s_ouvre(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre', 'a_verifier' => true]);

        $magasin = $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $this->nic($premier->societe, '0002')]);

        $this->assertSame([0, 0], [SiretMagasin::query()->count(), PeriodeActivite::query()->where('magasin_id', $magasin->id)->count()]);
        $this->assertSame(0, app(TenantContext::class)->runAs($magasin, fn (): int => EnregistrementFiscal::query()->count()));
    }

    /**
     * @return array<string, array{0: StatutSociete}>
     */
    public static function societesEnLectureSeule(): array
    {
        return [
            'suspendue' => [StatutSociete::Suspendue],
            'résiliée' => [StatutSociete::Resiliee],
            'révoquée' => [StatutSociete::Revoquee],
        ];
    }

    #[DataProvider('societesEnLectureSeule')]
    public function test_une_societe_suspendue_ne_cree_plus_de_magasin(StatutSociete $statut): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $premier->societe->forceFill(['statut' => $statut])->save();

        $this->assertThrows(
            fn () => $this->creer($premier, ['nom' => 'Lyon 2', 'nic' => $this->nic($premier->societe, '0002')]),
            LogicException::class,
            CreerEtablissementAction::MESSAGE_SANS_AUTORISATION,
        );

        $this->assertSame(1, Magasin::query()->count());
    }

    /**
     * Le logiciel ne gère pas encore la Guyane, Mayotte ni les
     * collectivités d'outre-mer : un établissement qui y est situé est
     * refusé.
     */
    public function test_hors_des_territoires_geres_le_magasin_est_refuse(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $nic = $this->nic($premier->societe, '0002');
        app(RepertoireSirene::class)->deplacer($premier->societe->siren.$nic, '97300', 'CAYENNE');

        $this->assertThrows(
            fn () => $this->creer($premier, ['nom' => 'Cayenne', 'nic' => $nic]),
            fn (ValidationException $refus): bool => $refus->errors() === ['nic' => [ControleSirene::MOTIF_TERRITOIRE_NON_GERE]],
        );

        $this->assertSame(1, Magasin::query()->count());
    }

    /**
     * Un magasin ne s'efface jamais : ni par le modèle, ni par une requête
     * qui ne passe pas par lui, la base le refuse.
     */
    public function test_un_magasin_ne_se_supprime_jamais_et_la_base_refuse_de_l_effacer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);

        $this->assertThrows(fn () => $magasin->delete(), LogicException::class, 'Un magasin ne se supprime jamais');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('magasins')->where('id', $magasin->id)->delete()), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('societes')->where('id', $magasin->societe_id)->delete()), QueryException::class);

        $this->assertModelExists($magasin);
        $this->assertModelExists($magasin->societe);
    }

    /**
     * Quel que soit le chemin qui l'a créé (inscription validée, autre
     * magasin d'une société validée, fabrique), chaque magasin réel actif
     * d'une société validée a son SIRET actuel au registre des SIRET
     * réservés, et un seul ; aucun magasin d'une société en cours de
     * vérification n'y figure.
     */
    public function test_chaque_magasin_reel_actif_d_une_societe_validee_a_son_siret_actuel_au_registre_et_un_seul(): void
    {
        $premier = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $this->ouvrirUnAutreMagasin($premier, ['nom' => 'Lyon 2']);
        Magasin::factory()->create();
        Magasin::factory()->for($premier->societe)->create();
        $aVerifier = $this->ouvrirUneSociete(['nom' => 'En attente', 'a_verifier' => true]);
        $this->ouvrirUnAutreMagasin($aVerifier, ['nom' => 'En attente 2']);

        $magasinsValides = Magasin::query()->whereHas('societe', fn ($societes) => $societes->whereNotNull('validee_le'))->where('statut', StatutMagasin::Actif)->get();
        $this->assertCount(4, $magasinsValides);

        foreach ($magasinsValides as $magasin) {
            $actuels = SiretMagasin::query()->where('magasin_id', $magasin->id)->whereNull('retire_le')->whereNull('libere_le')->get();
            $this->assertSame([$magasin->siret], $actuels->pluck('siret')->all());
            $this->assertSame($magasin->societe_id, $actuels->sole()->societe_id);
        }

        $this->assertSame(0, SiretMagasin::query()->where('societe_id', $aVerifier->societe_id)->count());
    }

    /**
     * Une ligne du registre ne se corrige jamais (seules sa fin
     * d'actualité et sa libération se posent, une fois) et ne s'efface
     * pas, ni par le modèle ni par la base ; la base refuse aussi une
     * seconde réservation du même SIRET.
     */
    public function test_une_ligne_du_registre_des_siret_ne_se_modifie_ni_ne_s_efface(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon centre']);
        $ligne = SiretMagasin::query()->where('magasin_id', $magasin->id)->sole();
        $autre = Magasin::factory()->create();

        $this->assertThrows(fn () => $ligne->forceFill(['siret' => $autre->siret])->save(), LogicException::class, 'Une ligne du registre des SIRET ne se corrige jamais');
        $this->assertThrows(fn () => $ligne->fresh()->delete(), LogicException::class, 'Une ligne du registre des SIRET ne s\'efface jamais.');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('sirets_magasins')->where('id', $ligne->id)->delete()), QueryException::class);
        $this->assertThrows(
            fn () => app(SiretsDesMagasins::class)->reserver($autre->forceFill(['siret' => $magasin->siret])),
            LogicException::class,
            SiretsDesMagasins::MESSAGE_SIRET_RESERVE,
        );

        $ligne->fresh()->forceFill(['retire_le' => now()])->save();
        $this->assertThrows(fn () => $ligne->fresh()->forceFill(['retire_le' => now()->addDay()])->save(), LogicException::class, 'Une ligne du registre des SIRET ne se corrige jamais');
        $this->assertSame($magasin->siret, $ligne->fresh()->siret);
    }

    /**
     * La société est validée après que la requête de création l'a chargée
     * (pendant le contrôle Sirene, par exemple) : le magasin se met en
     * activité d'après la société relue sous son verrou, et non d'après
     * l'instance chargée avant.
     */
    public function test_un_magasin_cree_avec_la_societe_chargee_avant_sa_validation_se_met_en_activite(): void
    {
        $societe = $this->inscrireUneSociete();
        $chargeeAvant = Societe::query()->findOrFail($societe->id);
        $this->verifierLIdentiteDuProprietaire($societe);
        $this->assertTrue($societe->fresh()->estValidee());
        app(TenantContext::class)->clear();

        $nouveau = app(CreerEtablissementAction::class)->handle($chargeeAvant->proprietaire, $chargeeAvant, ['nom' => 'Nouveau magasin', 'nic' => MagasinFactory::nicLibre($chargeeAvant)]);

        $this->assertSame(
            [1, 1],
            [SiretMagasin::query()->where('magasin_id', $nouveau->id)->whereNull('libere_le')->count(), PeriodeActivite::query()->where('magasin_id', $nouveau->id)->whereNull('fin')->count()],
        );
        $this->assertTrue($nouveau->fresh()->peutVendre());
    }

    /**
     * Le magasin que crée le propriétaire de la société de $magasin.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function creer(Magasin $magasin, array $donnees): Magasin
    {
        app(TenantContext::class)->clear();

        return app(CreerEtablissementAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe()->firstOrFail(), $donnees);
    }

    /**
     * Le répertoire Sirene factice, qui exécute la création concurrente à la
     * première consultation d'un établissement, une seule fois.
     */
    private function pendantLeControleSirene(Closure $creationConcurrente): void
    {
        $this->app->instance(RepertoireSirene::class, new class($creationConcurrente) extends FauxRepertoireSirene
        {
            public function __construct(private ?Closure $creationConcurrente) {}

            public function etablissement(string $siret): ?EtablissementSirene
            {
                if ($this->creationConcurrente !== null) {
                    $creation = $this->creationConcurrente;
                    $this->creationConcurrente = null;
                    $creation();
                }

                return parent::etablissement($siret);
            }
        });
    }

    /**
     * Le NIC à partir de ses quatre premiers chiffres, clé comprise.
     */
    private function nic(Societe $societe, string $debut): string
    {
        return substr(IdentifiantsInsee::avecSaCle($societe->siren.$debut), 9);
    }

    private function nombreDEntreesAudit(Societe $societe): int
    {
        return app(TenantContext::class)->runAsSociete($societe, fn (): int => EntreeAudit::query()->count());
    }
}
