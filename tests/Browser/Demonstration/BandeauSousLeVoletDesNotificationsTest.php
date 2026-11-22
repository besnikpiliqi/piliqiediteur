<?php

use App\Domain\Tenancy\Enums\TypeDActivite;
use Tests\Browser\Support\Navigation;

/*
 * Le bandeau « Vous êtes : » d'une copie du site de démonstration ne coupe
 * pas le volet des notifications (constat de l'éditeur du 17/11/2026, avec
 * son menu qui reste au-dessus de la barre du haut) : le volet, qui couvre
 * toute la hauteur de la fenêtre, est au-dessus du bandeau ; chaque point
 * de sa colonne, jusqu'au bord haut de la fenêtre, appartient au volet, à
 * 1 280 px de large comme sur un téléphone, en clair comme en sombre.
 */

/**
 * Le volet des notifications ouvert : chaque point de sa colonne, du haut
 * à une dizaine de pixels du bas, répond-il à un élément du volet (et non
 * au bandeau) ?
 */
function colonneDuVoletLibreDuBandeau(Navigation $onglet): array
{
    return $onglet->page()->evaluate(<<<'JS'
        () => {
            const volet = document.querySelector('.fi-modal.fi-modal-open .fi-modal-window');
            const bandeau = document.querySelector('[data-bandeau-de-la-demonstration]');

            if (volet === null || bandeau === null) {
                return { volet: volet !== null, bandeau: bandeau !== null, pointsAuBandeau: -1, pointsEchantillonnes: 0 };
            }

            const boite = volet.getBoundingClientRect();
            const x = boite.left + boite.width / 2;
            let auBandeau = 0;
            let echantillons = 0;

            for (let y = 1; y < Math.min(boite.bottom, window.innerHeight) - 10; y += 12) {
                const point = document.elementFromPoint(x, y);
                echantillons += 1;

                if (point !== null && bandeau.contains(point)) {
                    auBandeau += 1;
                }
            }

            return { volet: true, bandeau: true, pointsAuBandeau: auBandeau, pointsEchantillonnes: echantillons };
        }
        JS);
}

test('le volet des notifications passe au-dessus du bandeau « Vous êtes : » dans l’espace de la société et le panneau d’un magasin, à 1 280 px et sur un téléphone, en clair comme en sombre', function () {
    ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
    $responsable = $this->compteDeLaCopie($copie, 'Camille Laurent');

    $onglet = $this->suivreLeLien($secret)->voir('Choisissez qui vous êtes');

    foreach (['la responsable du magasin', 'le propriétaire'] as $qui) {
        if ($qui === 'la responsable du magasin') {
            $onglet->cliquer('[data-compte-du-choix="'.$responsable->getKey().'"]')
                ->attendreQue('document.querySelector("[data-bandeau-de-la-demonstration]") !== null && location.pathname !== "/choisir"', 'le choix du compte n\'a ouvert aucun espace');
        } else {
            $onglet->page()->setViewportSize(1280, 900);
            $onglet->cliquer('[data-menu-vous-etes] > summary')
                ->cliquer('[data-compte-du-bandeau]:has(.piliqi-bandeau__role:text-is("Propriétaire"))')
                ->attendreQue('location.pathname.startsWith("/societe")', 'le propriétaire n\'arrive pas dans l\'espace de la société');
        }

        foreach ([['largeur' => 1280, 'hauteur' => 900], ['largeur' => 390, 'hauteur' => 800]] as $fenetre) {
            foreach (['light', 'dark'] as $mode) {
                $onglet->page()->setViewportSize($fenetre['largeur'], $fenetre['hauteur']);
                $onglet->page()->evaluate('(mode) => { localStorage.setItem("theme", mode); }', $mode);
                $onglet->aller($onglet->chemin())->attendre()->verifier();
                // Sur un téléphone, le tiroir du menu, ouvert, couvre la cloche : le visiteur le referme.
                $onglet->page()->evaluate('(largeur) => { if (largeur < 1024) { window.Alpine.store("sidebar").close(); } }', $fenetre['largeur']);
                $onglet->cliquer('.fi-topbar-database-notifications-btn');

                $colonne = colonneDuVoletLibreDuBandeau($onglet);

                expect($colonne['volet'])->toBeTrue("Le volet s'ouvre ({$qui}, {$fenetre['largeur']} px, {$mode}).")
                    ->and($colonne['bandeau'])->toBeTrue('Le bandeau est en haut de la page.')
                    ->and($colonne['pointsAuBandeau'])->toBe(0, "Le bandeau coupe le volet des notifications ({$qui}, {$fenetre['largeur']} px, {$mode}).")
                    ->and($colonne['pointsEchantillonnes'])->toBeGreaterThan(3);

                $onglet->fermerLaFenetre();
            }
        }

        $onglet->cliquer('[data-menu-vous-etes] > summary');
        expect($onglet->page()->evaluate('() => { const menu = document.querySelector(".piliqi-bandeau__comptes"); const boite = menu.getBoundingClientRect(); const point = document.elementFromPoint(boite.left + 10, boite.top + 10); return menu.contains(point); }'))->toBeTrue('Le menu déroulant du bandeau reste au-dessus de la barre du haut.');
        $onglet->cliquer('[data-menu-vous-etes] > summary');
    }
});
