// Surveillance de chaque page des tests en navigateur, posée avant tout
// script de la page (voir Navigation::ouvrir()) :
// - chaque erreur JavaScript, promesse rejetée, appel à console.error ou
//   console.warn, ressource de l'application qui ne se charge pas et
//   requête vers un autre site s'inscrit dans window.__piliqi.erreurs, que
//   le test relit et vide à chaque vérification (voir
//   Navigation::verifier()). Le navigateur des tests ne joint aucun autre
//   site (voir Navigation::ouvrir()) : seules sont tolérées les deux
//   ressources que Filament fait demander à d'autres sites par chaque page
//   (TIERS_DE_FILAMENT : la police Inter et l'image des initiales du
//   compte connecté), refusées par le navigateur sans aucune requête
//   réelle ;
// - chaque requête fetch ou XMLHttpRequest (celles de Livewire comprises,
//   l'envoi d'un fichier passant par la seconde) se compte : lancées, en
//   cours et finies ; une page qui commence à en quitter une autre (un lien
//   suivi, un formulaire envoyé, une redirection) le note ;
// - chaque téléchargement que le script de la page déclenche (un lien
//   « download » qu'il clique, comme Livewire pour le fichier que rend une
//   action) est lu : son nom, son type, sa taille, ses premiers
//   caractères et tout son contenu, en base64, s'inscrivent dans
//   window.__piliqi.telechargements (voir Navigation::telechargements()) ;
// - window.__piliqi.auRepos(ms) dit si la page est au repos (voir
//   Navigation::attendre()) : chargée, ne quittant pas la page, sans
//   requête ni lecture de téléchargement en cours, chaque composant visible
//   que Filament charge à part (x-load : une liste à recherche) démarré, et
//   rien de tout cela n'ayant changé pendant ms millisecondes. Ce délai est
//   compté par une minuterie de la page posée après le geste : elle ne
//   passe qu'après chaque minuterie plus proche posée par le geste (le
//   regroupement des appels de Livewire, l'anti-rebond d'une saisie), même
//   quand la machine chargée retarde toutes les minuteries ; une requête
//   qu'un geste ne lance qu'après une minuterie n'est donc jamais manquée.
(() => {
    if (window.__piliqi) {
        return;
    }

    const TIERS_DE_FILAMENT = ['https://fonts.bunny.net/', 'https://ui-avatars.com/api/'];

    const etat = { erreurs: [], enCours: 0, lancees: 0, finies: 0, lectures: 0, quitte: false, telechargements: [] };
    window.__piliqi = etat;

    const texte = (valeur) => {
        if (valeur instanceof Error) {
            return valeur.stack || valeur.message;
        }

        try {
            return typeof valeur === 'string' ? valeur : JSON.stringify(valeur);
        } catch (erreur) {
            return String(valeur);
        }
    };
    const noter = (genre, message) => etat.erreurs.push(genre + ' : ' + message);
    const dAutreSite = (adresse) => {
        const url = new URL(adresse, location.href);

        return ['http:', 'https:', 'ws:', 'wss:'].includes(url.protocol) && url.origin !== location.origin;
    };
    const noterUnAutreSite = (adresse) => {
        if (! TIERS_DE_FILAMENT.some((tiers) => String(adresse).startsWith(tiers))) {
            noter('requête vers un autre site', adresse);
        }
    };

    for (const niveau of ['error', 'warn']) {
        const original = console[niveau];
        console[niveau] = function (...valeurs) {
            noter('console.' + niveau, valeurs.map(texte).join(' '));

            return original.apply(console, valeurs);
        };
    }

    window.addEventListener('error', (evenement) => {
        const cible = evenement.target;
        const adresse = cible && cible !== window ? (cible.src || cible.href || '') : '';

        if (adresse === '') {
            noter('erreur JavaScript', evenement.message + (evenement.filename ? ' (' + evenement.filename + ':' + evenement.lineno + ')' : ''));
        } else if (dAutreSite(adresse)) {
            noterUnAutreSite(adresse);
        } else {
            noter('ressource introuvable', adresse);
        }
    }, true);

    window.addEventListener('unhandledrejection', (evenement) => noter('promesse rejetée', texte(evenement.reason)));

    new PerformanceObserver((liste) => {
        for (const ressource of liste.getEntries()) {
            if (dAutreSite(ressource.name)) {
                noterUnAutreSite(ressource.name);
            }
        }
    }).observe({ type: 'resource', buffered: true });

    window.addEventListener('beforeunload', () => {
        etat.quitte = true;
    });

    const fetchOriginal = window.fetch;
    window.fetch = function (ressource, ...suite) {
        const adresse = ressource instanceof Request ? ressource.url : String(ressource);

        if (dAutreSite(adresse)) {
            noterUnAutreSite(adresse);
        }

        etat.enCours++;
        etat.lancees++;

        return fetchOriginal.call(this, ressource, ...suite).finally(() => {
            etat.enCours--;
            etat.finies++;
        });
    };

    const ouvrirOriginal = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (methode, adresse, ...suite) {
        if (dAutreSite(String(adresse))) {
            noterUnAutreSite(String(adresse));
        }

        return ouvrirOriginal.call(this, methode, adresse, ...suite);
    };
    const envoyerOriginal = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...corps) {
        etat.enCours++;
        etat.lancees++;
        this.addEventListener('loadend', () => {
            etat.enCours--;
            etat.finies++;
        });

        return envoyerOriginal.apply(this, corps);
    };

    const enBase64 = (fichier) => new Promise((resolve, reject) => {
        const lecteur = new FileReader();
        lecteur.onload = () => resolve(String(lecteur.result).split(',')[1] ?? '');
        lecteur.onerror = () => reject(lecteur.error);
        lecteur.readAsDataURL(fichier);
    });

    const cliquerOriginal = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function () {
        if (this.hasAttribute('download')) {
            const nom = this.download;
            etat.lectures++;
            fetchOriginal(this.href)
                .then((reponse) => reponse.blob())
                .then(async (fichier) => etat.telechargements.push({ nom, type: fichier.type, taille: fichier.size, debut: await fichier.slice(0, 16).text(), contenu: await enBase64(fichier) }))
                .catch((erreur) => noter('téléchargement illisible', nom + ' : ' + texte(erreur)))
                .finally(() => etat.lectures--);
        }

        return cliquerOriginal.call(this);
    };

    const pret = () => document.readyState === 'complete'
        && ! etat.quitte
        && etat.enCours === 0
        && etat.lectures === 0
        && [...document.querySelectorAll('[x-load]')].every((composant) => composant.offsetParent === null || composant._x_dataStack !== undefined);

    etat.auRepos = (ms) => new Promise((resolve) => {
        if (! pret()) {
            resolve(false);

            return;
        }

        const activite = etat.lancees + etat.finies;
        setTimeout(() => resolve(pret() && etat.lancees + etat.finies === activite), ms);
    });
})();
