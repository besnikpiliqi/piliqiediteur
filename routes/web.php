<?php

use App\Http\Controllers\CodeDuCourrierController;
use App\Http\Controllers\ConfirmerInscriptionController;
use App\Http\Controllers\ConfirmerNouvelleAdresseController;
use App\Http\Controllers\RappelsDesPrestatairesController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => to_route('filament.app.tenant'));

// La confirmation d'une inscription (voir ConfirmerInscriptionController) :
// l'ouverture du lien ne consomme rien, le bouton confirme et fait partir
// le courrier recommandé.
Route::get('/inscription/{jeton}', [ConfirmerInscriptionController::class, 'afficher'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->name('inscription.afficher');
Route::post('/inscription/{jeton}', [ConfirmerInscriptionController::class, 'confirmer'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('inscription.confirmer');

// La page du code du courrier recommandé (voir CodeDuCourrierController),
// ouverte à tous, que le QR code du courrier ouvre avec le SIREN déjà
// rempli : la saisie du code valide la société.
Route::get('/code/{siren?}', [CodeDuCourrierController::class, 'afficher'])
    ->where('siren', '[0-9]{9}')
    ->name('code.afficher');
Route::post('/code', [CodeDuCourrierController::class, 'saisir'])
    ->middleware('throttle:10,1')
    ->name('code.saisir');

// Les rappels signés du prestataire d'envoi recommandé (voir
// RappelsDesPrestatairesController), sans session ni jeton CSRF.
Route::post('/rappels/recommande', [RappelsDesPrestatairesController::class, 'recommande'])
    ->middleware('throttle:60,1')
    ->name('rappels.recommande');

// La confirmation d'une nouvelle adresse de connexion demandée par le
// propriétaire dans son profil (voir ConfirmerNouvelleAdresseController) :
// l'ouverture du lien ne change rien, le bouton confirme.
Route::get('/profil/adresse/{jeton}', [ConfirmerNouvelleAdresseController::class, 'afficher'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->name('profil.adresse.afficher');
Route::post('/profil/adresse/{jeton}', [ConfirmerNouvelleAdresseController::class, 'confirmer'])
    ->where('jeton', '[A-Za-z0-9]{64}')
    ->middleware('throttle:10,1')
    ->name('profil.adresse.confirmer');
