<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\AjouterUnExpertComptableAction;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\ConstaterLInscriptionALOrdreAction;
use App\Domain\Cabinets\Actions\CorrigerLAdresseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\RattacherUneDeclarationAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Actions\RetirerLInscriptionALOrdreAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Jobs\RattacherLesDeclarationsDuSiren;
use App\Domain\Cabinets\Jobs\RattacherUneDeclaration;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Actions\RevoquerSocieteAction;
use App\Domain\Tenancy\Actions\SaisirCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Ordre\FauxAnnuaireDeLOrdre;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

/**
 * L'expert-comptable de la société face aux gestes simultanés, chacun dans
 * son processus, sur une vraie base PostgreSQL : le rattachement de sa
 * déclaration au cabinet de son SIREN, la gestion de ses comptes, le lien
 * de son mot de passe et ses gestes chez la société se sérialisent dans
 * l'ordre des verrous de l'espace des cabinets, sans interblocage, et ne
 * laissent qu'une issue cohérente. Les trois premiers tests opposent des
 * gestes sans verrou commun : ils prouvent l'absence d'interblocage et
 * l'issue unique, pas l'ordre des commits, que les tests déterministes de
 * RattachementDesExpertsComptablesTest couvrent.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé. Le répertoire Sirene et l'annuaire de l'Ordre
 * factices vivent dans la mémoire de chaque processus : l'autre processus
 * les règle de nouveau.
 */
