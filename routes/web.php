<?php

use App\Http\Controllers\AccordDirigeantController;
use App\Http\Controllers\ConfirmerInscriptionController;
use App\Http\Controllers\RappelsDesPrestatairesController;
use App\Http\Controllers\RetourVerificationIdentiteController;
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

// Le retour du propriétaire depuis le prestataire de vérification
// d'identité (voir RetourVerificationIdentiteController).
Route::get('/verification/identite/retour', RetourVerificationIdentiteController::class)
    ->middleware('auth')
    ->name('verification.identite.retour');

// La demande d'accord d'un dirigeant (voir AccordDirigeantController) :
// l'ouverture du lien ne change rien, les boutons approuvent ou refusent.
Route::get('/accord-dirigeant/{jeton}', [AccordDirigeantController::class, 'afficher'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->name('accord-dirigeant.afficher');
Route::post('/accord-dirigeant/{jeton}', [AccordDirigeantController::class, 'demarrer'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('accord-dirigeant.demarrer');
Route::get('/accord-dirigeant/{jeton}/retour', [AccordDirigeantController::class, 'retour'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('accord-dirigeant.retour');
Route::post('/accord-dirigeant/{jeton}/refus', [AccordDirigeantController::class, 'refuser'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('accord-dirigeant.refuser');

// Les rappels signés des prestataires de la vérification (voir
// RappelsDesPrestatairesController), sans session ni jeton CSRF.
Route::post('/rappels/identite', [RappelsDesPrestatairesController::class, 'identite'])
    ->middleware('throttle:60,1')
    ->name('rappels.identite');
Route::post('/rappels/recommande', [RappelsDesPrestatairesController::class, 'recommande'])
    ->middleware('throttle:60,1')
    ->name('rappels.recommande');
