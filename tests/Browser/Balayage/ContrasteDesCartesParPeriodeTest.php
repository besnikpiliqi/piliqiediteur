<?php

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use Tests\Browser\Support\CartesParPeriode as Cartes;
use Tests\Browser\Support\ContrasteDesPages;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\SemeLesVentesDeTroisAnnees;

uses(SemeLesVentesDeTroisAnnees::class);

/*
 * Les cartes par mois, par trimestre ou par année se lisent aussi bien en
 * mode clair qu'en mode sombre (décision de l'éditeur du 15/11/2026 :
 * contraste AA, 4,5:1 pour le texte, 3:1 pour un grand texte et pour les
 * marques utiles ; choix de l'éditeur du 16/11/2026, lot GRAPH-AN, « couleurs
 * lisibles dans les deux modes »). ContrasteDesDeuxModesTest lit chaque page
 * avec les cartes par défaut et seulement le texte visible : ces cartes,
 * dans leurs états (mois, trimestre, année et écart, cumul et comparaison,
 * détail par jour, fenêtre d'un jour, info-bulle), n'y seraient pas toutes
 * contrôlées. Ici chaque état est lu en clair puis en sombre par
 * ContrasteDesPages, plus ce que le texte de la page ne dit pas : les
 * graduations et les étiquettes du `svg` (leur remplissage, non leur couleur
 * de texte), ses barres, ses courbes, ses points, la ligne du zéro et les
 * pastilles des légendes.
 */

/**
 * Ce que le dessin et les légendes des cartes par période ont sous le seuil,
 * dans le mode où la page est : « carte, élément, rapport trouvé pour le
 * seuil ».
 *
 * @return list<string>
 */
function defautsDuDessinDesCartesParPeriode(Navigation $onglet): array
{
    $defauts = $onglet->page()->evaluate(<<<'JS'
        () => {
            const lire = (couleur) => {
                const parties = /^rgba?\(([^)]+)\)$/.exec(couleur);

                if (parties === null) {
                    return null;
                }

                const nombres = parties[1].split(/[\s,/]+/).filter((partie) => partie !== '').map(Number);

                return { r: nombres[0], g: nombres[1], b: nombres[2], a: nombres.length > 3 ? nombres[3] : 1 };
            };
            const luminance = ({ r, g, b }) => {
                const canal = (valeur) => {
                    const v = valeur / 255;

                    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
                };

                return 0.2126 * canal(r) + 0.7152 * canal(g) + 0.0722 * canal(b);
            };
            const rapport = (premiere, seconde) => {
                const [claire, sombre] = [luminance(premiere), luminance(seconde)].sort((x, y) => y - x);

                return Math.round(((claire + 0.05) / (sombre + 0.05)) * 100) / 100;
            };
            const defauts = [];

            for (const carte of document.querySelectorAll('.piliqi-graphiques__carte--periode')) {
                const fond = lire(getComputedStyle(carte).backgroundColor);
                const nom = carte.dataset.graphique;
                const controler = (element, couleur, seuil) => {
                    const lue = lire(couleur);

                    if (lue === null || lue.a === 0) {
                        return;
                    }

                    const trouve = rapport(lue, fond);

                    if (trouve < seuil) {
                        defauts.push(nom + ', ' + element + ' (' + couleur + ' sur ' + getComputedStyle(carte).backgroundColor + '), ' + trouve + ':1 pour ' + seuil + ':1');
                    }
                };

                for (const texte of carte.querySelectorAll('svg text')) {
                    controler('texte « ' + texte.textContent + ' » du dessin', getComputedStyle(texte).fill, 4.5);
                }

                for (const barre of carte.querySelectorAll('svg rect:not(.fond)')) {
                    controler('barre ' + barre.getAttribute('fill'), getComputedStyle(barre).fill, 3);
                }

                for (const courbe of carte.querySelectorAll('svg path[stroke]')) {
                    controler('courbe ' + courbe.getAttribute('stroke'), getComputedStyle(courbe).stroke, 3);
                }

                for (const point of carte.querySelectorAll('svg circle')) {
                    controler('point ' + point.getAttribute('fill'), getComputedStyle(point).fill, 3);
                }

                for (const ligne of carte.querySelectorAll('svg line[stroke="var(--periode-axe)"]')) {
                    controler('ligne du zéro', getComputedStyle(ligne).stroke, 3);
                }

                for (const puce of carte.querySelectorAll('.piliqi-graphiques__puce')) {
                    const style = getComputedStyle(puce);

                    controler('pastille de la légende', puce.classList.contains('piliqi-graphiques__puce--tirets') ? style.borderTopColor : style.backgroundColor, 3);
                }
            }

            return defauts;
        }
        JS);

    return array_values(array_filter(is_array($defauts) ? $defauts : [], is_string(...)));
}

