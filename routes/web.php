<?php

use App\Http\Controllers\ConfirmerInscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('filament.app.tenant'));

// La confirmation d'une inscription (voir ConfirmerInscriptionController) :
// l'ouverture du lien ne consomme rien, le bouton confirme.
Route::get('/inscription/{jeton}', [ConfirmerInscriptionController::class, 'afficher'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->name('inscription.afficher');
Route::post('/inscription/{jeton}', [ConfirmerInscriptionController::class, 'confirmer'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('inscription.confirmer');
