<?php

use App\Domain\Tenancy\Enums\TypeDActivite;

/*
 * Le balayage de chaque copie du site de démonstration (lot DEMO-COPIE,
 * N24 bis) : dans une copie de chaque type, chaque compte du bandeau
 * « Vous êtes : », choisi sur « Choisissez qui vous êtes », ouvre chaque
 * page que ses liens atteignent dans les espaces de la copie (espace de la
 * société, panneau des magasins, espace du cabinet) et chaque action
 * visible de chacune, ouverte puis fermée sans rien valider, sans aucune
 * erreur (voir Balayeur et PiloteLeNavigateur::balayer()), le bandeau
 * restant sur le compte choisi. Le modèle de test de chaque type est celui
 * de DemonstrationTestCase::semerLeModeleDeTest().
 */

dataset('comptes du bandeau de chaque copie', [
    'le propriétaire de la société de commerce' => [TypeDActivite::Commerce, null],
    'la responsable des Halles' => [TypeDActivite::Commerce, 'Camille Laurent'],
    'la vendeuse des Halles' => [TypeDActivite::Commerce, 'Inès Garcia'],
    'l’observatrice des Halles' => [TypeDActivite::Commerce, 'Élodie Fabre'],
    'la vendeuse de La République' => [TypeDActivite::Commerce, 'Sarah Petit'],
    'le propriétaire de la société de services' => [TypeDActivite::Services, null],
    'la responsable de la société de services' => [TypeDActivite::Services, 'Claire Dubois'],
    'le vendeur de la société de services' => [TypeDActivite::Services, 'Maxime Henry'],
    'la propriétaire du cabinet' => [TypeDActivite::Cabinet, null],
    'le collaborateur du cabinet' => [TypeDActivite::Cabinet, 'Paul Lefèvre'],
]);

test('chaque compte du bandeau ouvre chaque page et chaque action de sa copie sans erreur', function (TypeDActivite $type, ?string $nom) {
    ['secret' => $secret] = $this->copieDeTest($type);
    $onglet = $this->suivreLeLien($secret);
    $onglet->cliquer($nom === null ? '[data-commencer-en-tant-que-proprietaire]' : '[data-compte-du-choix]:has(strong:text-is("'.$nom.'"))')
        ->attendreQue('document.querySelector("[data-bandeau-de-la-demonstration]") !== null && location.pathname !== "/choisir"', 'le choix du compte n\'a ouvert aucun espace')
        ->attendre();
    $choisi = (string) $onglet->page()->evaluate('() => document.querySelector("[data-menu-vous-etes] summary strong").innerText');

    $pages = $this->balayer($onglet, $onglet->chemin(), ['/societe/', '/app/', '/cabinet/'], __FILE__);

    expect($pages)->not->toBeEmpty()
        ->and($onglet->page()->evaluate('() => document.querySelector("[data-menu-vous-etes] summary strong").innerText'))->toBe($choisi);

    if ($nom !== null) {
        expect($choisi)->toBe($nom);
    }
})->with('comptes du bandeau de chaque copie');
