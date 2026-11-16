<?php

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
| lisent que le registre (voir OuvrirLaCopie).
|
*/

Route::get('/d', [EntrerDansLaDemonstrationController::class, 'page'])->name('demonstration.lien');
Route::post('/entrer', [EntrerDansLaDemonstrationController::class, 'entrer'])->name('demonstration.entrer');
Route::post('/entrer-par-le-compte', [EntrerDansLaDemonstrationController::class, 'entrerParLeCompte'])->name('demonstration.entrer-par-le-compte');
Route::post('/interne/messages', MessagesDuSiteReelController::class)->name('demonstration.messages');
Route::get('/interne/etat', EtatDuSiteController::class)->name('demonstration.etat');
