<?php

namespace Tests\Feature\Charges;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Charges\Actions\CorrigerUneChargeAction;
use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Actions\RenvoyerUneChargeAction;
use App\Domain\Charges\Actions\SaisirUneChargeAction;
use App\Domain\Charges\Actions\SupprimerUneChargeAction;
use App\Domain\Charges\Actions\ValiderUneChargeAction;
use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Services\TypesDeCharge;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Les gestes sur les charges de la société et leurs états (décision de
 * l'éditeur du 14/11/2026, lot CHARGES) : le dépôt, avec ou sans ses
 * chiffres ; la saisie ; la validation, qui exige la pièce ; le renvoi au
 * client avec un mot, puis sa correction ; la correction d'une charge
 * validée, avec un motif ; la suppression avant la validation seulement ;
 * les doublons ; les jetons des demandes répétées ; le journal d'audit.
 */
class GestesSurLesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $magasin;

    private Societe $societe;

    private User $proprietaire;

    private User $collaborateur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        $this->magasin = $this->ouvrirUneSociete();
        $this->societe = $this->magasin->societe;
        $this->proprietaire = $this->proprietaireDe($this->magasin);
        $this->collaborateur = $this->collaborateurDuCabinetLie($this->magasin);
        app(TenantContext::class)->clear();
    }

    public function test_le_client_depose_une_charge_a_valider_ou_sa_seule_piece(): void
    {
        $chiffree = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2026-05']));
        $this->assertSame(EtatDeLaCharge::AValider, $chiffree->etat);
        $this->assertSame([120000, 24000, 144000, 24000], [$chiffree->total_ht, $chiffree->total_tva, $chiffree->total_ttc, $chiffree->total_tva_deductible]);
        $this->assertSame(1, LigneDeCharge::query()->withoutGlobalScopes()->where('charge_id', $chiffree->getKey())->count());
        $this->assertSame(1, PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $chiffree->getKey())->count());

        $piece = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:electricite', null));
        $this->assertSame(EtatDeLaCharge::PieceSansChiffres, $piece->etat);
        $this->assertSame(0, LigneDeCharge::query()->withoutGlobalScopes()->where('charge_id', $piece->getKey())->count());
        $this->assertSame(0, $piece->total_ttc);

        $this->assertThrows(fn () => $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:gaz', null), avecLaPiece: false), InvalidArgumentException::class, DeposerUneChargeAction::REFUS_SANS_RIEN);
    }

    public function test_une_charge_sans_piece_ne_se_valide_pas(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe, avecLaPiece: false);
        $this->assertSame(EtatDeLaCharge::AValider, $charge->etat);

        $this->assertThrows(fn () => app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid), LogicException::class, ValiderUneChargeAction::REFUS_SANS_PIECE);
        $this->assertSame(EtatDeLaCharge::AValider, $charge->fresh()?->etat);

        $pieceSansChiffres = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:eau', null));
        $this->assertThrows(fn () => app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $pieceSansChiffres->uuid), LogicException::class, ValiderUneChargeAction::REFUS_ETAT);
    }

    public function test_le_comptable_renvoie_la_charge_et_le_client_la_corrige(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:publicite', null));

        $this->assertThrows(fn () => app(RenvoyerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, '   '), InvalidArgumentException::class, RenvoyerUneChargeAction::REFUS_SANS_MOT);
        $this->assertThrows(fn () => app(RenvoyerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid, 'Illisible'), AuthorizationException::class, RenvoyerUneChargeAction::REFUS_DROIT);

        $renvoyee = app(RenvoyerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, 'Pièce illisible');
        $this->assertSame(EtatDeLaCharge::Renvoyee, $renvoyee->etat);
        $this->assertSame('Pièce illisible', $renvoyee->motif_renvoi);

        $corrigee = app(SaisirUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid, $this->saisie('piliqi:publicite', '180.00', champs: ['fournisseur' => 'Imprimerie', 'numero_piece' => 'P-77']), $this->piece('facture-lisible.pdf'));
        $this->assertSame(EtatDeLaCharge::AValider, $corrigee->etat);
        $this->assertNull($corrigee->motif_renvoi);
        $this->assertSame('facture-lisible.pdf', PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $charge->getKey())->value('nom'));
        $this->assertCount(1, Storage::disk(PiecesDesCharges::DISQUE)->allFiles());
        $this->assertCount(1, $this->entreesDAudit($this->societe, ActionAuditee::ChargeRenvoyee));
    }

    public function test_le_comptable_qui_ecrit_les_chiffres_d_une_piece_la_valide(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:honoraires', null));

        $saisie = app(SaisirUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, $this->saisie('piliqi:honoraires', '450.00', champs: ['fournisseur' => 'Cabinet', 'numero_piece' => 'H-0312']));

        $this->assertSame(EtatDeLaCharge::Validee, $saisie->etat);
        $this->assertSame(9000, $saisie->total_tva_deductible);
    }

    public function test_une_charge_validee_ne_se_saisit_plus_et_se_corrige_avec_un_motif(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid);

        $this->assertThrows(fn () => app(SaisirUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid, $this->saisie(ht: '1000.00')), LogicException::class, SaisirUneChargeAction::REFUS_VALIDEE);
        $this->assertThrows(fn () => app(CorrigerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $charge->uuid, $this->saisie(ht: '1000.00'), 'Erreur'), AuthorizationException::class, CorrigerUneChargeAction::REFUS_DROIT);
        $this->assertThrows(fn () => app(CorrigerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, $this->saisie(ht: '1000.00'), ' '), InvalidArgumentException::class, CorrigerUneChargeAction::REFUS_SANS_MOTIF);

        $corrigee = app(CorrigerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, $this->saisie(ht: '1000.00', champs: ['mois_tva' => '2026-04']), 'HT recopié de travers');

        $this->assertSame(EtatDeLaCharge::Validee, $corrigee->etat);
        $this->assertSame(1, $corrigee->corrections);
        $this->assertSame('2026-04', $corrigee->mois_tva);
        $this->assertSame(20000, $corrigee->total_tva);
        $this->assertSame([1], LigneDeCharge::query()->withoutGlobalScopes()->where('charge_id', $charge->getKey())->pluck('version')->all());
        [$entree] = $this->entreesDAudit($this->societe, ActionAuditee::ChargeCorrigee);
        $this->assertSame('HT recopié de travers', $entree->nouvelles_valeurs['motif'] ?? null);
        $this->assertSame(24000, $entree->anciennes_valeurs['total_tva'] ?? null);
    }

    public function test_seuls_qui_l_a_deposee_et_le_comptable_suppriment_une_charge_non_validee(): void
    {
        $administrateur = $this->administrateur($this->magasin, autorisationsMagasin: [PermissionRegistry::CHARGES_AJOUTER]);
        app(TenantContext::class)->clear();
        $deLAdministrateur = $this->deposer($administrateur, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-1']));
        $duProprietaire = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:gaz', champs: ['numero_piece' => 'G-1']));

        $this->assertThrows(fn () => app(SupprimerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $deLAdministrateur->uuid), AuthorizationException::class, SupprimerUneChargeAction::REFUS_DROIT);

        app(SupprimerUneChargeAction::class)->handle($administrateur, $this->societe, $deLAdministrateur->uuid);
        app(SupprimerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $duProprietaire->uuid);

        $this->assertSame(0, Charge::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, PieceDeCharge::query()->withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk(PiecesDesCharges::DISQUE)->allFiles());
        $this->assertCount(2, $this->entreesDAudit($this->societe, ActionAuditee::ChargeSupprimee));
    }

    public function test_une_charge_validee_ne_s_efface_jamais(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid);

        $this->assertThrows(fn () => app(SupprimerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid), LogicException::class, SupprimerUneChargeAction::REFUS_VALIDEE);
        $this->assertNotNull($charge->fresh());
    }

    public function test_un_doublon_du_fournisseur_et_du_numero_est_refuse_et_un_doublon_probable_se_confirme(): void
    {
        $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2026-05']));

        $this->assertThrows(fn () => $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['fournisseur' => 'sci  DES halles', 'numero_piece' => 'q-2026-05'])), InvalidArgumentException::class, ChargesDeLaSociete::REFUS_DOUBLON);

        $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:carburant', '60.00', champs: ['fournisseur' => 'Station', 'numero_piece' => null]));
        $this->assertThrows(fn () => $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:carburant', '60.00', champs: ['fournisseur' => 'Station', 'numero_piece' => null])), ConfirmationRequiseException::class, 'est déjà saisie');

        $confirmee = $this->deposer($this->proprietaire, $this->societe, SaisieDeCharge::duFormulaire(['type' => 'piliqi:carburant', 'fournisseur' => 'Station', 'date_piece' => '2026-05-02', 'lignes' => [['taux' => 2000, 'ht' => '60.00']]], confirmeLeDoublon: true));
        $this->assertSame(EtatDeLaCharge::AValider, $confirmee->etat);
    }

    public function test_l_avertissement_dit_les_charges_du_meme_type_deja_saisies_ce_mois(): void
    {
        $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2026-05']));
        $acces = app(DroitsSurLesCharges::class)->pour($this->proprietaire, $this->societe);
        $this->assertNotNull($acces);

        $dejaSaisies = app(ChargesDeLaSociete::class)->dejaSaisiesCeMois($acces, 'piliqi:loyer', '2026-05');

        $this->assertSame([['etat' => EtatDeLaCharge::AValider, 'fournisseur' => 'SCI des Halles', 'numero_piece' => 'Q-2026-05']], $dejaSaisies);
        $this->assertSame([], app(ChargesDeLaSociete::class)->dejaSaisiesCeMois($acces, 'piliqi:loyer', '2026-06'));
        $this->assertSame([], app(ChargesDeLaSociete::class)->dejaSaisiesCeMois($acces, 'piliqi:electricite', '2026-05'));
    }

    public function test_une_tva_qui_s_ecarte_de_plus_d_un_centime_se_confirme_et_se_marque(): void
    {
        $ligne = ['taux' => 2000, 'ht' => '100.00', 'tva' => '20.01', 'ttc' => '120.01'];
        $this->deposer($this->proprietaire, $this->societe, SaisieDeCharge::duFormulaire(['type' => 'piliqi:loyer', 'fournisseur' => 'SCI', 'numero_piece' => 'A', 'date_piece' => '2026-05-02', 'lignes' => [$ligne]]));

        $ecart = ['taux' => 2000, 'ht' => '100.00', 'tva' => '20.50', 'ttc' => '120.50'];
        $this->assertThrows(fn () => $this->deposer($this->proprietaire, $this->societe, SaisieDeCharge::duFormulaire(['type' => 'piliqi:loyer', 'fournisseur' => 'SCI', 'numero_piece' => 'B', 'date_piece' => '2026-05-02', 'lignes' => [$ecart]])), ConfirmationRequiseException::class, 'la TVA saisie, 20,50 €, s\'écarte de 0,50 €');

        $confirmee = $this->deposer($this->proprietaire, $this->societe, SaisieDeCharge::duFormulaire(['type' => 'piliqi:loyer', 'fournisseur' => 'SCI', 'numero_piece' => 'B', 'date_piece' => '2026-05-02', 'lignes' => [$ecart]], confirmeLesEcarts: true));
        $this->assertTrue($confirmee->tva_a_verifier);
        $this->assertSame(2050, $confirmee->total_tva);
    }

    public function test_une_demande_repetee_rend_la_meme_charge_et_un_autre_contenu_sous_le_meme_jeton_est_refuse(): void
    {
        $jeton = (string) Str::uuid();
        $une = app(DeposerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-1']), $this->piece(), $jeton);
        app(TenantContext::class)->clear();
        $repetee = app(DeposerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-1']), $this->piece(), $jeton);

        $this->assertSame($une->getKey(), $repetee->getKey());
        $this->assertSame(1, Charge::query()->withoutGlobalScopes()->count());
        $this->assertThrows(fn () => app(DeposerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-2']), $this->piece(), $jeton), LogicException::class, ActionsUniques::REFUS);

        $validation = (string) Str::uuid();
        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $une->uuid, $validation);
        $this->assertSame($une->getKey(), app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $une->uuid, $validation)->getKey());
    }

    public function test_chaque_geste_s_inscrit_au_journal_d_audit_de_la_societe(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid);

        [$depot] = $this->entreesDAudit($this->societe, ActionAuditee::ChargeDeposee);
        [$validation] = $this->entreesDAudit($this->societe, ActionAuditee::ChargeValidee);
        [$piece] = $this->entreesDAudit($this->societe, ActionAuditee::PieceDeChargeDeposee);

        $this->assertSame('a_valider', $depot->nouvelles_valeurs['etat'] ?? null);
        $this->assertSame(144000, $depot->nouvelles_valeurs['total_ttc'] ?? null);
        $this->assertSame('cabinet', $validation->nouvelles_valeurs['validee_par'] ?? null);
        $this->assertSame('quittance.pdf', $piece->nouvelles_valeurs['piece']['nom'] ?? null);
        $this->assertSame('operations', $depot->categorie->value);
    }

    /**
     * Le comptable écrit les chiffres d'une charge à valider qui a déjà les
     * siens, en corrigeant la TVA déductible (un cadeau de moins de 73 €),
     * puis la valide d'un même geste : les lignes s'écrivent avant la
     * validation, que la base ne laisse plus les changer ensuite.
     */
    public function test_le_comptable_ecrit_les_chiffres_d_une_charge_qui_a_deja_les_siens_et_la_valide(): void
    {
        $cadeau = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:cadeaux', '50.00', champs: ['fournisseur' => 'Chocolatier', 'numero_piece' => 'C-1']));
        $this->assertSame([EtatDeLaCharge::AValider, 0], [$cadeau->etat, $cadeau->total_tva_deductible]);

        $validee = $this->saisir($this->collaborateur, $this->societe, $cadeau, $this->saisie('piliqi:cadeaux', champs: ['fournisseur' => 'Chocolatier', 'numero_piece' => 'C-1', 'lignes' => [['taux' => 2000, 'ht' => '50.00', 'tva_deductible' => '10.00']]]));

        $this->assertSame([EtatDeLaCharge::Validee, 1000, 1000], [$validee->etat, $validee->total_tva, $validee->total_tva_deductible]);
        $this->assertSame([[0, 1000]], LigneDeCharge::query()->withoutGlobalScopes()->where('charge_id', $cadeau->getKey())->get()->map(fn (LigneDeCharge $ligne): array => [$ligne->version, $ligne->tva_deductible])->all());
    }

    public function test_le_comptable_ecrit_les_chiffres_d_une_charge_renvoyee_qui_a_ses_lignes_et_la_valide(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-1']));
        app(RenvoyerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, 'Mauvais montant');

        $validee = $this->saisir($this->collaborateur, $this->societe, $charge, $this->saisie(ht: '1100.00', champs: ['numero_piece' => 'Q-1']));

        $this->assertSame([EtatDeLaCharge::Validee, null, 22000], [$validee->etat, $validee->motif_renvoi, $validee->total_tva]);
    }

    /**
     * Sur une pièce sans chiffres, le comptable écrit les chiffres en
     * remplaçant la pièce : la pièce d'avant s'efface avant la validation,
     * et son fichier aussi.
     */
    public function test_le_comptable_ecrit_les_chiffres_d_une_piece_sans_chiffres_en_remplacant_sa_piece(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe, $this->saisie('piliqi:eau', null));

        $validee = $this->saisir($this->collaborateur, $this->societe, $charge, $this->saisie('piliqi:eau', '40.00', taux: 550, champs: ['fournisseur' => 'Eaux du Nord', 'numero_piece' => 'E-7']), $this->piece('facture-eau.pdf'));

        $this->assertSame(EtatDeLaCharge::Validee, $validee->etat);
        $this->assertSame(['facture-eau.pdf'], PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $charge->getKey())->pluck('nom')->all());
        $this->assertCount(1, Storage::disk(PiecesDesCharges::DISQUE)->allFiles());
    }

    public function test_l_expert_comptable_declare_ecrit_les_chiffres_d_une_charge_qui_a_les_siens_et_la_valide(): void
    {
        $autre = $this->ouvrirUneSociete(['nom' => 'Déclarante']);
        $expert = $this->expertComptable($autre);
        $proprietaire = $this->proprietaireDe($autre);
        app(TenantContext::class)->clear();
        $charge = $this->deposer($proprietaire, $autre->societe, $this->saisie(champs: ['numero_piece' => 'Q-1']));

        $validee = $this->saisir($expert, $autre->societe, $charge, $this->saisie(ht: '1000.00', champs: ['numero_piece' => 'Q-1', 'lignes' => [['taux' => 2000, 'ht' => '1000.00', 'tva_deductible' => '150.00']]]));

        $this->assertSame([EtatDeLaCharge::Validee, ValideePar::ExpertComptable, 15000], [$validee->etat, $validee->validee_par, $validee->total_tva_deductible]);
    }

    /**
     * Une saisie, puis une correction, qui changent le magasin de la charge
     * s'inscrivent au journal d'audit dans la chaîne du magasin qu'elle a
     * ensuite, avec l'ancien et le nouveau magasin.
     */
    public function test_une_saisie_et_une_correction_qui_changent_le_magasin_l_inscrivent_au_journal(): void
    {
        $paris = $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Paris', 'code' => 'PARIS']);
        app(TenantContext::class)->clear();
        $charge = $this->deposer($this->proprietaire, $this->societe, $this->saisie(champs: ['numero_piece' => 'Q-1', 'magasin' => (string) $this->magasin->uuid]));

        $this->saisir($this->proprietaire, $this->societe, $charge, $this->saisie(champs: ['numero_piece' => 'Q-1', 'magasin' => (string) $paris->uuid]));
        [$saisie] = $this->entreesDAudit($this->societe, ActionAuditee::ChargeSaisie);
        $this->assertSame($paris->getKey(), $saisie->magasin_id);
        $this->assertSame([$this->magasin->code, 'PARIS'], [$saisie->anciennes_valeurs['magasin']['code_magasin'] ?? null, $saisie->nouvelles_valeurs['magasin']['code_magasin'] ?? null]);

        app(ValiderUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid);
        app(TenantContext::class)->clear();
        app(CorrigerUneChargeAction::class)->handle($this->collaborateur, $this->societe, $charge->uuid, $this->saisie(champs: ['numero_piece' => 'Q-1', 'magasin' => (string) $this->magasin->uuid]), 'Mauvais magasin');
        [$correction] = $this->entreesDAudit($this->societe, ActionAuditee::ChargeCorrigee);
        $this->assertSame($this->magasin->getKey(), $correction->magasin_id);
        $this->assertSame(['PARIS', $this->magasin->code], [$correction->anciennes_valeurs['magasin']['code_magasin'] ?? null, $correction->nouvelles_valeurs['magasin']['code_magasin'] ?? null]);
    }

    public function test_un_type_de_la_societe_forge_est_refuse_sans_interroger_la_base(): void
    {
        $this->assertThrows(fn () => app(TypesDeCharge::class)->resoudre($this->societe, 'societe:pas-un-uuid'), InvalidArgumentException::class, TypesDeCharge::REFUS_TYPE);
        $this->assertThrows(fn () => $this->deposer($this->proprietaire, $this->societe, $this->saisie('societe:abc')), InvalidArgumentException::class, TypesDeCharge::REFUS_TYPE);
    }

    private function saisir(User $auteur, Societe $societe, Charge $charge, SaisieDeCharge $saisie, ?PieceDeposee $piece = null): Charge
    {
        app(TenantContext::class)->clear();
        $saisie = app(SaisirUneChargeAction::class)->handle($auteur, $societe, $charge->uuid, $saisie, $piece);
        app(TenantContext::class)->clear();

        return Charge::query()->withoutGlobalScopes()->findOrFail($saisie->getKey());
    }
}
