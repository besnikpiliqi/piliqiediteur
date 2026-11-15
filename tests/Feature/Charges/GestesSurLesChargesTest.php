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
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Services\ChargesDeLaSociete;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Charges\Support\SaisieDeCharge;
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
}
