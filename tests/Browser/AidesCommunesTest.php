<?php

use App\Domain\Tenancy\Enums\RoleMagasin;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Les aides communes des tests en navigateur (voir TestCaseDuNavigateur et
 * Navigation) : la connexion de chaque compte, le poste reconnu, et le
 * filet qui fait échouer un test à la moindre erreur du serveur, du
 * JavaScript ou de la console.
 */

test('le responsable se connecte par son identifiant et arrive au tableau de bord de son magasin', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $responsable = $this->membre($magasin, RoleMagasin::Responsable, identite: ['name' => 'Rose Martin']);

    $onglet = $this->seConnecter($responsable);

    expect($onglet->chemin())->toBe('/app/'.$magasin->uuid);
    $onglet->voir('Bonjour')->voir('Boutique du Port');
});

test('le propriétaire se connecte par son adresse de connexion', function () {
    $magasin = $this->ouvrirUneSociete();
    $proprietaire = $this->proprietaireDe($magasin);
    $proprietaire->forceFill(['password' => self::MOT_DE_PASSE_CHOISI])->save();

    $onglet = $this->seConnecter($proprietaire, saisie: (string) $proprietaire->email);

    expect($onglet->chemin())->toStartWith('/app/'.$magasin->uuid);
});

test('un vendeur dont le mot de passe est encore son identifiant se connecte depuis un poste reconnu', function () {
    $magasin = $this->ouvrirUneSociete();
    [$vendeur, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
    $responsable = $this->responsable($magasin);
    $onglet = $this->naviguer('/app/login');

    $this->reconnaitreCeNavigateur($onglet, $magasin, $responsable, 'Caisse du comptoir');
    $this->seConnecter($vendeur, $identifiant, onglet: $onglet);

    expect($onglet->chemin())->toBe('/app/'.$magasin->uuid);
});

test('une erreur du serveur fait échouer la vérification', function () {
    Route::get('/essai-erreur-serveur', fn () => abort(500));

    expect(fn () => $this->naviguer('/essai-erreur-serveur'))->toThrow(AssertionFailedError::class, '500 GET /essai-erreur-serveur');
});

test('un refus attendu par le test ne fait pas échouer la vérification', function () {
    Route::get('/essai-refus', fn () => abort(403));
    $this->tolerer(403, '/essai-refus');

    $onglet = $this->naviguer('/essai-refus');

    $onglet->voir('403');
});

test('une erreur JavaScript fait échouer la vérification', function () {
    Route::get('/essai-erreur-javascript', fn () => '<html><body><script>throw new Error("panne du script");</script></body></html>');

    expect(fn () => $this->naviguer('/essai-erreur-javascript'))->toThrow(AssertionFailedError::class, 'panne du script');
});

test('un message d’erreur de la console fait échouer la vérification', function () {
    Route::get('/essai-console', fn () => '<html><body><script>console.error("message de la console");</script></body></html>');

    expect(fn () => $this->naviguer('/essai-console'))->toThrow(AssertionFailedError::class, 'message de la console');
});
