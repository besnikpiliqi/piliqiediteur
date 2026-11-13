<?php

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use Database\Factories\SocieteFactory;
use Illuminate\Support\Facades\Mail;

/*
 * Le balayage du panneau d'un magasin par le cabinet lié (son propriétaire
 * et le collaborateur à qui le client est confié), par l'expert-comptable
 * que la société déclare à la place de ce cabinet, et du magasin de
 * démonstration d'une société qui attend son code, par son propriétaire et
 * par ses comptes d'entraînement : chaque page que ses liens atteignent et
 * chaque action visible de chacune, ouverte puis fermée sans rien valider,
 * sans aucune erreur (voir Balayeur et TestCaseDuNavigateur::balayer()). La
 * société de test a tous ses rôles et de quoi remplir chaque page (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

test('le propriétaire du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->proprietaireDuCabinet, panneau: 'cabinet');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures', '/app/profile')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le collaborateur du cabinet lié ouvre chaque page et chaque action du magasin de son client sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $onglet = $this->seConnecter($societe->collaborateur, panneau: 'cabinet');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('l’expert-comptable déclaré par la société ouvre chaque page et chaque action du magasin sans erreur', function () {
    $societe = $this->societeComplete();
    $magasin = '/app/'.$societe->magasin->uuid;
    $siren = SocieteFactory::sirenFictif();
    app(RepertoireSirene::class)->exercer($siren, ControleSirene::CODE_ACTIVITES_COMPTABLES);
    Mail::fake();
    $marc = app(DeclarerSonExpertComptableAction::class)->handle($societe->proprietaire, $societe->societe, [
        'siren' => $siren,
        'name' => 'Marc Petit',
        'email' => 'marc.petit@petit-associes.test',
        'email_confirmation' => 'marc.petit@petit-associes.test',
        'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet(TypeDActivite::Commerce)),
        'remplacer' => true,
    ], self::MOT_DE_PASSE_CHOISI);
    app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($this->jetonDuDernierLienDExpertComptable(), self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI);
    app(TenantContext::class)->clear();

    $pages = $this->balayer($this->seConnecter($marc->refresh()), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/factures', $magasin.'/la-societe', '/app/profile')->not->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le propriétaire d’une société qui attend son code ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;
    $onglet = $this->seConnecter($demonstration->proprietaire, panneau: 'societe');

    $pages = $this->balayer($onglet, $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/equipe', $magasin.'/caisse');
});

test('le responsable d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($demonstration->responsable), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse', $magasin.'/equipe');
});

test('le vendeur d’entraînement ouvre chaque page et chaque action du magasin de démonstration sans erreur', function () {
    $demonstration = $this->demonstration();
    $magasin = '/app/'.$demonstration->magasin->uuid;

    $pages = $this->balayer($this->seConnecter($demonstration->vendeur), $magasin, [$magasin], __FILE__);

    expect($pages)->toContain($magasin.'/caisse');
});
