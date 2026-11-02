// Surveillance de chaque page des tests en navigateur, posée avant tout
// script de la page (voir Navigation::ouvrir()) :
// - chaque erreur JavaScript, promesse rejetée, appel à console.error ou
//   console.warn et ressource de l'application qui ne se charge pas
//   s'inscrit dans window.__piliqi.erreurs, que le test relit et vide à
//   chaque vérification (voir Navigation::verifier()) ; une ressource d'un
//   autre site (la police de Filament) n'est pas celle de l'application ;
// - chaque requête fetch en cours (celles de Livewire comprises) se compte
//   dans window.__piliqi.enCours, avec l'instant de la dernière activité,
//   pour attendre que la page soit au repos (voir Navigation::attendre()).
(() => {
    if (window.__piliqi) {
        return;
    }

    const etat = { erreurs: [], enCours: 0, derniereActivite: performance.now() };
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
        } else if (new URL(adresse, location.href).origin === location.origin) {
            noter('ressource introuvable', adresse);
        }
    }, true);

    window.addEventListener('unhandledrejection', (evenement) => noter('promesse rejetée', texte(evenement.reason)));

    const fetchOriginal = window.fetch;
    window.fetch = function (...arguments_) {
        etat.enCours++;
        etat.derniereActivite = performance.now();

        return fetchOriginal.apply(this, arguments_).finally(() => {
            etat.enCours--;
            etat.derniereActivite = performance.now();
        });
    };
})();
