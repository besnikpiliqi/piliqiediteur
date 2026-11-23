/*
 * Le contraste de chaque texte visible de la page (voir ContrasteDesPages),
 * calculé dans la page, sans aucune bibliothèque : la couleur de chaque
 * élément qui porte du texte contre la couleur de fond réelle derrière lui,
 * en remontant ses ancêtres et en composant les fonds transparents ; pour
 * chaque menu déroulant (<select>), la couleur de chacune de ses options
 * contre son fond, que Chrome prend, quand l'option n'en a pas, dans le
 * fond du menu rendu opaque. Rend les éléments sous le seuil de WCAG AA :
 * 4,5:1 pour un texte, 3:1 pour un grand texte (24 px, ou 18,66 px en
 * gras). Les éléments décoratifs ou désactivés (aria-hidden, disabled,
 * aria-disabled, hors de la vue des lecteurs d'écran par sr-only) suivent
 * la règle de WCAG, qui ne leur demande aucun contraste ; de même un texte
 * rejeté hors de la vue par son retrait (text-indent), que seul un lecteur
 * d'écran lit, comme le « Remove item » de la croix d'un menu à recherche,
 * dessinée par une image.
 */
(mode) => {
    const lire = (couleur) => {
        const parties = /^rgba?\(([^)]+)\)$/.exec(couleur);

        if (parties === null) {
            return null;
        }

        const nombres = parties[1].split(/[\s,/]+/).filter((partie) => partie !== '').map(Number);

        return { r: nombres[0], g: nombres[1], b: nombres[2], a: nombres.length > 3 ? nombres[3] : 1 };
    };
    const dessus = (haut, bas) => ({
        r: haut.r * haut.a + bas.r * (1 - haut.a),
        g: haut.g * haut.a + bas.g * (1 - haut.a),
        b: haut.b * haut.a + bas.b * (1 - haut.a),
        a: 1,
    });
    const luminance = ({ r, g, b }) => {
        const canal = (valeur) => {
            const v = valeur / 255;

            return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * canal(r) + 0.7152 * canal(g) + 0.0722 * canal(b);
    };
    const rapport = (premiere, seconde) => {
        const [claire, sombre] = [luminance(premiere), luminance(seconde)].sort((x, y) => y - x);

        return (claire + 0.05) / (sombre + 0.05);
    };
    const toile = mode === 'sombre' ? { r: 18, g: 18, b: 18, a: 1 } : { r: 255, g: 255, b: 255, a: 1 };
    const fondDe = (element) => {
        const couches = [];

        for (let noeud = element; noeud instanceof Element; noeud = noeud.parentElement) {
            const couleur = lire(getComputedStyle(noeud).backgroundColor);

            if (couleur !== null && couleur.a > 0) {
                couches.push(couleur);

                if (couleur.a >= 1) {
                    break;
                }
            }
        }

        return couches.reduceRight((fond, couche) => dessus(couche, fond), toile);
    };
    const opaciteDe = (element) => {
        let opacite = 1;

        for (let noeud = element; noeud instanceof Element; noeud = noeud.parentElement) {
            opacite *= Number(getComputedStyle(noeud).opacity);
        }

        return opacite;
    };
    const enRgb = ({ r, g, b }) => 'rgb(' + [r, g, b].map(Math.round).join(', ') + ')';
    const decrire = (element, texte, couleur, fond) => {
        const classes = [...element.classList].slice(0, 3).join('.');
        const composant = [...(element.closest('[class*="fi-"]')?.classList ?? [])].find((classe) => classe.startsWith('fi-'));

        return element.tagName.toLowerCase() + (element.id ? '#' + element.id : '') + (classes ? '.' + classes : '')
            + (composant ? ' dans .' + composant : '')
            + ' « ' + texte.replace(/\s+/g, ' ').trim().slice(0, 50) + ' » (' + enRgb(couleur) + ' sur ' + enRgb(fond) + ')';
    };
    const horsDeLaRegle = (element) => element.closest('[aria-hidden="true"], [aria-disabled="true"], :disabled, .sr-only, script, style, noscript, template, title') !== null;
    const visible = (element) => {
        const boite = element.getBoundingClientRect();
        const style = getComputedStyle(element);

        return element.getClientRects().length > 0 && boite.width > 1 && boite.height > 1 && style.visibility === 'visible'
            && Math.abs(parseFloat(style.textIndent) || 0) < boite.width;
    };
    const defauts = [];

    for (const element of document.body.querySelectorAll('*')) {
        if (element.tagName === 'OPTION' || horsDeLaRegle(element) || ! visible(element)) {
            continue;
        }

        const texte = [...element.childNodes].filter((noeud) => noeud.nodeType === Node.TEXT_NODE).map((noeud) => noeud.textContent).join('');

        if (texte.trim() === '') {
            continue;
        }

        const style = getComputedStyle(element);
        const couleur = lire(style.color);

        if (couleur === null) {
            continue;
        }

        const fond = fondDe(element);
        const vue = dessus({ ...couleur, a: couleur.a * opaciteDe(element) }, fond);
        const trouve = rapport(vue, fond);
        const taille = parseFloat(style.fontSize);
        const seuil = taille >= 24 || (taille >= 18.66 && Number(style.fontWeight) >= 700) ? 3 : 4.5;

        if (trouve < seuil) {
            defauts.push({ element: decrire(element, texte, vue, fond), rapport: Math.round(trouve * 100) / 100, seuil });
        }
    }

    for (const menu of document.body.querySelectorAll('select')) {
        if (horsDeLaRegle(menu) || ! visible(menu)) {
            continue;
        }

        const fondDuMenu = lire(getComputedStyle(menu).backgroundColor);
        const fondParDefaut = mode === 'sombre' ? { r: 59, g: 59, b: 59, a: 1 } : { r: 255, g: 255, b: 255, a: 1 };

        for (const option of menu.options) {
            const style = getComputedStyle(option);
            const couleur = lire(style.color);
            const propre = lire(style.backgroundColor);

            if (couleur === null) {
                continue;
            }

            const fond = propre !== null && propre.a > 0
                ? dessus(propre, fondDuMenu !== null && fondDuMenu.a > 0 ? { ...fondDuMenu, a: 1 } : fondParDefaut)
                : (fondDuMenu !== null && fondDuMenu.a > 0 ? { ...fondDuMenu, a: 1 } : fondParDefaut);
            const vue = dessus(couleur, fond);
            const trouve = rapport(vue, fond);

            if (trouve < 4.5) {
                defauts.push({ element: 'option de ' + decrire(menu, option.textContent, vue, fond), rapport: Math.round(trouve * 100) / 100, seuil: 4.5 });
            }
        }
    }

    return defauts;
}
