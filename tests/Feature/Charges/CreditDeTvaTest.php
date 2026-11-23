<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\Actions\ConfirmerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\CorrigerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\DeclarerUnCreditDeTvaAction;
use App\Domain\Charges\Actions\ModifierUnCreditDeTvaAction;
use App\Domain\Charges\Enums\EtatDuCreditDeTva;
use App\Domain\Charges\Enums\LectureDeLaTva;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Charges\Services\CreditsDeTva;
use App\Domain\Charges\Services\GestesSurLeCreditDeTva;
use App\Domain\Charges\Services\MessagesDuCreditDeTva;
use App\Domain\Charges\Support\GardesDesCreditsDeTva;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use App\Domain\Tenancy\Enums\CategorieAudit;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Le crédit de TVA de départ (lot TVA27, choix K2 de l'éditeur du
 * 15/11/2026, règle du 18/11/2026, maquette « Message TVA avant PILIQI »
 * approuvée ; voir CreditsDeTva et ses actions) : la société, arrivée dans
 * PILIQI le 12 novembre 2026 au réel normal mensuel, indique le crédit de
 * l'une de ses déclarations déposées, novembre 2026 se déclarant à partir
 * du 1er décembre ; le temps est figé au 15 décembre 2026, à Paris.
 * Le solde est négatif pour un crédit, 0 € sans crédit, jamais positif ;
 * qui le saisit, qui le vérifie, ses états, son journal d'audit, son jeton
 * de demande répétée, son isolation et les gardes de la base.
 */
class CreditDeTvaTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->societe = $this->magasin->societe()->firstOrFail();
        $this->proprietaire = $this->proprietaireDe($this->magasin);
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        app(TenantContext::class)->clear();
    }

    /**
     * Le propriétaire, sans comptable, indique −1 250,00 € pour novembre
     * 2026 : la déclaration est « non vérifiée par un comptable », sert de
     * point de départ, et s'inscrit au journal d'audit (catégorie
     * « opérations »), avec ses valeurs, sans le nom de personne ; dès
     * qu'un cabinet est lié, elle est « à vérifier ».
     */
    public function test_le_proprietaire_sans_comptable_indique_un_credit_non_verifie_qui_passe_a_verifier_quand_un_comptable_arrive(): void
    {
        $credit = $this->declarer($this->proprietaire, '2026-11', -125000);

        $this->assertSame([-125000, 125000, '2026-11', ValideePar::Proprietaire, false], [$credit->solde, $credit->credit(), $credit->periode, $credit->saisi_comme, $credit->estVerifie()]);
        $this->assertSame(EtatDuCreditDeTva::NonVerifie, app(CreditsDeTva::class)->etat($this->societe, $credit));
        $this->assertTrue(app(CreditsDeTva::class)->ancre($this->societe)?->is($credit));
        $entrees = $this->entreesDAudit($this->societe, ActionAuditee::CreditDeTvaDeclare);
        $this->assertCount(1, $entrees);
        $this->assertSame(CategorieAudit::Operations, $entrees[0]->categorie);
        $this->assertNull($entrees[0]->anciennes_valeurs);
        $this->assertSame(self::trie(['credit' => (string) $credit->uuid, 'periode' => '2026-11', 'cadence' => 'mensuelle', 'solde' => -125000, 'verifie' => false, 'verifie_par' => null, 'motif' => null]), self::trie($entrees[0]->nouvelles_valeurs));

        $this->lierLeCabinet($this->ouvrirUnCabinet(), $this->magasin);

        $this->assertSame(EtatDuCreditDeTva::AVerifier, app(CreditsDeTva::class)->etat($this->societe, $credit->fresh()));
    }

    /**
     * Un solde positif, une TVA à payer déjà payée avec sa déclaration, est
     * refusé avec le message de la maquette, sans rien écrire.
     */
    public function test_un_solde_positif_est_refuse_avec_son_message(): void
    {
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-11', 10000), LogicException::class, DeclarerUnCreditDeTvaAction::REFUS_POSITIF);
        $this->assertSame('Une TVA à payer de votre déclaration a été payée avec elle : elle ne se reporte pas. Saisissez votre crédit en négatif, ou 0 € si vous n\'en avez pas.', DeclarerUnCreditDeTvaAction::REFUS_POSITIF);
        $this->assertSame(0, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * « Non, aucun crédit » : 0 € se déclare, et sert de point de départ.
     */
    public function test_aucun_credit_se_declare_a_zero_euro(): void
    {
        $credit = $this->declarer($this->proprietaire, '2026-11', 0);

        $this->assertSame([0, 0], [$credit->solde, $credit->credit()]);
        $this->assertTrue(app(CreditsDeTva::class)->ancre($this->societe)?->is($credit));
    }

    /**
     * Seules les périodes terminées depuis celle de l'arrivée se déclarent :
     * octobre 2026, d'avant l'arrivée, et décembre 2026, en cours, sont
     * refusés, chacun avec sa raison ; une période déjà indiquée aussi, et
     * une écriture qui ne commence pas une période.
     */
    public function test_une_periode_d_avant_l_arrivee_pas_encore_terminee_ou_deja_indiquee_est_refusee(): void
    {
        $this->assertSame(['2026-11'], CreditsDeTva::periodesDeclarables($this->societe, CadenceDeLaTva::Mensuelle, '2026-12-15'));
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-10', -1000), LogicException::class, 'PILIQI ne compte pas cette période, d\'avant votre arrivée : déclarez le crédit du mois de votre arrivée (novembre 2026) ou d\'un mois suivant.');
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-12', -1000), LogicException::class, 'Ce mois n\'est pas encore terminé : sa déclaration se dépose après sa fin, et son crédit se déclare à partir du 01/01/2027.');
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-13', -1000), LogicException::class, 'Cette période ne se déclare pas : choisissez un mois de la liste.');

        $this->declarer($this->proprietaire, '2026-11', -1000);

        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-11', -2000), LogicException::class, DeclarerUnCreditDeTvaAction::REFUS_DEJA_DECLAREE);
        $this->assertSame(1, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * Le collaborateur à qui le client est confié confirme le crédit (son
     * nom et la date) ; le propriétaire du cabinet le corrige ensuite,
     * avec un motif obligatoire, ce qui vaut vérification ; le propriétaire
     * de la société qui le change le repasse « à vérifier » ; chaque geste
     * au journal d'audit avec l'ancienne et la nouvelle valeur.
     */
    public function test_le_cabinet_confirme_ou_corrige_avec_un_motif_et_le_proprietaire_qui_change_le_repasse_a_verifier(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $this->lierLeCabinet($cabinet, $this->magasin);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$this->magasin]);
        $credit = $this->declarer($this->proprietaire, '2026-11', -125000);

        $confirme = $this->geste(fn () => app(ConfirmerUnCreditDeTvaAction::class)->handle($collaborateur, $this->societe, (string) $credit->uuid));

        $this->assertSame([ValideePar::Cabinet, (int) $collaborateur->id], [$confirme->verifie_comme, $confirme->verifie_par]);
        $this->assertSame('Paul Lefèvre (cabinet '.$cabinet->raison_sociale.')', $confirme->verifie_par_nom, 'Le nom figé du collaborateur nomme son cabinet.');
        $this->assertSame(EtatDuCreditDeTva::Verifie, app(CreditsDeTva::class)->etat($this->societe, $confirme));
        $this->assertThrows(fn () => $this->geste(fn () => app(ConfirmerUnCreditDeTvaAction::class)->handle($collaborateur, $this->societe, (string) $credit->uuid)), LogicException::class, ConfirmerUnCreditDeTvaAction::REFUS_DEJA_VERIFIE);

        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
        $this->assertThrows(fn () => $this->geste(fn () => app(CorrigerUnCreditDeTvaAction::class)->handle($proprietaireDuCabinet, $this->societe, (string) $credit->uuid, -120000, '   ')), LogicException::class, CorrigerUnCreditDeTvaAction::REFUS_SANS_MOTIF);
        $this->assertThrows(fn () => $this->geste(fn () => app(CorrigerUnCreditDeTvaAction::class)->handle($proprietaireDuCabinet, $this->societe, (string) $credit->uuid, 500, 'Signe')), LogicException::class, DeclarerUnCreditDeTvaAction::REFUS_POSITIF);
        $corrige = $this->geste(fn () => app(CorrigerUnCreditDeTvaAction::class)->handle($proprietaireDuCabinet, $this->societe, (string) $credit->uuid, -120000, 'Ligne 27 de la CA3 de novembre'));

        $this->assertSame([-120000, 'Ligne 27 de la CA3 de novembre', ValideePar::Cabinet, true], [$corrige->solde, $corrige->motif, $corrige->verifie_comme, $corrige->estVerifie()]);
        $correction = $this->entreesDAudit($this->societe, ActionAuditee::CreditDeTvaCorrige);
        $this->assertCount(1, $correction);
        $this->assertSame([-125000, true, 'cabinet', null], [$correction[0]->anciennes_valeurs['solde'], $correction[0]->anciennes_valeurs['verifie'], $correction[0]->anciennes_valeurs['verifie_par'], $correction[0]->anciennes_valeurs['motif']]);
        $this->assertSame([-120000, true, 'cabinet', 'Ligne 27 de la CA3 de novembre'], [$correction[0]->nouvelles_valeurs['solde'], $correction[0]->nouvelles_valeurs['verifie'], $correction[0]->nouvelles_valeurs['verifie_par'], $correction[0]->nouvelles_valeurs['motif']]);

        $this->assertThrows(fn () => $this->geste(fn () => app(ModifierUnCreditDeTvaAction::class)->handle($proprietaireDuCabinet, $this->societe, (string) $credit->uuid, -110000)), AuthorizationException::class, ModifierUnCreditDeTvaAction::REFUS_DROIT);
        $this->assertThrows(fn () => $this->geste(fn () => app(CorrigerUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, (string) $credit->uuid, -110000, 'Erreur')), AuthorizationException::class, CorrigerUnCreditDeTvaAction::REFUS_DROIT);
        $modifie = $this->geste(fn () => app(ModifierUnCreditDeTvaAction::class)->handle($this->proprietaire, $this->societe, (string) $credit->uuid, -110000));

        $this->assertSame([-110000, null, null, null], [$modifie->solde, $modifie->verifie_le, $modifie->verifie_comme, $modifie->motif]);
        $this->assertSame(EtatDuCreditDeTva::AVerifier, app(CreditsDeTva::class)->etat($this->societe, $modifie));
        $this->assertSame(self::trie(['verifie' => true, 'solde' => -120000]), self::trie(array_intersect_key($this->entreesDAudit($this->societe, ActionAuditee::CreditDeTvaModifie)[0]->anciennes_valeurs ?? [], ['verifie' => 0, 'solde' => 0])));
        $this->assertCount(1, $this->entreesDAudit($this->societe, ActionAuditee::CreditDeTvaConfirme));
    }

    /**
     * L'expert-comptable déclaré par la société, qui a « Charges : voir,
     * ajouter, saisir et valider », indique lui-même le crédit : il naît
     * vérifié, par l'expert-comptable déclaré.
     */
    public function test_le_credit_saisi_par_l_expert_comptable_declare_nait_verifie(): void
    {
        $expert = $this->expertComptable($this->magasin);

        $credit = $this->declarer($expert, '2026-11', -48000);

        $this->assertSame([ValideePar::ExpertComptable, ValideePar::ExpertComptable, true], [$credit->saisi_comme, $credit->verifie_comme, $credit->estVerifie()]);
        $this->assertSame(EtatDuCreditDeTva::Verifie, app(CreditsDeTva::class)->etat($this->societe, $credit));
    }

    /**
     * Ni un administrateur (même avec toutes les autorisations), ni un
     * responsable, ni un vendeur, ni un observateur n'indiquent, ne
     * modifient, ne confirment ni ne corrigent le crédit ; rien ne s'écrit.
     */
    public function test_ni_un_administrateur_ni_un_responsable_ni_un_vendeur_ni_un_observateur_ne_touchent_au_credit(): void
    {
        $credit = $this->declarer($this->proprietaire, '2026-11', -125000);
        $comptes = [
            $this->administrateur($this->magasin),
            $this->responsable($this->magasin),
            $this->membre($this->magasin, RoleMagasin::Vendeur),
            $this->membre($this->magasin, RoleMagasin::Observateur),
        ];

        foreach ($comptes as $compte) {
            $this->assertThrows(fn () => $this->declarer($compte, '2026-11', -1000), AuthorizationException::class, GestesSurLeCreditDeTva::REFUS);
            $this->assertThrows(fn () => $this->geste(fn () => app(ModifierUnCreditDeTvaAction::class)->handle($compte, $this->societe, (string) $credit->uuid, -1000)), AuthorizationException::class, GestesSurLeCreditDeTva::REFUS);
            $this->assertThrows(fn () => $this->geste(fn () => app(ConfirmerUnCreditDeTvaAction::class)->handle($compte, $this->societe, (string) $credit->uuid)), AuthorizationException::class, GestesSurLeCreditDeTva::REFUS);
            $this->assertThrows(fn () => $this->geste(fn () => app(CorrigerUnCreditDeTvaAction::class)->handle($compte, $this->societe, (string) $credit->uuid, -1000, 'Motif')), AuthorizationException::class, GestesSurLeCreditDeTva::REFUS);
        }

        $this->assertSame([-125000, null], [$credit->fresh()->solde, $credit->fresh()->verifie_le]);
        $this->assertSame(1, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * Une demande répétée après une réponse perdue, avec le même jeton,
     * rend la déclaration déjà enregistrée, sans doublon ni seconde entrée
     * au journal d'audit ; le même jeton pour une autre demande est refusé.
     */
    public function test_une_demande_repetee_rend_le_credit_deja_enregistre_sans_doublon(): void
    {
        $jeton = (string) Str::uuid();

        $premier = $this->declarer($this->proprietaire, '2026-11', -125000, $jeton);
        $repete = $this->declarer($this->proprietaire, '2026-11', -125000, $jeton);

        $this->assertTrue($premier->is($repete));
        $this->assertSame(1, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
        $this->assertCount(1, $this->entreesDAudit($this->societe, ActionAuditee::CreditDeTvaDeclare));
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-11', -99000, $jeton), LogicException::class);
    }

    /**
     * Le crédit d'une société ne se lit ni ne se change jamais depuis une
     * autre : son uuid y est inconnu, et la lecture des déclarations ne
     * rend que celles de la société lue.
     */
    public function test_le_credit_d_une_autre_societe_ne_se_lit_ni_ne_se_change(): void
    {
        $credit = $this->declarer($this->proprietaire, '2026-11', -125000);
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre']);
        $autreSociete = $autre->societe()->firstOrFail();
        $autreProprietaire = $this->proprietaireDe($autre);
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        app(TenantContext::class)->clear();

        $this->assertThrows(fn () => $this->geste(fn () => app(ModifierUnCreditDeTvaAction::class)->handle($autreProprietaire, $autreSociete, (string) $credit->uuid, -1000)), LogicException::class, GestesSurLeCreditDeTva::CREDIT_INTROUVABLE);
        $this->assertThrows(fn () => $this->geste(fn () => app(ModifierUnCreditDeTvaAction::class)->handle($autreProprietaire, $this->societe, (string) $credit->uuid, -1000)), AuthorizationException::class, GestesSurLeCreditDeTva::REFUS);
        $this->assertSame([], app(CreditsDeTva::class)->declarations($autreSociete)->all());
        $this->assertNull(app(CreditsDeTva::class)->ancre($autreSociete));
        $this->assertSame(-125000, $credit->fresh()->solde);
    }

    /**
     * Hors du réel normal, rien ne se déclare : ni avant le choix du
     * régime, ni en franchise en base ; une société révoquée ne fait plus
     * que consulter.
     */
    public function test_hors_du_reel_normal_ou_dans_une_societe_revoquee_rien_ne_se_declare(): void
    {
        DB::table('societes')->where('id', $this->societe->id)->update(['regime_tva' => RegimeTva::FranchiseEnBase->value]);
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-11', -1000), LogicException::class, GestesSurLeCreditDeTva::REFUS_REGIME);

        DB::table('societes')->where('id', $this->societe->id)->update(['regime_tva' => RegimeTva::ReelNormalMensuel->value]);
        $this->revoquerLaSociete($this->societe->fresh());
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-11', -1000), LogicException::class, GestesSurLeCreditDeTva::REFUS_SOCIETE);
        $this->assertSame(0, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * Une société membre d'un assujetti unique (art. 256 C du CGI) ne
     * dépose pas sa propre déclaration de TVA : le représentant du groupe
     * déclare pour elle, avec le crédit du groupe (décision de Claude,
     * D76). PILIQI ne lui demande donc aucun crédit de départ (ni message,
     * ni étape, ni ligne de l'« À faire »), refuse de le déclarer avec un
     * message qui le dit, et la base le refuse aussi.
     */
    public function test_une_societe_membre_d_un_assujetti_unique_n_a_pas_de_credit_de_tva(): void
    {
        DB::table('societes')->where('id', $this->societe->id)->update(['membre_assujetti_unique' => true]);
        $membre = $this->societe->fresh();
        $messages = app(MessagesDuCreditDeTva::class);

        $this->assertFalse(CreditsDeTva::aLieuDansLa($membre));
        $this->assertNull($messages->message($membre, $this->proprietaire, false, '#credit-de-tva', fn (): LectureDeLaTva => LectureDeLaTva::AvecLeReport));
        $this->assertSame([], $messages->etape($this->proprietaire, $membre, '#credit-de-tva'));
        $this->assertSame([], $messages->chosesAFaire($this->proprietaire, $membre, '#credit-de-tva'));
        $this->assertThrows(fn () => $this->declarer($this->proprietaire, '2026-11', -125000), LogicException::class, GestesSurLeCreditDeTva::REFUS_ASSUJETTI_UNIQUE);

        DB::table('societes')->where('id', $this->societe->id)->update(['membre_assujetti_unique' => false]);
        $credit = $this->declarer($this->proprietaire, '2026-11', -125000);
        $ligne = (array) DB::table('credits_de_tva')->where('id', $credit->id)->first();
        unset($ligne['id']);
        DB::table('societes')->where('id', $this->societe->id)->update(['membre_assujetti_unique' => true]);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('credits_de_tva')->insert([...$ligne, 'uuid' => (string) Str::uuid(), 'periode' => '2026-12'])), QueryException::class, GardesDesCreditsDeTva::REFUS_SOCIETE);
        $this->assertSame(1, CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->count());
    }

    /**
     * La base garde chaque déclaration : elle ne s'efface jamais, sa
     * société, sa cadence et sa période ne changent pas, son solde n'est
     * jamais positif, et elle ne naît que dans une société au réel normal
     * de sa cadence, jamais dans un cabinet.
     */
    public function test_la_base_garde_chaque_declaration(): void
    {
        $credit = $this->declarer($this->proprietaire, '2026-11', -125000);
        $ligne = (array) DB::table('credits_de_tva')->where('id', $credit->id)->first();
        unset($ligne['id']);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('credits_de_tva')->where('id', $credit->id)->delete()), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('credits_de_tva')->where('id', $credit->id)->update(['periode' => '2026-12'])), QueryException::class, GardesDesCreditsDeTva::REFUS_FIGEE);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('credits_de_tva')->where('id', $credit->id)->update(['solde' => 100])), QueryException::class);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('credits_de_tva')->insert([...$ligne, 'uuid' => (string) Str::uuid(), 'periode' => '2026-10', 'cadence' => 'trimestrielle'])), QueryException::class, GardesDesCreditsDeTva::REFUS_SOCIETE);
        $this->assertSame([-125000, '2026-11'], [$credit->fresh()->solde, $credit->fresh()->periode]);
    }

    /**
     * Ces valeurs, dans l'ordre de leurs clés : le journal d'audit les garde
     * en JSON, qui ne retient pas leur ordre.
     *
     * @param  array<string, mixed>|null  $valeurs
     * @return array<string, mixed>
     */
    private static function trie(?array $valeurs): array
    {
        $valeurs ??= [];
        ksort($valeurs);

        return $valeurs;
    }

    private function declarer(User $auteur, string $periode, int $solde, ?string $jeton = null): CreditDeTva
    {
        return $this->geste(fn () => app(DeclarerUnCreditDeTvaAction::class)->handle($auteur, $this->societe, $periode, $solde, $jeton));
    }

    /**
     * Un geste sur le crédit, sans contexte avant ni après, comme depuis
     * un écran ; rend la déclaration relue.
     *
     * @param  Closure(): CreditDeTva  $geste
     */
    private function geste(Closure $geste): CreditDeTva
    {
        app(TenantContext::class)->clear();

        try {
            $credit = $geste();
        } finally {
            app(TenantContext::class)->clear();
        }

        return CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)->findOrFail($credit->getKey());
    }
}