#[Group('concurrence')]
class ExpertsComptablesConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    private const AUTRE_MOT_DE_PASSE = 'Autre-Mot-2026!';

    public function test_la_saisie_du_code_et_la_verification_de_l_ordre_simultanees_rattachent_la_declaration_une_seule_fois(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->expertComptable($magasin);
        $cabinet = $this->inscrireLeCabinetDuSiren(FauxRepertoireSirene::SIREN_CABINET);
        $cabinetId = (int) $cabinet->getKey();

        $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($cabinetId): void {
            self::reglerLesFournisseursFactices(FauxRepertoireSirene::SIREN_CABINET);
            app(ConstaterLInscriptionALOrdreAction::class)->handle(Societe::query()->findOrFail($cabinetId), self::editeur(), '75-'.substr(FauxRepertoireSirene::SIREN_CABINET, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true);
        });
        $this->saisirLeCodeDuCourrier($cabinet);

        $this->assertTrue($this->attendre($concurrente)->successful());
        app(TenantContext::class)->clear();
        $this->rattacherCeQueLaTacheHoraireRattraperait();
        $declaration = $this->declarationDe($magasin->societe);
        $this->assertSame([EtatDuLien::Actif, $cabinetId], [$declaration->etat, (int) $declaration->cabinet_id]);
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::ExpertComptableRattache));
        $this->assertCount(1, $this->entreesDAudit($cabinet->fresh(), ActionAuditee::LienActive));
    }

    public function test_une_declaration_pendant_la_verification_de_l_ordre_du_cabinet_de_ce_siren_finit_rattachee_ou_refusee_jamais_en_attente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $cabinet = $this->inscrireLeCabinetDuSiren(FauxRepertoireSirene::SIREN_CABINET);
        $this->saisirLeCodeDuCourrier($cabinet);
        $cabinetId = (int) $cabinet->getKey();

        $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($cabinetId): void {
            self::reglerLesFournisseursFactices(FauxRepertoireSirene::SIREN_CABINET);
            app(ConstaterLInscriptionALOrdreAction::class)->handle(Societe::query()->findOrFail($cabinetId), self::editeur(), '75-'.substr(FauxRepertoireSirene::SIREN_CABINET, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true);
        });

        try {
            $this->declarerSonExpertComptable($magasin);
        } catch (ValidationException $refus) {
            $this->assertSame([DeclarerSonExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI], $refus->errors()['siren'] ?? []);
        }

        $this->assertTrue($this->attendre($concurrente)->successful());
        app(TenantContext::class)->clear();
        $this->rattacherCeQueLaTacheHoraireRattraperait();
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Declare->value)->count());
        $liens = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->get();
        $this->assertTrue($liens->every(fn (LienCabinet $lien): bool => $lien->etat === EtatDuLien::Actif && (int) $lien->cabinet_id === $cabinetId));
        $this->assertSame(0, User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', 'expert_comptable')->where('statut', 'actif')->count());
    }

    public function test_la_verification_d_un_cabinet_pendant_que_le_proprietaire_change_de_siren_declare_ne_rattache_que_le_siren_en_vigueur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->expertComptable($magasin);
        $cabinet = $this->inscrireLeCabinetDuSiren(FauxRepertoireSirene::SIREN_CABINET);
        $this->saisirLeCodeDuCourrier($cabinet);
        $cabinetId = (int) $cabinet->getKey();

        $concurrente = $this->demarrerUnProcessusConcurrent(static function () use ($cabinetId): void {
            self::reglerLesFournisseursFactices(FauxRepertoireSirene::SIREN_CABINET);
            app(ConstaterLInscriptionALOrdreAction::class)->handle(Societe::query()->findOrFail($cabinetId), self::editeur(), '75-'.substr(FauxRepertoireSirene::SIREN_CABINET, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST, true);
        });

        try {
            $this->declarerSonExpertComptable($magasin, siren: '000000927', nom: 'Léa Blanc', email: 'lea.blanc@blanc-expertise.test', remplacer: true);
        } catch (Throwable $refus) {
            $this->assertStringContainsString(DeclarerSonExpertComptableAction::MESSAGE_LIENS_CHANGES, $refus->getMessage());
        }

        $this->assertTrue($this->attendre($concurrente)->successful());
        app(TenantContext::class)->clear();
        $this->rattacherCeQueLaTacheHoraireRattraperait();
        $enCours = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->whereIn('etat', [EtatDuLien::Actif->value, EtatDuLien::Declare->value])->sole();
        $this->assertTrue(
            ($enCours->etat === EtatDuLien::Declare && $enCours->siren_cabinet === '000000927')
            || ($enCours->etat === EtatDuLien::Actif && (int) $enCours->cabinet_id === $cabinetId && $enCours->siren_cabinet === FauxRepertoireSirene::SIREN_CABINET),
            "Lien en cours inattendu : {$enCours->etat->value}, SIREN {$enCours->siren_cabinet}."
        );
    }

    public function test_deux_rattachements_simultanes_de_la_meme_declaration_n_en_font_qu_un(): void
    {
        [$client, $declaration, $cabinet] = $this->declarationEtCabinetVerifie();
        [$declarationId, $cabinetId] = [(int) $declaration->getKey(), (int) $cabinet->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($declarationId, $cabinetId): void {
            app(RattacherUneDeclarationAction::class)->handle($declarationId, $cabinetId);
        }, $concurrente);

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle($declarationId, $cabinetId));

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame([EtatDuLien::Actif, $cabinetId], [$declaration->fresh()->etat, (int) $declaration->fresh()->cabinet_id]);
        $this->assertCount(1, $this->entreesDAudit($client, ActionAuditee::ExpertComptableRattache));
        $this->assertCount(1, $this->entreesDAudit($cabinet->fresh(), ActionAuditee::LienActive));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function avecEtSansMagasinReel(): array
    {
        return ['avec un magasin réel' => [true], 'sans magasin réel' => [false]];
    }

    #[DataProvider('avecEtSansMagasinReel')]
    public function test_un_rattachement_et_le_retrait_de_la_declaration_simultanes_ne_laissent_qu_une_issue(bool $avecUnMagasin): void
    {
        [$client, $declaration, $cabinet, $marc] = $this->declarationEtCabinetVerifie($avecUnMagasin);
        [$declarationId, $proprietaireId] = [(int) $declaration->getKey(), (int) $client->proprietaire()->firstOrFail()->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($declarationId, $proprietaireId): void {
            app(MettreFinAuLienAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($declarationId));
        }, $concurrente);

        app(RattacherUneDeclarationAction::class)->handle($declarationId, (int) $cabinet->getKey());

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(EtatDuLien::Termine, $declaration->fresh()->etat);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $client->id)->whereIn('etat', [EtatDuLien::Actif->value, EtatDuLien::Declare->value])->count());
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
    }

    public function test_un_rattachement_et_la_reactivation_d_un_compte_d_expert_comptable_simultanes_laissent_le_compte_retire(): void
    {
        [$client, $declaration] = $this->declarationEtCabinetVerifie(sansCabinetVerifie: true);
        $ines = $this->ajouterUnExpertComptable($client, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $proprietaire = $client->proprietaire()->firstOrFail();
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $ines);
        $cabinet = $this->ouvrirLeCabinetSansRattachement();
        [$inesId, $proprietaireId] = [(int) $ines->getKey(), (int) $proprietaire->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($inesId, $proprietaireId): void {
            app(ChangerStatutCompteAction::class)->reactiver(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($inesId));
        }, $concurrente);

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle((int) $declaration->getKey(), (int) $cabinet->getKey()));

        $this->attendre($concurrente);
        $this->assertSame(EtatDuLien::Actif, $declaration->fresh()->etat);
        $this->assertSame(StatutCompte::Retire, $ines->fresh()->statut);
        $this->assertSame(0, User::query()->where('societe_id', $client->id)->where('role_societe', 'expert_comptable')->where('statut', '<>', StatutCompte::Retire->value)->count());
    }

    public function test_un_rattachement_et_le_reglage_des_autorisations_simultanes_ne_perdent_aucun_reglage(): void
    {
        [$client, $declaration, $cabinet] = $this->declarationEtCabinetVerifie();
        [$declarationId, $proprietaireId] = [(int) $declaration->getKey(), (int) $client->proprietaire()->firstOrFail()->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($declarationId, $proprietaireId): void {
            app(ReglerLesAutorisationsDuCabinetAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($declarationId), [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]);
        }, $concurrente);

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle($declarationId, (int) $cabinet->getKey()));

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(EtatDuLien::Actif, $declaration->fresh()->etat);
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_ACHAT_CREER], app(TenantContext::class)->runAsSociete($client, fn (): array => $declaration->fresh()->autorisations()));
        $this->assertCount(1, $this->entreesDAudit($client, ActionAuditee::AutorisationsDuCabinetModifiees));
    }

    public function test_l_ajout_d_une_personne_pendant_le_rattachement_ne_laisse_aucun_compte_d_expert_comptable_ouvert(): void
    {
        [$client, $declaration, $cabinet] = $this->declarationEtCabinetVerifie();
        [$declarationId, $proprietaireId] = [(int) $declaration->getKey(), (int) $client->proprietaire()->firstOrFail()->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($declarationId, $proprietaireId): void {
            app(AjouterUnExpertComptableAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($declarationId), ['name' => 'Inès Garnier', 'email' => 'ines.garnier@petit-associes.test', 'email_confirmation' => 'ines.garnier@petit-associes.test'], 'password', (string) Str::uuid());
        }, $concurrente);

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle($declarationId, (int) $cabinet->getKey()));

        $this->assertFalse($this->attendre($concurrente)->successful());
        $this->assertSame(EtatDuLien::Actif, $declaration->fresh()->etat);
        $this->assertSame(0, User::query()->where('societe_id', $client->id)->where('role_societe', 'expert_comptable')->where('statut', '<>', StatutCompte::Retire->value)->count());
        $this->assertFalse(User::query()->where('name', 'Inès Garnier')->exists());
    }

    public function test_le_lien_du_mot_de_passe_suivi_pendant_le_rattachement_ne_rouvre_pas_le_compte(): void
    {
        [$client, $declaration] = $this->declarationEtCabinetVerifie(sansCabinetVerifie: true);
        $ines = $this->ajouterUnExpertComptable($client, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $cabinet = $this->ouvrirLeCabinetSansRattachement();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($jeton): void {
            app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, self::AUTRE_MOT_DE_PASSE, self::AUTRE_MOT_DE_PASSE);
        }, $concurrente);

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle((int) $declaration->getKey(), (int) $cabinet->getKey()));

        $this->attendre($concurrente);
        $this->assertSame(EtatDuLien::Actif, $declaration->fresh()->etat);
        $this->assertSame(StatutCompte::Retire, $ines->fresh()->statut);
        $this->assertNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $ines->id)->sole()->jeton_expire_le);
    }

    public function test_le_meme_lien_suivi_deux_fois_en_meme_temps_ne_sert_qu_une_fois(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $marc = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('comptes_expert_comptable', 'for update', static function () use ($jeton): void {
            app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, self::AUTRE_MOT_DE_PASSE, self::AUTRE_MOT_DE_PASSE);
        }, $concurrente);

        app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI);

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('LienDeLExpertComptableInvalideException', $resultat->errorOutput());
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $marc->fresh()->password));
        $this->assertFalse(Hash::check(self::AUTRE_MOT_DE_PASSE, $marc->fresh()->password));
    }

    public function test_le_renvoi_du_lien_pendant_que_la_personne_suit_l_ancien_ne_laisse_qu_un_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $marc = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        [$marcId, $proprietaireId] = [(int) $marc->getKey(), (int) $this->proprietaireDe($magasin)->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('comptes_expert_comptable', 'for update', static function () use ($marcId, $proprietaireId): void {
            app(RedonnerLAccesAction::class)->parUnNouveauLien(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($marcId), (string) Str::uuid());
        }, $concurrente);

        app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $choisiValable = Hash::check(self::MOT_DE_PASSE_CHOISI, $marc->fresh()->password);
        $nouveauLien = CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $marcId)->sole()->jeton_expire_le !== null;
        $this->assertTrue($choisiValable xor $nouveauLien, 'Le mot de passe choisi et un nouveau lien ne valent jamais ensemble.');
        $this->assertTrue($nouveauLien);
    }

    public function test_la_correction_de_l_adresse_pendant_que_la_personne_choisit_son_mot_de_passe_passe_avant_ou_apres(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $marc = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        [$marcId, $proprietaireId] = [(int) $marc->getKey(), (int) $this->proprietaireDe($magasin)->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('comptes_expert_comptable', 'for update', static function () use ($marcId, $proprietaireId): void {
            app(CorrigerLAdresseDeLExpertComptableAction::class)->handle(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($marcId), 'marc@petit-associes.test', 'marc@petit-associes.test', 'password');
        }, $concurrente);

        app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($jeton, self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI);

        $resultat = $this->attendre($concurrente);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString(CorrigerLAdresseDeLExpertComptableAction::MESSAGE_MOT_DE_PASSE_DEJA_CHOISI, $resultat->errorOutput());
        $this->assertSame('marc.petit@petit-associes.test', $marc->fresh()->email);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $marc->fresh()->password));
    }

    /**
     * Le rattachement tient la ligne de la société puis la déclaration avant
     * les comptes ; la correction de l'adresse les prend dans le même
     * ordre, si bien qu'elle attend la fin du rattachement au lieu de
     * tenir le compte qu'il veut retirer pendant que la garde de son
     * adresse attend la déclaration (interblocage). Elle passe après lui :
     * le compte est retiré, la correction refusée, l'adresse inchangée.
     */
    #[DataProvider('avecEtSansMagasinReel')]
    public function test_la_correction_de_l_adresse_pendant_le_rattachement_passe_avant_ou_apres_sans_interblocage(bool $avecUnMagasin): void
    {
        [$client, $marc] = $this->declarationSansMotDePasseChoisi($avecUnMagasin);
        $declaration = $this->declarationDe($client);
        $cabinet = $this->ouvrirLeCabinetSansRattachement();
        [$marcId, $proprietaireId] = [(int) $marc->getKey(), (int) $client->proprietaire()->firstOrFail()->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($marcId, $proprietaireId): void {
            app(CorrigerLAdresseDeLExpertComptableAction::class)->handle(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($marcId), 'marc@petit-associes.test', 'marc@petit-associes.test', 'password');
        }, $concurrente);

        $this->assertTrue(app(RattacherUneDeclarationAction::class)->handle((int) $declaration->getKey(), (int) $cabinet->getKey()));

        $resultat = $this->attendre($concurrente);
        $this->assertStringNotContainsString('40P01', $resultat->errorOutput().$resultat->output());
        $this->assertFalse($resultat->successful());
        $this->assertSame(EtatDuLien::Actif, $declaration->fresh()->etat);
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
        $this->assertSame('marc.petit@petit-associes.test', $marc->fresh()->email);
    }

    public function test_deux_retraits_simultanes_des_deux_dernieres_personnes_mettent_fin_a_la_declaration(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $marc = $this->declarerSonExpertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $declaration = $this->declarationDe($magasin->societe);
        [$inesId, $proprietaireId] = [(int) $ines->getKey(), (int) $this->proprietaireDe($magasin)->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($inesId, $proprietaireId): void {
            app(ChangerStatutCompteAction::class)->retirer(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($inesId));
        }, $concurrente);

        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $marc);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame([StatutCompte::Retire, StatutCompte::Retire], [$marc->fresh()->statut, $ines->fresh()->statut]);
        $this->assertSame(EtatDuLien::Termine, $declaration->fresh()->etat);
    }

    public function test_une_declaration_et_l_acceptation_de_la_demande_d_un_autre_cabinet_simultanees_ne_laissent_qu_un_lien_en_cours(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->demanderLeRattachement($cabinet, $magasin->societe, 'compta@cliente.test');
        $demande = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->sole();
        [$demandeId, $proprietaireId] = [(int) $demande->getKey(), (int) $this->proprietaireDe($magasin)->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($demandeId, $proprietaireId): void {
            app(AccepterLaDemandeDeLienAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($demandeId));
        }, $concurrente);

        $this->declarerSonExpertComptable($magasin, siren: '000000927');

        $this->attendre($concurrente);
        $this->assertSame(1, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->whereIn('etat', [EtatDuLien::Actif->value, EtatDuLien::Declare->value])->count());
    }

    #[DataProvider('avecEtSansMagasinReel')]
    public function test_la_revocation_du_client_pendant_le_rattachement_ne_laisse_aucun_lien_actif_ni_compte_ouvert(bool $avecUnMagasin): void
    {
        [$client, $declaration, $cabinet, $marc] = $this->declarationEtCabinetVerifie($avecUnMagasin);
        $this->prouverLaRemiseDuCourrierDeRevocation($client);
        $clientId = (int) $client->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($clientId): void {
            app(RevoquerSocieteAction::class)->handle(Societe::query()->findOrFail($clientId), self::editeur());
        }, $concurrente);

        app(RattacherUneDeclarationAction::class)->handle((int) $declaration->getKey(), (int) $cabinet->getKey());

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(StatutSociete::Revoquee, $client->fresh()->statut);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $clientId)->whereIn('etat', [EtatDuLien::Actif->value, EtatDuLien::Declare->value])->count());
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
    }

    /**
     * Le rattachement tient le cabinet sous un verrou partagé ; la
     * révocation du cabinet attend, puis met fin au lien né du
     * rattachement, sans interblocage.
     */
    public function test_la_revocation_du_cabinet_pendant_un_rattachement_ne_laisse_aucun_lien_actif(): void
    {
        [$client, $declaration, $cabinet] = $this->declarationEtCabinetVerifie();
        $this->prouverLaRemiseDuCourrierDeRevocation($cabinet);
        $cabinetId = (int) $cabinet->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('cabinets', 'for share', static function () use ($cabinetId): void {
            app(RevoquerSocieteAction::class)->handle(Societe::query()->findOrFail($cabinetId), self::editeur());
        }, $concurrente);

        app(RattacherUneDeclarationAction::class)->handle((int) $declaration->getKey(), $cabinetId);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(StatutSociete::Revoquee, $cabinet->fresh()->statut);
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $client->id)->where('etat', EtatDuLien::Actif->value)->count());
    }

    public function test_le_retrait_de_l_ordre_pendant_un_rattachement_ne_s_interbloque_pas_et_ne_laisse_aucun_lien_actif(): void
    {
        [$client, $declaration, $cabinet] = $this->declarationEtCabinetVerifie();
        $cabinetId = (int) $cabinet->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('cabinets', 'for share', static function () use ($cabinetId): void {
            app(RetirerLInscriptionALOrdreAction::class)->handle(Societe::query()->findOrFail($cabinetId), new InterventionDeLEditeur('Radiation publiée au tableau de l\'Ordre.', 'DOSSIER-2026-0101', ['alice']), 'Radiation publiée.');
        }, $concurrente);

        app(RattacherUneDeclarationAction::class)->handle((int) $declaration->getKey(), $cabinetId);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $client->id)->where('etat', EtatDuLien::Actif->value)->count());
        $this->assertSame(EtatDuLien::Termine, $declaration->fresh()->etat);
    }

    public function test_deux_ajouts_de_la_meme_personne_avec_le_meme_jeton_ne_creent_qu_un_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->declarerSonExpertComptable($magasin);
        $declaration = $this->declarationDe($magasin->societe);
        $jeton = (string) Str::uuid();
        $personne = ['name' => 'Inès Garnier', 'email' => 'ines.garnier@petit-associes.test', 'email_confirmation' => 'ines.garnier@petit-associes.test'];
        [$declarationId, $proprietaireId] = [(int) $declaration->getKey(), (int) $this->proprietaireDe($magasin)->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('liens_cabinet', 'for update', static function () use ($declarationId, $proprietaireId, $personne, $jeton): void {
            app(AjouterUnExpertComptableAction::class)->handle(User::query()->findOrFail($proprietaireId), LienCabinet::query()->withoutGlobalScopes()->findOrFail($declarationId), $personne, 'password', $jeton);
        }, $concurrente);

        $compte = app(AjouterUnExpertComptableAction::class)->handle($this->proprietaireDe($magasin), $declaration, $personne, 'password', $jeton);

        $this->assertTrue($this->attendre($concurrente)->successful());
        $this->assertSame([$compte->id], User::query()->where('name', 'Inès Garnier')->pluck('id')->all());
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::ExpertComptableCree));
    }

    public function test_suspendre_un_expert_comptable_pendant_qu_il_valide_un_achat_passe_avant_ou_apres(): void
    {
        [$magasin, $marc, $factureId] = $this->unAchatSaisiParLExpertComptable();
        [$marcId, $magasinId] = [(int) $marc->getKey(), (int) $magasin->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($marcId, $magasinId, $factureId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ValiderFactureAchatAction::class)->handle(User::query()->findOrFail($marcId), FactureAchat::query()->findOrFail($factureId));
        }, $concurrente);

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $marc);

        $this->assertSame(StatutCompte::Suspendu, $marc->fresh()->statut);
        $this->assertPasseAvantOuApres($this->attendre($concurrente), $magasin->societe, $factureId, ActionAuditee::CompteSuspendu);
    }

    public function test_une_facture_d_achat_validee_par_l_expert_comptable_pendant_le_retrait_de_cette_autorisation_passe_avant_ou_apres(): void
    {
        [$magasin, $marc, $factureId] = $this->unAchatSaisiParLExpertComptable();
        [$marcId, $magasinId] = [(int) $marc->getKey(), (int) $magasin->getKey()];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($marcId, $magasinId, $factureId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ValiderFactureAchatAction::class)->handle(User::query()->findOrFail($marcId), FactureAchat::query()->findOrFail($factureId));
        }, $concurrente);

        app(ReglerLesAutorisationsDuCabinetAction::class)->handle($this->proprietaireDe($magasin), $this->declarationDe($magasin->societe), [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]);

        $this->assertNotContains(PermissionRegistry::FACTURE_ACHAT_VALIDER, $marc->fresh()->autorisationsDans($magasin));
        $this->assertPasseAvantOuApres($this->attendre($concurrente), $magasin->societe, $factureId, ActionAuditee::AutorisationsDuCabinetModifiees);
    }

    /**
     * La validation de l'achat passe tout entière avant le geste qui retire
     * le droit de valider (son entrée d'audit vient avant celle du geste),
     * ou elle est refusée après lui et l'achat reste en brouillon : jamais
     * validée après, avec le droit d'avant.
     */
    private function assertPasseAvantOuApres(ProcessResult $validation, Societe $societe, int $factureId, ActionAuditee $retrait): void
    {
        $statut = DB::table('factures_achat')->where('id', $factureId)->value('statut');

        if (! $validation->successful()) {
            $this->assertSame('brouillon', $statut, $validation->errorOutput());

            return;
        }

        $this->assertSame('validee', $statut);
        [$validee] = $this->entreesDAudit($societe, ActionAuditee::FactureAchatValidee);
        $this->assertLessThan(collect($this->entreesDAudit($societe, $retrait))->last()->id, $validee->id);
    }

    public function test_un_rattachement_pendant_l_export_du_journal_par_l_expert_comptable_passe_tout_entier_avant_ou_apres(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $marc = $this->expertComptable($lyon);
        $paris = $this->ouvrirUneSociete(['nom' => 'Paris 1', 'siren' => '000000935']);
        $lea = $this->expertComptable($paris, 'Léa Blanc');
        $cabinet = $this->ouvrirLeCabinetSansRattachement();
        $jour = Calendrier::deParis()->aujourdhui();
        $cabinetId = (int) $cabinet->getKey();
        $declarationDeLyon = (int) $this->declarationDe($lyon->societe)->getKey();
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($declarationDeLyon, $cabinetId): void {
            app(RattacherUneDeclarationAction::class)->handle($declarationDeLyon, $cabinetId);
        }, $concurrente);

        app(TenantContext::class)->runAs($lyon, fn () => app(ExporterJournalDesEvenementsAction::class)->handle($marc->fresh(), $jour, $jour));

        $this->assertTrue($this->attendre($concurrente)->successful());
        $evenements = app(TenantContext::class)->runAs($lyon->fresh(), fn () => EnregistrementFiscal::query()->whereIn('type', [(string) CodeEvenement::ExportDeDonnees->value, (string) CodeEvenement::ModificationDesDroits->value])->orderBy('sequence')->pluck('type')->all());
        $this->assertSame([(string) CodeEvenement::ExportDeDonnees->value, (string) CodeEvenement::ModificationDesDroits->value, (string) CodeEvenement::ModificationDesDroits->value], array_slice($evenements, -3));

        [$leaId, $parisId] = [(int) $lea->getKey(), (int) $paris->getKey()];
        $exports = fn (): int => app(TenantContext::class)->runAs($paris->fresh(), fn (): int => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::ExportDeDonnees->value)->count());
        $exportConcurrent = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($leaId, $parisId, $jour): void {
            app(TenantContext::class)->runAs(Magasin::query()->findOrFail($parisId), fn () => app(ExporterJournalDesEvenementsAction::class)->handle(User::query()->findOrFail($leaId), $jour, $jour));
        }, $exportConcurrent);
        app(RattacherUneDeclarationAction::class)->handle((int) $this->declarationDe($paris->societe)->getKey(), $cabinetId);
        $resultat = $this->attendre($exportConcurrent);
        $this->assertFalse($resultat->successful());
        $this->assertStringContainsString('AuthorizationException', $resultat->errorOutput());
        $this->assertSame(0, $exports());
    }

    /**
     * Une société cliente qui a déclaré son expert-comptable, Marc Petit,
     * qui a choisi son mot de passe, et le cabinet de ce SIREN inscrit et
     * vérifié sans que son rattachement soit parti : chaque test le fait
     * lui-même, dans le processus de son choix.
     *
     * @return array{0: Societe, 1: LienCabinet, 2: Societe, 3: User}
     */
    private function declarationEtCabinetVerifie(bool $avecUnMagasin = true, bool $sansCabinetVerifie = false): array
    {
        if ($avecUnMagasin) {
            $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        } else {
            $client = $this->inscrireUneSociete();
            $this->saisirLeCodeDuCourrier($client)->forceFill(['password' => 'password'])->save();
            $client = $client->fresh();
        }

        $marc = $this->expertComptable($client);
        $cabinet = $sansCabinetVerifie ? $client : $this->ouvrirLeCabinetSansRattachement();
        app(TenantContext::class)->clear();

        return [$client->fresh(), $this->declarationDe($client), $cabinet, $marc];
    }

    /**
     * Le cabinet du SIREN déclaré, inscrit et vérifié, ses tâches de
     * rattachement retenues : le test rattache lui-même.
     */
    private function ouvrirLeCabinetSansRattachement(): Societe
    {
        Bus::fake([RattacherLesDeclarationsDuSiren::class, RattacherUneDeclaration::class]);
        $cabinet = $this->ouvrirUnCabinet();
        app(TenantContext::class)->clear();

        return $cabinet;
    }

    /**
     * Rejoue, dans ce processus, ce que la tâche horaire rattraperait : un
     * rattachement par déclaration encore en attente dont le cabinet du
     * SIREN est vérifié.
     */
    private function rattacherCeQueLaTacheHoraireRattraperait(): void
    {
        $this->artisan('app:rattacher-experts-comptables')->assertSuccessful();
        app(TenantContext::class)->clear();
    }

    /**
     * Le cabinet de ce SIREN inscrit, sans code ni Ordre vérifié, son
     * inscription au tableau connue de l'annuaire factice.
     */
    private function inscrireLeCabinetDuSiren(string $siren): Societe
    {
        self::reglerLesFournisseursFactices($siren);

        return $this->inscrireUneSociete($siren, "cabinet-{$siren}@cabinet.test", TypeDActivite::Cabinet);
    }

    /**
     * Le répertoire Sirene et l'annuaire de l'Ordre factices de ce
     * processus : ce SIREN est un cabinet comptable inscrit au tableau.
     */
    private static function reglerLesFournisseursFactices(string $siren): void
    {
        app(RepertoireSirene::class)->exercer($siren, ControleSirene::CODE_ACTIVITES_COMPTABLES);
        $annuaire = app(AnnuaireDeLOrdre::class);

        if ($annuaire instanceof FauxAnnuaireDeLOrdre) {
            $annuaire->inscrire($siren, '75-'.substr($siren, -5), FauxAnnuaireDeLOrdre::CONSEIL_DU_CABINET_DE_TEST);
        }
    }

    /**
     * Un expert-comptable de la société, déclaré avec les achats, qui a
     * saisi une facture d'achat encore en brouillon dans son magasin.
     *
     * @return array{0: Magasin, 1: User, 2: int}
     */
    private function unAchatSaisiParLExpertComptable(): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $marc = $this->expertComptable($magasin, autorisations: [...PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)), PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_CREATE])->fresh();
        app(TenantContext::class)->set($magasin);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($marc, Fournisseur::factory()->create(), [['produit_id' => Produit::factory()->create(['taux_tva' => 20])->id, 'quantite' => 2, 'prix_unitaire_ht' => 10]], ['date_facture' => '2026-01-15']);
        app(TenantContext::class)->clear();

        return [$magasin, $marc, (int) $factureAchat->getKey()];
    }

    /**
     * Un client, avec ou sans magasin réel, qui a déclaré son
     * expert-comptable ; la personne n'a pas encore choisi son mot de
     * passe : son adresse se corrige encore.
     *
     * @return array{0: Societe, 1: User}
     */
    private function declarationSansMotDePasseChoisi(bool $avecUnMagasin): array
    {
        if ($avecUnMagasin) {
            $client = $this->ouvrirUneSociete(['nom' => 'Boutique cliente'])->societe;
        } else {
            $client = $this->inscrireUneSociete();
            $this->saisirLeCodeDuCourrier($client)->forceFill(['password' => 'password'])->save();
            $client = $client->fresh();
        }

        $marc = $this->declarerSonExpertComptable($client);
        app(TenantContext::class)->clear();

        return [$client->fresh(), $marc];
    }

    private function declarationDe(Societe $client): LienCabinet
    {
        return LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $client->getKey())->orderByDesc('id')->firstOrFail();
    }

    /**
     * La remise du courrier de révocation de cette société, prouvée par son
     * code saisi sur sa page : l'éditeur peut alors la révoquer.
     */
    private function prouverLaRemiseDuCourrierDeRevocation(Societe $societe): void
    {
        app(TenantContext::class)->clear();
        app(EnvoyerCodeDeConfirmationAction::class)->parLEditeur($societe->fresh(), MotifCode::Revocation, self::editeur());
        app(SaisirCodeDeConfirmationAction::class)->saisirUnCodeDeProcedure((string) $societe->siren, MotifCode::Revocation, $this->codeDuCourrier($societe, MotifCode::Revocation), '127.0.0.1');
        app(TenantContext::class)->clear();
    }

    private static function editeur(): InterventionDeLEditeur
    {
        return new InterventionDeLEditeur('Société qui a pris le SIREN d\'un autre', 'DOSSIER-2026-0102', ['alice', 'bruno']);
    }

    private function attendre(?InvokedProcess $concurrente): ProcessResult
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);

        try {
            return $this->attendreLeProcessusConcurrent($concurrente);
        } catch (Throwable $erreur) {
            $this->fail($erreur->getMessage());
        }
    }
}