test('les cartes par période se lisent en clair et en sombre : mois, trimestre, année et écart, cumul et comparaison, détail par jour, fenêtre d\'un jour et info-bulle', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    Cartes::afficherLesCartesParPeriode($proprietaire, EcranDuTableauDeBord::Societe);
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 8', 'les huit cartes ne sont pas là');
    $contraste = new ContrasteDesPages($onglet);
    $carte = Cartes::carte();
    $defauts = [];
    $lire = function (string $etat) use ($onglet, $contraste, &$defauts): void {
        foreach (ContrasteDesPages::MODES as $mode) {
            foreach ($contraste->defautsDeLaPage($mode) as $defaut) {
                $defauts[] = "{$etat} ({$mode}) : {$defaut}";
            }

            foreach (defautsDuDessinDesCartesParPeriode($onglet) as $defaut) {
                $defauts[] = "{$etat} ({$mode}) : {$defaut}";
            }
        }
    };

    Cartes::basculer($onglet, 'input[data-filtre="comparer"]', 'la comparaison');
    Cartes::basculer($onglet, $carte.' input[data-geste="tendance"]', 'la tendance');
    $lire('mois, comparaison et tendance');

    Cartes::basculer($onglet, 'input[data-filtre="comparer"]', 'la fin de la comparaison');
    Cartes::choisir($onglet, 'decoupage', 'trimestre');
    $lire('trimestre');

    Cartes::choisir($onglet, 'decoupage', 'annee');
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="mode"]')->selectOption('ecart'), 'l\'écart en %');
    $lire('année et écart');

    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="mode"]')->selectOption('montants'), 'le retour aux montants');
    Cartes::choisir($onglet, 'decoupage', 'mois');
    Cartes::basculer($onglet, 'input[data-filtre="cumul"]', 'le cumul');
    Cartes::basculer($onglet, 'input[data-filtre="comparer"]', 'la comparaison du cumul');
    $lire('cumul et comparaison');

    Cartes::basculer($onglet, 'input[data-filtre="cumul"]', 'la fin du cumul');
    Cartes::basculer($onglet, 'input[data-filtre="comparer"]', 'la fin de la comparaison');
    Cartes::cliquerLaBarre($onglet, 'Mars 2026');
    $onglet->attendreQue('document.querySelectorAll(\''.$carte.' g.colonne\').length === 31', 'mars 2026 ne se redécoupe pas');
    $lire('détail par jour');

    Cartes::cliquerLaBarre($onglet, 'Jeudi 5 mars 2026');
    $onglet->attendreQue('document.querySelector("dialog[data-liste-du-jour][open]") !== null', 'la liste du jour ne s\'ouvre pas');
    $lire('fenêtre d\'un jour');

    $onglet->geste(fn () => $onglet->element('dialog[data-liste-du-jour]')->press('Escape'), 'Échap dans la fenêtre');
    $onglet->attendreQue('document.querySelector("dialog[data-liste-du-jour]") === null', 'la fenêtre ne se ferme pas');
    $onglet->element(Cartes::colonne('Mardi 10 mars 2026'))->hover();
    $onglet->attendre();
    $onglet->element(Cartes::colonne('Mardi 10 mars 2026'))->hover();
    $onglet->attendreQue('document.querySelector("[data-info-bulle-des-barres]") !== null && ! document.querySelector("[data-info-bulle-des-barres]").hidden', 'l\'info-bulle ne s\'ouvre pas');
    $lire('info-bulle');

    $onglet->page()->setViewportSize(390, 900);
    $onglet->attendreQue('window.innerWidth === 390', 'la fenêtre n\'est pas à 390 px');
    $lire('téléphone (390 px)');

    expect($defauts)->toBe([], 'Sous le contraste AA :'.PHP_EOL.implode(PHP_EOL, $defauts));
});

test('le contrôle du dessin relève une graduation, une barre, une courbe et une pastille trop pâles, et rien sur les cartes telles qu\'elles sont', function () {
    ['proprietaire' => $proprietaire] = $this->semerLesVentesDeTroisAnnees();
    Cartes::afficherLesCartesParPeriode($proprietaire, EcranDuTableauDeBord::Societe);
    $onglet = $this->seConnecter($proprietaire, panneau: 'societe')
        ->attendreQue('document.querySelectorAll("[data-graphique]").length === 8', 'les huit cartes ne sont pas là');
    $contraste = new ContrasteDesPages($onglet);

    Cartes::basculer($onglet, Cartes::carte().' input[data-geste="tendance"]', 'la tendance');

    foreach (ContrasteDesPages::MODES as $mode) {
        $contraste->defautsDeLaPage($mode);

        expect(defautsDuDessinDesCartesParPeriode($onglet))->toBe([], "cartes telles qu'elles sont ({$mode})");
    }

    $onglet->page()->evaluate(<<<'JS'
        () => {
            const feuille = document.createElement('style');
            feuille.textContent = '.piliqi-graphiques__carte--periode { --periode-s1: #e0e8f0 !important; --periode-doux: #d0d0d0 !important; --periode-encre: #f0f0f0 !important; --periode-axe: #e8e8e8 !important; }';
            document.head.append(feuille);
        }
        JS);
    $contraste->defautsDeLaPage('clair');
    $trouves = implode(PHP_EOL, defautsDuDessinDesCartesParPeriode($onglet));

    expect($trouves)->toContain('texte « ', 'barre var(--periode-s1)', 'courbe var(--periode-encre)', 'ligne du zéro', 'pastille de la légende');
});
