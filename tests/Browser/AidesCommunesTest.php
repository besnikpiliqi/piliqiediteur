<?php

use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use PHPUnit\Framework\AssertionFailedError;

/*
 * Les aides communes des tests en navigateur (voir TestCaseDuNavigateur,
 * Navigation et NoyauDUneRequeteNeuve) : la connexion de chaque compte, le
 * poste reconnu, les témoins que le navigateur garde quelle que soit
 * l'heure figée du serveur et qu'il envoie, lus comme PHP les lit, l'envoi
 * d'un fichier, et le filet qui fait
 * échouer un test à la moindre erreur du serveur, du JavaScript ou de la
 * console, ou à la moindre requête vers un autre site, que le navigateur
 * refuse.
 */

/**
 * Un fichier de ce nom et de ce contenu, sur le poste de la personne qui
 * l'envoie.
 */
function fichierDuPoste(string $nom, string $contenu): string
{
    $chemin = storage_path('framework/testing/envois-'.getmypid().'/'.$nom);
    File::ensureDirectoryExists(dirname($chemin));
    File::put($chemin, $contenu);

    return $chemin;
}

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

test('un second navigateur ouvert dans le même test ne trouve pas connecté le compte du premier', function () {
    $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port']);
    $this->seConnecter($this->responsable($magasin));

    expect($this->naviguer('/app/login')->chemin())->toBe('/app/login');
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

test('une requête vers un autre site est refusée par le navigateur et fait échouer la vérification', function () {
    Route::get('/essai-autre-site', fn () => '<html><body><p>Essai</p></body></html>');
    $onglet = $this->naviguer('/essai-autre-site');

    $reponse = $onglet->page()->evaluate('async () => fetch("https://example.org/", { mode: "no-cors" }).then(() => "joint", () => "refusé")');

    expect($reponse)->toBe('refusé')
        ->and(fn () => $onglet->verifier())->toThrow(AssertionFailedError::class, 'requête vers un autre site : https://example.org/');
});

test('un témoin posé pour longtemps reste dans le navigateur même daté depuis une horloge du serveur bien antérieure à l’heure réelle, et un témoin effacé s’efface', function () {
    $this->travelTo(self::aParis('2020-03-10 10:00:00'));
    Route::get('/essai-temoin', fn () => response('posé')->withCookie(cookie()->forever('temoin_de_l_essai', 'present', httpOnly: false)));
    Route::get('/essai-oubli', fn () => response('oublié')->withCookie(cookie()->forget('temoin_de_l_essai')));

    $onglet = $this->naviguer('/essai-temoin');

    expect($onglet->page()->evaluate('() => document.cookie'))->toContain('temoin_de_l_essai=present');

    $onglet->aller('/essai-oubli');

    expect($onglet->page()->evaluate('() => document.cookie'))->not->toContain('temoin_de_l_essai');
});

test('les témoins du navigateur arrivent à l’application comme PHP les lit, un nom en tableau compris', function () {
    Route::get('/essai-temoins-lus', fn (Request $requete) => '<html><body><p id="temoins">'.e((string) json_encode($requete->cookies->all())).'</p></body></html>');
    $onglet = $this->naviguer('/essai-temoins-lus');

    $onglet->page()->evaluate('() => { document.cookie = "tableau[0]=1; path=/"; document.cookie = "tableau=2; path=/"; document.cookie = "point.espace=x%2By+z; path=/"; document.cookie = "simple=a; path=/"; }');
    $onglet->aller('/essai-temoins-lus');

    expect(json_decode((string) $onglet->page()->evaluate('() => document.getElementById("temoins").textContent'), true))
        ->toBe(['tableau' => ['1'], 'point_espace' => 'x+y+z', 'simple' => 'a']);
});

test('un fichier envoyé par un formulaire arrive au serveur avec les autres champs', function () {
    Route::get('/essai-envoi', fn () => '<html><body><form method="post" action="/essai-envoi" enctype="multipart/form-data"><input type="file" name="pieces[]"><input name="nom" value="Jeanne Dupont"><button type="submit">Envoyer</button></form></body></html>');
    Route::post('/essai-envoi', fn (Request $requete) => 'Reçu '.$requete->file('pieces')[0]->getClientOriginalName().' ('.$requete->file('pieces')[0]->get().') de '.$requete->input('nom'));

    $this->naviguer('/essai-envoi')
        ->envoyerLeFichier(fichierDuPoste('facture.txt', 'Facture n° 12 : 18,00 €'))
        ->cliquerSur('Envoyer')
        ->voir('Reçu facture.txt (Facture n° 12 : 18,00 €) de Jeanne Dupont');
});

test('le propriétaire dont les courriers ne sont pas parvenus dépose une pièce par le champ de fichier de Filament', function () {
    Storage::fake(PiecesDeVerification::DISQUE);
    Storage::fake(FileUploadConfiguration::disk());
    $this->travelTo(self::aParis('2026-10-05 10:00:00'));
    $societe = $this->inscrireUneSociete();
    $this->travelTo(self::aParis('2026-10-20 10:00:00'));
    app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe);
    app(TenantContext::class)->clear();
    $contenu = '%PDF-1.4 Carte d\'identité de Jeanne Dupont';

    $this->seConnecter($societe->proprietaire, panneau: 'societe')
        ->cliquerSurLeLien('Vérification')
        ->cliquerSur('Déposer des pièces pour l\'éditeur')
        ->choisir('Pièce', TypePiece::Identite->value)
        ->envoyerLeFichier(fichierDuPoste('cni.pdf', $contenu))
        ->soumettre('Soumettre')
        ->voir('Pièce déposée : l\'éditeur est prévenu.');

    $piece = app(TenantContext::class)->runAsSociete($societe, fn (): PieceVerification => PieceVerification::query()->sole());
    expect($piece->type)->toBe(TypePiece::Identite)
        ->and($piece->empreinte)->toBe(hash('sha256', $contenu));
});
