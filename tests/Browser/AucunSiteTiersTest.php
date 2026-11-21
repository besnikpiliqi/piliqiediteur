<?php

use Tests\Browser\Support\Navigation;

/*
 * Aucune page ne joint un site tiers (constat C4 de l'éditeur, réponse
 * « oui recommandé » à Q-TIERS) : panneau par panneau, et sur les pages
 * publiques, aucune ressource (feuille de style, police, image, script,
 * cadre) ne vient d'un autre site que PILIQI ; la police Inter vient de
 * public/fonts/inter, et l'image des initiales du compte est dessinée par
 * PILIQI dans son adresse même, sans que le nom de la personne parte
 * ailleurs. Le navigateur des tests refuse de toute façon toute requête
 * vers un autre site, et chaque vérification d'une page échoue sur la
 * moindre (voir Navigation et surveillance.js) : ces tests lisent en plus
 * ce que chaque page a chargé.
 */

/**
 * Ce que cette page a chargé ou désigne hors de PILIQI (ressources
 * chargées, feuilles de style, scripts, images, cadres), et les polices
 * qu'elle a chargées, une fois ses polices prêtes.
 *
 * @return array{tiers: list<string>, polices: list<string>}
 */
function ressourcesDeLaPage(Navigation $onglet): array
{
    $ressources = $onglet->page()->evaluate(<<<'JS'
        async () => {
            await document.fonts.ready;
            const adresses = [
                ...performance.getEntriesByType('resource').map((ressource) => ressource.name),
                ...[...document.querySelectorAll('link[href]')].map((lien) => lien.href),
                ...[...document.querySelectorAll('script[src], img[src], iframe[src], source[src], video[src], audio[src]')].map((element) => element.src),
            ];
            const tiers = adresses.filter((adresse) => {
                const url = new URL(adresse, location.href);

                return ['http:', 'https:', 'ws:', 'wss:'].includes(url.protocol) && url.origin !== location.origin;
            });
            const polices = performance.getEntriesByType('resource').map((ressource) => ressource.name).filter((adresse) => /\.woff2?($|\?)/.test(adresse));

            return { tiers, polices };
        }
    JS);

    return ['tiers' => array_values($ressources['tiers']), 'polices' => array_values($ressources['polices'])];
}

/**
 * La page ne charge rien d'un autre site ; chaque police qu'elle charge
 * vient de public/fonts/inter, et une page d'un panneau de Filament, écrite
 * en Inter, en charge au moins une.
 */
function pageSansSiteTiers(Navigation $onglet, string $quoi, bool $enInter = true): void
{
    $ressources = ressourcesDeLaPage($onglet);

    expect($ressources['tiers'])->toBe([], $quoi.' charge une ressource d\'un autre site.');

    if ($enInter) {
        expect($ressources['polices'])->not->toBe([], $quoi.' ne charge aucune police.');
    }

    foreach ($ressources['polices'] as $police) {
        expect(parse_url($police, PHP_URL_PATH))->toStartWith('/fonts/inter/', $quoi.' charge une police d\'ailleurs : '.$police);
    }
}

test('les pages publiques ne chargent rien d’un autre site', function (string $page, bool $enInter) {
    pageSansSiteTiers($this->naviguer($page), $page, $enInter);
})->with([
    'connexion au panneau des magasins' => ['/app/login', true],
    'connexion à l’espace de la société' => ['/societe/login', true],
    'connexion à l’espace du cabinet' => ['/cabinet/login', true],
    'mot de passe oublié' => ['/app/password-reset/request', true],
    'inscription d’une société' => ['/app/register', true],
    'code du courrier recommandé, hors des panneaux' => ['/code', false],
]);

test('chaque panneau ne charge rien d’un autre site, et les initiales du compte sont dessinées par PILIQI', function () {
    $societe = $this->societeComplete();
    $panneaux = [
        'le panneau du magasin' => [$societe->responsable, 'app'],
        'l’espace de la société' => [$societe->proprietaire, 'societe'],
        'l’espace du cabinet' => [$societe->proprietaireDuCabinet, 'cabinet'],
    ];

    foreach ($panneaux as $quoi => [$compte, $panneau]) {
        $onglet = $this->seConnecter($compte, panneau: $panneau);
        pageSansSiteTiers($onglet, $quoi);

        $avatar = $onglet->page()->evaluate('() => document.querySelector(".fi-user-menu .fi-user-avatar")?.getAttribute("src") ?? null');
        expect($avatar)->toBeString($quoi.' n\'a pas d\'image des initiales.')
            ->and($avatar)->toStartWith('data:image/svg+xml;base64,')
            ->and($avatar)->not->toContain('ui-avatars');
    }
});
