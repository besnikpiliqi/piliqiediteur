<?php

use App\Http\Controllers\Demonstration\ChoisirQuiVousEtesController;
use App\Http\Controllers\Demonstration\CourrielsDeLaDemonstrationController;
use App\Http\Controllers\Demonstration\EntrerDansLaDemonstrationController;
use App\Http\Controllers\Demonstration\EtatDuSiteController;
use App\Http\Controllers\Demonstration\MessagesDuSiteReelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Les routes du site de démonstration
|--------------------------------------------------------------------------
|
| Chargées seulement en mode démonstration (voir bootstrap/app.php ; lot
| DEMO-COPIE, A.2), toutes nommées demonstration.* : le site réel refuse
| de démarrer avec l'une d'elles. Les adresses d'entrée et les adresses
| internes sont hors du groupe web : sans session ni jeton CSRF, elles ne
| lisent que le registre (voir OuvrirLaCopie). « Choisissez qui vous
| êtes », le changement de compte, « Quitter » et « E-mails qui seraient
| partis » sont du groupe web, dans la copie du témoin (C.8 et C.9).
|
*/

Route::get('/d', [EntrerDansLaDemonstrationController::class, 'page'])->name('demonstration.lien');
Route::post('/entrer', [EntrerDansLaDemonstrationController::class, 'entrer'])->name('demonstration.entrer');
Route::post('/entrer-par-le-compte', [EntrerDansLaDemonstrationController::class, 'entrerParLeCompte'])->name('demonstration.entrer-par-le-compte');
Route::post('/interne/messages', MessagesDuSiteReelController::class)->name('demonstration.messages');
Route::get('/interne/etat', EtatDuSiteController::class)->name('demonstration.etat');

Route::middleware('web')->group(function (): void {
    Route::get('/choisir', [ChoisirQuiVousEtesController::class, 'page'])->name('demonstration.choisir');
    Route::post('/demonstration/changer-de-compte', [ChoisirQuiVousEtesController::class, 'changer'])->name('demonstration.changer-de-compte');
    Route::post('/demonstration/quitter', [ChoisirQuiVousEtesController::class, 'quitter'])->name('demonstration.quitter');
    Route::get('/demonstration/courriels', [CourrielsDeLaDemonstrationController::class, 'liste'])->name('demonstration.courriels');
    Route::get('/demonstration/courriels/{courriel}', [CourrielsDeLaDemonstrationController::class, 'fiche'])->whereNumber('courriel')->name('demonstration.courriel');
    Route::get('/demonstration/courriels/{courriel}/pieces/{rang}', [CourrielsDeLaDemonstrationController::class, 'piece'])->whereNumber(['courriel', 'rang'])->name('demonstration.courriel.piece');
});
