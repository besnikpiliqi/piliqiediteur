<?php

use Tests\Browser\Support\Navigation;

/*
 * Constat C5 de l'éditeur, confirmé à T43 : la rangée choisie d'une fenêtre
 * de sélection (un produit ajouté à la vente) et la ligne cochée d'une
 * liste prennent une teinte légère de la couleur principale du panneau, au
 * texte bien lisible : le contraste entre le texte et le fond de la rangée,
 * tels que le navigateur les calcule, atteint au moins 4,5 pour 1 (niveau AA
 * des règles d'accessibilité WCAG), en mode clair comme en mode sombre, au
 * repos comme au survol. La fenêtre des clients, qu'un clic ferme sur le
 * client choisi, se lit de même au survol. La société de test a tous ses
 * rôles et son catalogue (voir TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Le texte de cette étiquette de cellule (« Bougie parfumée »), sur la
 * page ou dans la fenêtre ouverte : sa couleur, le fond que l'œil voit
 * derrière lui (les fonds transparents de ses parents composés jusqu'au
 * premier fond opaque) et leur contraste au sens des règles WCAG, tels que
 * le navigateur les calcule.
 *
 * @return array{contraste: float, fond: string, texte: string}
 */
function couleursDeLaRangee(Navigation $onglet, string $etiquette): array
{
    $mesure = $onglet->page()->evaluate(<<<'JS'
        (etiquette) => {
            const libelle = [...document.querySelectorAll('.fi-ta-row .fi-ta-text-item-label')]
                .find((element) => element.textContent.trim() === etiquette && element.offsetParent !== null);

            if (! libelle) {
                return null;
            }

            const couleur = (valeur) => {
                const morceaux = valeur.match(/rgba?\(([^)]*)\)/);

                if (! morceaux) {
                    return null;
                }

                const parts = morceaux[1].split(/[\s,\/]+/).filter((part) => part !== '').map(Number);

                return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1];
            };
            const poser = (dessus, dessous) => [0, 1, 2].map((i) => dessus[i] * dessus[3] + dessous[i] * (1 - dessus[3])).concat([1]);
            const couches = [];

            for (let element = libelle; element; element = element.parentElement) {
                const fond = couleur(getComputedStyle(element).backgroundColor);

                if (fond && fond[3] > 0) {
                    couches.push(fond);

                    if (fond[3] >= 1) {
                        break;
                    }
                }
            }

            const fond = couches.reverse().reduce((dessous, dessus) => poser(dessus, dessous), [255, 255, 255, 1]);
            const texte = poser(couleur(getComputedStyle(libelle).color), fond);
            const luminance = (c) => {
                const [r, g, b] = c.slice(0, 3).map((v) => {
                    const s = v / 255;

                    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
                });

                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const [claire, sombre] = [luminance(texte), luminance(fond)].sort((a, b) => b - a);

            return { contraste: (claire + 0.05) / (sombre + 0.05), fond: fond.map(Math.round).join(','), texte: texte.map(Math.round).join(',') };
        }
    JS, $etiquette);
    expect($mesure)->toBeArray("« {$etiquette} » n'est dans aucune rangée visible.");

    return ['contraste' => (float) $mesure['contraste'], 'fond' => (string) $mesure['fond'], 'texte' => (string) $mesure['texte']];
}

/**
 * Le contraste du texte de cette étiquette sur sa rangée atteint le niveau
 * AA (4,5 pour 1) ; rend ses couleurs.
 *
 * @return array{contraste: float, fond: string, texte: string}
 */
function rangeeLisible(Navigation $onglet, string $etiquette, string $etat): array
{
    $couleurs = couleursDeLaRangee($onglet, $etiquette);
    expect($couleurs['contraste'])->toBeGreaterThanOrEqual(4.5, "« {$etiquette} » ({$etat}) : texte {$couleurs['texte']} sur fond {$couleurs['fond']}, contraste {$couleurs['contraste']}.");

    return $couleurs;
}

/**
 * Le fond propre de la rangée de cette étiquette, tel que le navigateur le
 * calcule, et la teinte légère de la couleur principale du panneau que
 * doit prendre une rangée choisie ou cochée (la plus claire en mode clair,
 * la principale très transparente en mode sombre), calculée de même sur un
 * élément témoin.
 *
 * @return array{rangee: string, teinte: string}
 */
