<?php

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Models\Demonstration;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\MaDemonstration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Browser\Support\Navigation;
use Tests\Feature\Demonstration\DemonstrationDuSiteReel;

/*
 * La carte « Ma démonstration » (lot DEMO-COPIE, B.2 à B.7), sur le
 * tableau de bord de l'espace de la société et sur celui du cabinet, pour
 * le seul propriétaire : créer et lire le lien une seule fois, renouveler
 * le lien avec son mot de passe, recommencer à zéro, ouvrir par le
 * bouton, dont le billet arrive au site de démonstration avec l'origine
 * du site réel ; un administrateur ne voit pas la carte ; sans adresse de
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

test('le billet du bouton part vers le site de démonstration avec l’origine du site réel', function () {
    $societe = $this->ouvrirUneSociete()->societe;
    $proprietaire = $societe->proprietaire()->firstOrFail();
    app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);
    app(TenantContext::class)->clear();
    $onglet = $this->seConnecter($proprietaire, 'password', panneau: 'societe')->aller('/societe/'.$societe->uuid);

    // Le site de démonstration du test : le même serveur, sous l'autre nom
    // de la boucle locale, donc une autre origine ; il dit l'en-tête Origin
    // qu'il reçoit, que le vrai site de démonstration compare à l'origine
    // exacte du site réel (C.5).
    $adresse = parse_url($onglet->page()->url());
    $origineDuSiteReel = $adresse['scheme'].'://'.$adresse['host'].':'.$adresse['port'];
    config(['demonstration.url' => $adresse['scheme'].'://'.($adresse['host'] === 'localhost' ? '127.0.0.1' : 'localhost').':'.$adresse['port']]);
    Route::post('/entrer-par-le-compte', fn (Request $requete): string => 'Billet reçu, origine : '.$requete->headers->get('Origin', 'aucune').'.');

    // Le formulaire de la carte s'ouvre dans le même onglet, pour que le
    // test suive la page du billet puis l'envoi qu'elle fait seule.
    $onglet->page()->evaluate("() => document.querySelector('form[action$=\"/ma-demonstration/ouvrir\"]').removeAttribute('target')");
    $onglet->cliquerSur('Ouvrir ma démonstration')
        ->attendreQue('document.body.innerText.includes('.Navigation::texte('Billet reçu').')', 'le billet n\'est pas arrivé au site de démonstration')
        ->voir('Billet reçu, origine : '.$origineDuSiteReel.'.');
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
