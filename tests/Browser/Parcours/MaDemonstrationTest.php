<?php

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\MaDemonstration;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Demonstration\DemonstrationDuSiteReel;

/*
 * La carte « Ma démonstration » (lot DEMO-COPIE, B.2 à B.7), sur le
 * tableau de bord de l'espace de la société et sur celui du cabinet, pour
 * le seul propriétaire : créer et lire le lien une seule fois, renouveler
 * le lien avec son mot de passe, recommencer à zéro, ouvrir par le
 * bouton ; un administrateur ne voit pas la carte ; sans adresse de
 * connexion confirmée, la carte mène au profil.
 */

uses(DemonstrationDuSiteReel::class);

beforeEach(function () {
    Mail::fake();
    $this->reglerLeSiteDeDemonstration();
});

test('le propriétaire d’une société crée sa démonstration et lit son lien une seule fois', function () {
    $societe = $this->ouvrirUneSociete()->societe;

    $this->seConnecter($societe->proprietaire()->firstOrFail(), 'password', panneau: 'societe')
        ->aller('/societe/'.$societe->uuid)
        ->voir('Ma démonstration')
        ->cliquerSur('Créer ma démonstration')
        ->soumettre('Créer ma démonstration')
        ->voir('Le lien de votre démonstration')
        ->voir(MaDemonstration::PHRASE_DU_LIEN)
        ->voir(self::ORIGINE_DE_LA_DEMONSTRATION.'/d#');

    expect(Demonstration::query()->withoutGlobalScopes()->sole()->etat)->toBe(EtatDeLaDemonstration::Prete);
});

test('le propriétaire renouvelle le lien avec son mot de passe, puis remet sa démonstration à zéro', function () {
    $societe = $this->ouvrirUneSociete()->societe;
    $proprietaire = $societe->proprietaire()->firstOrFail();
    app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
    app(TenantContext::class)->clear();

    $this->seConnecter($proprietaire, 'password', panneau: 'societe')
        ->aller('/societe/'.$societe->uuid)
        ->cliquerSur('Renouveler le lien')
        ->remplir('Votre mot de passe', 'password')
        ->soumettre('Renouveler le lien')
        ->voir('Le lien de votre démonstration')
        ->aller('/societe/'.$societe->uuid)
        ->cliquerSur('Recommencer à zéro')
        ->soumettre('Recommencer à zéro')
        ->voir('Votre démonstration repart de zéro');

    $demonstration = Demonstration::query()->withoutGlobalScopes()->sole();
    expect($demonstration->generation_du_lien)->toBe(2)
        ->and($demonstration->recommencee_le)->not->toBeNull();
});

test('le propriétaire ouvre sa démonstration par le bouton, dans un nouvel onglet', function () {
    $societe = $this->ouvrirUneSociete()->societe;
    $proprietaire = $societe->proprietaire()->firstOrFail();
    app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
    app(TenantContext::class)->clear();

    $this->seConnecter($proprietaire, 'password', panneau: 'societe')
        ->aller('/societe/'.$societe->uuid)
        ->cliquerSur('Ouvrir ma démonstration')
        ->attendre()
        ->aller('/societe/'.$societe->uuid)
        ->voir('Ma démonstration');

    expect(EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::DemonstrationOuverteParLeBouton->value)->count())->toBe(1);
});

test('le propriétaire d’un cabinet crée la démonstration de son cabinet', function () {
    $cabinet = $this->ouvrirUnCabinet();

    $this->seConnecter($this->proprietaireDuCabinet($cabinet), panneau: 'cabinet')
        ->aller('/cabinet/'.$cabinet->uuid)
        ->voir('Un cabinet fictif, déjà rempli')
        ->cliquerSur('Créer ma démonstration')
        ->soumettre('Créer ma démonstration')
        ->voir('Le lien de votre démonstration');

    expect(Demonstration::query()->withoutGlobalScopes()->sole()->type_d_activite->value)->toBe('cabinet');
});

test('un administrateur ne voit pas la carte, et sans adresse confirmée la carte mène au profil', function () {
    $magasin = $this->ouvrirUneSociete();
    $societe = $magasin->societe;

    $onglet = $this->seConnecter($this->administrateur($magasin), panneau: 'societe')
        ->aller('/societe/'.$societe->uuid)
        ->voir('Tableau de bord')
        ->nePasVoir('Créer ma démonstration')
        ->seDeconnecter();

    $proprietaire = $societe->proprietaire()->firstOrFail();
    $proprietaire->forceFill(['email' => null, 'email_verified_at' => null])->save();

    $this->seConnecter($proprietaire, 'password', onglet: $onglet, panneau: 'societe')
        ->aller('/societe/'.$societe->uuid)
        ->voir(ConditionsDeLaDemonstration::MESSAGE_ADRESSE_A_CONFIRMER)
        ->cliquerSurLeLien('Renvoyer le lien de confirmation')
        ->voir('Adresse e-mail');
});
