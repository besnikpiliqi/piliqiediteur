'use strict';

/*
 * Rejoue le script de l'avis des requêtes refusées (vue
 * filament/requete-refusee) dans une page réduite à ce qu'il touche : un
 * champ de saisie qui a le focus, une fenêtre de Filament fermée et une
 * autre qui s'ouvre et se ferme, et le crochet « request » de Livewire,
 * appelé comme Livewire
 * l'appelle pour une requête de fond (sondage, sans action ni saisie) ou
 * pour un geste de la personne (une action envoyée), puis refusée.
 *
 * Utilisé par RequetesRefuseesTest, en attendant les tests en navigateur :
 *   node avis-de-requete-refusee.js <fichier du script> <fichier des étapes>
 * Les étapes (JSON) : [{"faire": "refus", "statut": 404, "geste": false},
 * {"faire": "fermer"}, {"faire": "ouvrirLaFenetre"}, {"faire":
 * "fermerLaFenetre"}]. Écrit en JSON, pour chaque étape, ce que la page
 * montre ensuite.
 */

const fs = require('fs');
const vm = require('vm');

const [, , fichierDuScript, fichierDesEtapes] = process.argv;

let numeroSuivant = 0;

class Element {
    constructor(balise, nom = null) {
        this.tagName = balise.toUpperCase();
        this.nom = nom;
        this.numero = ++numeroSuivant;
        this.enfants = [];
        this.parentElement = null;
        this.attributs = {};
        this.dataset = {};
        this.className = '';
        this.id = '';
        this.type = '';
        this.texte = '';
        this.ecouteurs = {};
    }

    setAttribute(nom, valeur) {
        this.attributs[nom] = String(valeur);
    }

    append(...enfants) {
        for (const enfant of enfants) {
            enfant.remove();
            enfant.parentElement = this;
            this.enfants.push(enfant);
        }
    }

    remove() {
        if (this.parentElement !== null) {
            this.parentElement.enfants = this.parentElement.enfants.filter((enfant) => enfant !== this);
            this.parentElement = null;
        }
    }

    contains(element) {
        for (let courant = element; courant; courant = courant.parentElement) {
            if (courant === this) {
                return true;
            }
        }

        return false;
    }

    addEventListener(type, ecouteur) {
        (this.ecouteurs[type] ??= []).push(ecouteur);
    }

    click() {
        (this.ecouteurs.click ?? []).forEach((ecouteur) => ecouteur({ target: this }));
    }

    focus() {
        document.activeElement = this;
    }

    get textContent() {
        return this.texte + this.enfants.map((enfant) => enfant.textContent).join('');
    }

    set textContent(valeur) {
        this.texte = String(valeur);
        this.enfants = [];
    }

    *descendants() {
        for (const enfant of this.enfants) {
            yield enfant;
            yield* enfant.descendants();
        }
    }

    querySelectorAll(selecteur) {
        if (! /^(\.[\w-]+)+$/.test(selecteur)) {
            throw new Error(`Sélecteur non géré par cette page réduite : ${selecteur}`);
        }

        const classes = selecteur.split('.').filter(Boolean);

        return [...this.descendants()].filter((element) => classes.every((classe) => element.className.split(/\s+/).includes(classe)));
    }

    querySelector(selecteur) {
        return this.querySelectorAll(selecteur)[0] ?? null;
    }
}

const page = new Element('body', 'page');
const champ = new Element('textarea', 'champ');
const fenetre = new Element('div', 'fenetre');
fenetre.className = 'fi-modal';
const ligneDeLaFenetre = new Element('button', 'ligne de la fenêtre');
fenetre.append(ligneDeLaFenetre);
const fenetreFermee = new Element('div', 'fenêtre fermée');
fenetreFermee.className = 'fi-modal';
page.append(champ, fenetreFermee, fenetre);

const ecouteursDuDocument = {};
let rechargements = 0;
let crochetDesRequetes = null;

global.document = {
    body: page,
    activeElement: champ,
    createElement: (balise) => new Element(balise),
    getElementById: (id) => [...page.descendants()].find((element) => element.id === id) ?? null,
    querySelectorAll: (selecteur) => page.querySelectorAll(selecteur),
    addEventListener: (type, ecouteur) => (ecouteursDuDocument[type] ??= []).push(ecouteur),
};
global.window = { location: { reload: () => { rechargements += 1; } } };
global.Livewire = {
    hook: (nom, crochet) => {
        if (nom === 'request') {
            crochetDesRequetes = crochet;
        }
    },
};

vm.runInThisContext(fs.readFileSync(fichierDuScript, 'utf8'));
(ecouteursDuDocument['livewire:init'] ?? []).forEach((ecouteur) => ecouteur());

const charge = (geste) => JSON.stringify({
    _token: 'jeton',
    components: [{
        snapshot: '{}',
        updates: {},
        calls: geste ? [{ path: '', method: 'choisir', params: ['1'] }] : [],
    }],
});

const avisAffiches = () => [...page.descendants()].filter((element) => element.id === 'avis-de-requete-refusee');

const faire = {
    refus: ({ statut, geste }) => {
        const echecs = [];
        crochetDesRequetes({ url: '/livewire/update', options: {}, payload: charge(geste), respond: () => {}, succeed: () => {}, fail: (echec) => echecs.push(echec) });
        let empeche = false;
        echecs.forEach((echec) => echec({ status: statut, content: '', preventDefault: () => { empeche = true; } }));

        return { empeche };
    },
    fermer: () => {
        avisAffiches()[0].querySelector('.fermer').click();

        return {};
    },
    ouvrirLaFenetre: () => {
        fenetre.className = 'fi-modal fi-modal-open';
        ligneDeLaFenetre.focus();

        return {};
    },
    fermerLaFenetre: () => {
        fenetre.className = 'fi-modal';
        champ.focus();

        return {};
    },
};

const constats = JSON.parse(fs.readFileSync(fichierDesEtapes, 'utf8')).map((etape) => {
    const resultat = faire[etape.faire](etape);
    const affiches = avisAffiches();
    const cadre = affiches[0] ?? null;

    return {
        ...resultat,
        avis: affiches.length,
        numero: cadre?.numero ?? null,
        place: cadre?.parentElement?.nom ?? null,
        titre: cadre?.querySelector('.titre')?.textContent ?? null,
        boutons: cadre === null ? [] : cadre.querySelectorAll('.boutons')[0].enfants.map((bouton) => bouton.textContent),
        focus: document.activeElement.nom,
        rechargements,
    };
});

process.stdout.write(JSON.stringify(constats));
