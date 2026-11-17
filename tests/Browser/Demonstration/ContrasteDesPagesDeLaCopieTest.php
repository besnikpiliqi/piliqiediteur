<?php

use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Tests\Browser\Support\ContrasteDesPages;
use Tests\Browser\Support\Navigation;

/*
 * Les pages d'une copie du site de démonstration se lisent aussi bien en
 * mode clair qu'en mode sombre (décision de l'éditeur du 15/11/2026 ; lot
 * DEMO-COPIE, G.3) : « Choisissez qui vous êtes », le bandeau « Vous
 * êtes : » et son menu ouvert sur une page d'un panneau, « E-mails qui
 * seraient partis » et la fiche d'un e-mail, une page d'attente et la page
 * qui suit « Quitter ». Ces pages suivent la préférence du système
 * (prefers-color-scheme) ; dans un panneau, le bandeau suit aussi la classe
 * dark de Filament : chacune se lit dans les deux (voir
 * ContrasteDesPages::defautsDeLaPageSelonLeSysteme()).
 */

/**
 * Les textes sous le contraste AA de la page ouverte, en clair puis en
 * sombre, chacun précédé du nom de la page.
 *
 * @return list<string>
 */
function defautsEnClairEtEnSombre(Navigation $onglet, string $nom): array
{
    $contraste = new ContrasteDesPages($onglet);
    $defauts = [];

    foreach (ContrasteDesPages::MODES as $mode) {
        foreach ($contraste->defautsDeLaPageSelonLeSysteme($mode) as $defaut) {
            $defauts[] = $nom.' ('.$mode.') : '.$defaut;
        }
    }

    return $defauts;
}

test('les pages de la copie, le bandeau et son menu ouvert se lisent en clair et en sombre', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $onglet = $this->suivreLeLien($secret);
    $defauts = defautsEnClairEtEnSombre($onglet, 'Choisissez qui vous êtes');

    $onglet->cliquer('[data-compte-du-choix]:has(strong:text-is("Inès Garcia"))')
        ->attendreQue('document.querySelector("[data-bandeau-de-la-demonstration]") !== null && location.pathname.startsWith("/app")', 'le choix de la vendeuse n\'a pas ouvert son panneau')
        ->attendre()
        ->cliquer('[data-menu-vous-etes] > summary');
    $defauts = [...$defauts, ...defautsEnClairEtEnSombre($onglet, 'le bandeau et son menu ouvert, dans le panneau du magasin')];

    $onglet->aller('/demonstration/courriels');
    $defauts = [...$defauts, ...defautsEnClairEtEnSombre($onglet, 'E-mails qui seraient partis')];

    $onglet->cliquer('[data-courriel]')->voir('Tous les e-mails qui seraient partis');
    $defauts = [...$defauts, ...defautsEnClairEtEnSombre($onglet, 'la fiche d\'un e-mail')];

    $onglet->cliquer('.piliqi-bandeau__quitter')->voir('Vous avez quitté la démonstration');
    $defauts = [...$defauts, ...defautsEnClairEtEnSombre($onglet, 'la page qui suit « Quitter »')];

    $onglet = $this->suivreLeLien($secret);
    Copie::on('demo_admin')->whereKey($copie->id)->update(['etat' => EtatDUneCopie::EnMigration->value]);
    $this->tolerer(503, '/choisir');
    $onglet->aller('/choisir')->voir('Mise à jour en cours, une minute');
    $defauts = [...$defauts, ...defautsEnClairEtEnSombre($onglet, 'la page d\'attente d\'une mise à jour')];

    expect($defauts)->toBe([], 'Textes sous le contraste AA :'.PHP_EOL.implode(PHP_EOL, $defauts));
});
