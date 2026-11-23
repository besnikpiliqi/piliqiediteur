<?php

use Tests\Browser\Support\CarteDeLEcartDesPrixDAchat as Carte;
use Tests\Browser\Support\ContrasteDesPages;
use Tests\Browser\Support\Navigation;

/*
 * La carte « Écart des prix d'achat » se lit aussi bien en mode clair qu'en
 * mode sombre (décision de l'éditeur du 15/11/2026 : contraste AA, 4,5:1
 * pour le texte, 3:1 pour un grand texte et pour les traits utiles ; D76,
 * B7 : si une couleur de la maquette n'atteint pas le contraste exigé sur
 * le fond d'une carte de PILIQI, la feuille l'assombrit ou l'éclaircit au
 * minimum, dans le sens qui garde sa teinte). ContrasteDesDeuxModesTest lit
 * chaque page avec les cartes par défaut et seulement le texte visible :
 * cette carte, absente des cartes par défaut et tableaux fermés, n'y serait
 * pas contrôlée. Ici elle est affichée, dans ses deux affichages, avec
 * « Voir les chiffres » et « Voir le calcul… » ouverts, et la page est lue
 * en clair puis en sombre par ContrasteDesPages, plus ce que le texte de la
 * page ne dit pas : les graduations et les étiquettes du `svg` (leur
 * remplissage, non leur couleur de texte), ses barres, son losange, la
 * ligne du zéro et le cadre du mois choisi, et les carrés des légendes.
 */

/**
 * Ce que le dessin et les légendes de la carte ont sous le seuil, dans le
 * mode où la page est (voir ContrasteDesPages::defautsDeLaPage()) : « élément,
 * rapport trouvé pour le seuil ».
 *
 * @return list<string>
 */
function defautsDuDessinDeLEcart(Navigation $onglet): array
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
            const carte = document.querySelector('[data-graphique="ecart_des_prix_d_achat"]');
            const fond = lire(getComputedStyle(carte).backgroundColor);
            const defauts = [];
            const controler = (nom, couleur, seuil) => {
                const lue = lire(couleur);

                if (lue === null || lue.a === 0) {
                    return;
                }

                const trouve = rapport(lue, fond);

                if (trouve < seuil) {
                    defauts.push(nom + ' (' + couleur + ' sur ' + getComputedStyle(carte).backgroundColor + '), ' + trouve + ':1 pour ' + seuil + ':1');
                }
            };

            for (const texte of carte.querySelectorAll('svg text')) {
                controler('texte « ' + texte.textContent + ' » du dessin', getComputedStyle(texte).fill, 4.5);
            }

            for (const barre of carte.querySelectorAll('svg rect[fill="var(--plus)"], svg rect[fill="var(--moins)"]')) {
                controler('barre ' + barre.getAttribute('fill'), getComputedStyle(barre).fill, 3);
            }

            for (const losange of carte.querySelectorAll('svg polygon')) {
                controler('losange', getComputedStyle(losange).fill, 3);
            }

            for (const ligne of carte.querySelectorAll('svg line[stroke="var(--doux)"]')) {
                controler('ligne du zéro', getComputedStyle(ligne).stroke, 3);
            }

            for (const colonne of carte.querySelectorAll('svg g[aria-pressed="true"] rect.focus')) {
                controler('cadre du mois choisi', getComputedStyle(colonne).stroke, 3);
            }

            for (const legende of carte.querySelectorAll('[data-ecart="legende-plus"], [data-ecart="legende-moins"], [data-ecart="legende-net"]')) {
                controler('carré de la légende ' + legende.dataset.ecart, getComputedStyle(legende, '::before').backgroundColor, 3);
            }

            return defauts;
        }
        JS);

    return array_values(array_filter(is_array($defauts) ? $defauts : [], is_string(...)));
}

test('la carte « Écart des prix d’achat » se lit en clair et en sombre dans ses deux affichages, ses tableaux ouverts', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $carte = '[data-graphique="'.Carte::CARTE.'"]';
    $contraste = new ContrasteDesPages($onglet);

    Carte::ajouter($onglet, 4);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare');
    $onglet->geste(fn () => $onglet->element($carte.' [data-ecart="voir-chiffres"]:visible')->click(), 'l’ouverture des chiffres');
    $onglet->geste(fn () => $onglet->element($carte.' [data-ecart="voir-calcul"]:visible')->click(), 'l’ouverture du calcul');

    $etats = [
        'mois 6 (hausses)' => fn () => $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(6))->click(), 'le clic sur le mois 6'),
        'mois 0 (baisses)' => fn () => $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(0))->click(), 'le clic sur le mois 0'),
        'mois 8 (aucun achat)' => fn () => $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(8))->click(), 'le clic sur le mois 8'),
        'mois 3 (écart de +0,09 €)' => fn () => $onglet->gesteSansServeur(fn () => $onglet->element(Carte::colonne(3))->click(), 'le clic sur le mois 3'),
        'douze mois' => fn () => $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element($carte.' select[data-geste="affichage"]')->selectOption('debut'), 'le passage aux douze mois'),
    ];
    $defauts = [];

    foreach ($etats as $etat => $aller) {
        $aller();

        foreach (ContrasteDesPages::MODES as $mode) {
            foreach ($contraste->defautsDeLaPage($mode) as $defaut) {
                $defauts[] = "{$etat} ({$mode}) : {$defaut}";
            }

            if ($etat !== 'douze mois') {
                foreach (defautsDuDessinDeLEcart($onglet) as $defaut) {
                    $defauts[] = "{$etat} ({$mode}) : {$defaut}";
                }
            }
        }
    }

    expect($defauts)->toBe([], 'Sous le contraste AA :'.PHP_EOL.implode(PHP_EOL, $defauts));
});

test('le contrôle du dessin relève une graduation, une barre et un cadre trop pâles, et rien sur la carte telle qu’elle est', function () {
    $societe = $this->societeComplete();
    Carte::semer($this, self::aParis('2026-11-13 10:00:00'), $societe->autreMagasin, self::responsableDeTest($societe->autreMagasin));
    $onglet = $this->seConnecter($societe->proprietaire, panneau: 'societe');
    $contraste = new ContrasteDesPages($onglet);

    Carte::ajouter($onglet, 4);
    $onglet->gesteQuiAppelleLeServeur(fn () => $onglet->element('select[data-filtre="magasin"]')->selectOption((string) $societe->autreMagasin->uuid), 'le choix de la Boutique de la Gare');

    foreach (ContrasteDesPages::MODES as $mode) {
        $contraste->defautsDeLaPage($mode);

        expect(defautsDuDessinDeLEcart($onglet))->toBe([], "carte telle qu'elle est ({$mode})");
    }

    $onglet->page()->evaluate(<<<'JS'
        () => {
            const feuille = document.createElement('style');
            feuille.textContent = '[data-graphique="ecart_des_prix_d_achat"] { --doux: #d0d0d0 !important; --plus: #f0e0d0 !important; --texte: #e8e8e8 !important; }';
            document.head.append(feuille);
        }
        JS);
    $contraste->defautsDeLaPage('clair');
    $trouves = implode(PHP_EOL, defautsDuDessinDeLEcart($onglet));

    expect($trouves)->toContain('texte « 0 € » du dessin', 'barre var(--plus)', 'ligne du zéro', 'cadre du mois choisi', 'carré de la légende legende-plus');
});
