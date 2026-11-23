<?php

use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Models\User;
use Tests\Browser\Support\CartesParPeriode as Cartes;

/*
 * Les cartes ouvertes au départ dans une copie du site de démonstration
 * (précision de l'éditeur du 16/11/2026, lot GRAPH-AN, règle générale :
 * toutes les cartes que le compte peut voir, pour que la société fictive se
 * voie tout de suite en entier) : le propriétaire de la copie « Commerce ou
 * atelier » voit chaque graphique du catalogue dès sa première visite, les
 * cartes par période dessinées, et le menu « Ajouter un graphique » dit que
 * tous sont affichés. Le site réel garde ses cartes principales (voir
 * GraphiquesParPeriodeDeLaSocieteTest).
 */

test('le propriétaire d\'une copie voit toutes les cartes des graphiques ouvertes dès sa première visite, et « Ajouter un graphique » dit que tous sont affichés', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $proprietaire = $this->dansLaCopie($copie, fn (): User => User::query()->where('role_societe', RoleSociete::Proprietaire->value)->sole());

    $onglet = $this->suivreLeLien($secret)
        ->voir('Choisissez qui vous êtes')
        ->cliquer('[data-compte-du-choix="'.$proprietaire->getKey().'"]')
        ->attendreQue('location.pathname.startsWith("/societe/")', 'le choix du propriétaire n\'a pas ouvert l\'espace de sa société')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === '.count(Graphique::cases()), 'toutes les cartes du catalogue ne sont pas ouvertes au départ');

    expect($onglet->page()->evaluate('() => [document.querySelector(\'select[data-filtre="ajouter"]\').disabled, document.querySelector(\'select[data-filtre="ajouter"]\').selectedOptions[0].textContent.trim()]'))
        ->toBe([true, 'Tous les graphiques sont affichés']);

    foreach (array_filter(Graphique::cases(), fn (Graphique $graphique): bool => $graphique->estParPeriode()) as $graphique) {
        // Le modèle de test n'a aucune vente : une carte par période dit « Aucune donnée sur la période. » ; une carte dessinée a ses colonnes.
        $carte = Cartes::carte($graphique->value);
        $onglet->attendreQue('document.querySelectorAll(\''.$carte.' svg[data-periode="dessin"] g.colonne\').length > 0 || document.querySelector(\''.$carte.' [data-vide]\') !== null', "la carte {$graphique->value} n\'est pas lue");
    }

    expect(Cartes::debordements($onglet))->toBe([]);
});