function fondEtTeinteDeLaRangee(Navigation $onglet, string $etiquette): array
{
    $fonds = $onglet->page()->evaluate(<<<'JS'
        (etiquette) => {
            const libelle = [...document.querySelectorAll('.fi-ta-row .fi-ta-text-item-label')]
                .find((element) => element.textContent.trim() === etiquette && element.offsetParent !== null);
            const temoin = document.createElement('div');
            temoin.style.backgroundColor = document.documentElement.classList.contains('dark') ? 'rgba(var(--primary-500), 0.15)' : 'rgba(var(--primary-50), 1)';
            document.body.appendChild(temoin);
            const teinte = getComputedStyle(temoin).backgroundColor;
            temoin.remove();

            return { rangee: libelle ? getComputedStyle(libelle.closest('.fi-ta-row')).backgroundColor : null, teinte };
        }
    JS, $etiquette);

    return ['rangee' => (string) $fonds['rangee'], 'teinte' => (string) $fonds['teinte']];
}

/**
 * La rangée de cette étiquette a la teinte de la couleur principale du
 * panneau.
 */
function rangeeTeintee(Navigation $onglet, string $etiquette, string $etat): void
{
    $fonds = fondEtTeinteDeLaRangee($onglet, $etiquette);
    expect($fonds['rangee'])->toBe($fonds['teinte'], "La rangée de « {$etiquette} » ({$etat}) n'a pas la teinte de la couleur principale du panneau.");
}

/**
 * Passe la page en mode sombre, comme le choix du thème du menu du compte
 * (Filament le garde dans le navigateur), et la recharge.
 */
function passerEnModeSombre(Navigation $onglet): Navigation
{
    $onglet->page()->evaluate('() => localStorage.setItem("theme", "dark")');

    return $onglet->aller((string) parse_url($onglet->page()->url(), PHP_URL_PATH))
        ->attendreQue('document.documentElement.classList.contains("dark")', 'la page ne passe pas en mode sombre');
}

test('la rangée choisie, la rangée survolée et la ligne cochée se lisent bien, en clair et en sombre', function (bool $sombre) {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/create');

    if ($sombre) {
        passerEnModeSombre($onglet);
    }

    $onglet->cliquerSur('Ajouter un produit');
    $avant = couleursDeLaRangee($onglet, 'Bougie parfumée');
    $onglet->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte('Bougie parfumée').')')->voir('× 1');
    $choisie = rangeeLisible($onglet, 'Bougie parfumée', 'choisie');
    rangeeLisible($onglet, 'BOUGIE', 'choisie');
    rangeeTeintee($onglet, 'Bougie parfumée', 'choisie');
    expect($choisie['fond'])->not->toBe($avant['fond'], 'La rangée choisie garde le fond d\'une rangée qui ne l\'est pas.')
        ->and($choisie['fond'])->not->toBe(couleursDeLaRangee($onglet, 'Savon au lait')['fond']);

    $onglet->element(Navigation::FENETRE.' .fi-ta-row:has(.fi-ta-text-item-label:text-is('.Navigation::texte('Bougie parfumée').'))')->hover();
    expect(rangeeLisible($onglet, 'Bougie parfumée', 'choisie et survolée')['fond'])->toBe($choisie['fond']);
    rangeeTeintee($onglet, 'Bougie parfumée', 'choisie et survolée');
    $onglet->voir('× 1')->fermerLaFenetre();

    $onglet->cliquer('[title="Chercher un client"]');
    $onglet->element(Navigation::FENETRE.' .fi-ta-row:has(.fi-ta-text-item-label:text-is('.Navigation::texte('Jean Dupont').'))')->hover();
    rangeeLisible($onglet, 'Jean Dupont', 'survolée dans la fenêtre des clients');
    $onglet->fermerLaFenetre();

    $onglet->aller('/app/'.$societe->magasin->uuid.'/clients');
    $libre = couleursDeLaRangee($onglet, 'Jean Dupont');
    $onglet->cliquer('.fi-ta-row:has(.fi-ta-text-item-label:text-is('.Navigation::texte('Jean Dupont').')) .fi-ta-record-checkbox');
    $cochee = rangeeLisible($onglet, 'Jean Dupont', 'cochée dans la liste des clients');
    rangeeTeintee($onglet, 'Jean Dupont', 'cochée dans la liste des clients');
    expect($cochee['fond'])->not->toBe($libre['fond'], 'La ligne cochée garde le fond d\'une ligne qui ne l\'est pas.')
        ->and($cochee['fond'])->toBe($choisie['fond']);
})->with(['mode clair' => [false], 'mode sombre' => [true]]);
