{{--
    La mise en forme des graphiques du tableau de bord (voir
    App\Filament\GrilleDesGraphiques), dans chaque panneau : chaque carte
    passe sa série à piliqiGraphique(), qui en fait la configuration du
    composant « chart » de Filament, celui de Chart.js déjà servi par PILIQI
    (filament/widgets) : les couleurs viennent du thème (les teintes de
    Filament, plus claires en sombre), lues à chaque dessin, les nombres et
    les info-bulles en français et en euros (« 18 420 € », « 1,2 k € »). Rien
    ne vient d'un autre site. La carte « Écart des prix d'achat » (décision de
    l'éditeur du 21/11/2026, D76) n'a pas de canevas : elle écrit son propre
    `svg` (voir ecart-des-prix-d-achat.blade.php) et n'appelle pas
    piliqiGraphique().

    Les gestes de la grille qui ne changent que l'affichage de ce que la page
    contient déjà (le type d'un graphique, « Monter », « Descendre », la
    croix, « Classer par », « Afficher : ») se font aussitôt dans la page, et
    le serveur les retient ensuite en arrière-plan, sans redessiner la grille
    (décision de l'éditeur du 23/11/2026) : piliqiGrille() tient ce qui est
    affiché, ce que le serveur a retenu et ce qui attend sa réponse ; si le
    serveur refuse ou ne répond pas, l'écran revient à ce qu'il a retenu en
    dernier, avec une alerte.
--}}
<script>
    window.piliqiGraphique = (config) => {
        const TEINTES = ['primary', 'warning', 'success', 'danger', 'info', 'gray']
        const FORMES = { barres: 'bar', barres_couchees: 'bar', lignes: 'line', aires: 'line', anneau: 'doughnut', camembert: 'pie', radar: 'radar' }
        const COMPARAISON = ' (un an plus tôt)'
        const euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' })
        const compact = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 })
        const entier = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 })

        const couleur = (rang, opacite = 1) => {
            const sombre = document.documentElement.classList.contains('dark')
            const teinte = getComputedStyle(document.documentElement).getPropertyValue(`--${TEINTES[rang % TEINTES.length]}-${sombre ? 400 : 500}`).trim()

            return `rgba(${teinte === '' ? '99, 102, 241' : teinte}, ${opacite})`
        }
        const enTexte = (valeur, unite) => (valeur === null || valeur === undefined) ? '–' : (unite === 'nombre' ? entier.format(valeur) : euros.format(valeur))
        const graduation = (unite) => (valeur) => unite === 'nombre' ? entier.format(valeur) : `${compact.format(valeur)} €`

        const forme = FORMES[config.type] ?? 'bar'
        const circulaire = forme === 'doughnut' || forme === 'pie'
        const rempli = config.type === 'aires' || forme === 'radar'
        const rangDe = (jeu, rang) => jeu.libelle.endsWith(COMPARAISON)
            ? Math.max(0, config.jeux.findIndex((principal) => principal.libelle + COMPARAISON === jeu.libelle))
            : rang

        const datasets = config.jeux.map((jeu, rang) => {
            const teinte = rangDe(jeu, rang)
            const opacite = jeu.libelle.endsWith(COMPARAISON) ? 0.45 : 1
            const serie = { label: jeu.libelle, data: jeu.valeurs, unite: jeu.unite }

            if (circulaire) {
                serie.backgroundColor = (contexte) => couleur(contexte.dataIndex)
                serie.borderColor = 'rgba(0, 0, 0, 0)'

                return serie
            }

            serie.backgroundColor = () => couleur(teinte, rempli ? 0.25 * opacite : opacite)
            serie.borderColor = () => couleur(teinte, opacite)
            serie.pointBackgroundColor = () => couleur(teinte, opacite)
            serie.fill = rempli
            serie.tension = 0.3

            if (jeu.en_ligne && forme === 'bar') {
                serie.type = 'line'
                serie.backgroundColor = () => couleur(teinte, opacite)
                serie.fill = false
            }

            if (forme === 'bar' && serie.type !== 'line') {
                serie.borderRadius = 4
            }

            if (jeu.axe_de_droite) {
                serie.yAxisID = 'y2'
            }

            if (jeu.pointilles) {
                serie.borderDash = [6, 4]
            }

            return serie
        })

        const unite = (config.jeux.find((jeu) => ! jeu.axe_de_droite) ?? { unite: 'euros' }).unite
        const options = {
            maintainAspectRatio: false,
            indexAxis: config.type === 'barres_couchees' ? 'y' : 'x',
            plugins: {
                legend: { display: config.jeux.length > 1 || circulaire },
                tooltip: {
                    callbacks: {
                        label: (contexte) => ` ${contexte.dataset.label} : ${enTexte(contexte.raw, contexte.dataset.unite)}`,
                        afterBody: (elements) => (elements.length > 0 && config.info_bulles[elements[0].dataIndex]) ? config.info_bulles[elements[0].dataIndex] : '',
                    },
                },
            },
        }

        if (circulaire) {
            options.scales = { x: { display: false }, y: { display: false } }
        } else if (forme === 'radar') {
            options.scales = { x: { display: false }, y: { display: false }, r: { ticks: { callback: graduation(unite) } } }
        } else {
            options.scales = { [config.type === 'barres_couchees' ? 'x' : 'y']: { ticks: { callback: graduation(unite) } } }

            if (datasets.some((serie) => serie.yAxisID === 'y2')) {
                options.scales.y2 = { position: 'right', grid: { display: false }, ticks: { callback: graduation('euros') } }
            }
        }

        return { cachedData: { labels: config.etiquettes, datasets }, options, type: forme }
    }

    /*
     * La racine de la grille et le composant Livewire sont gardés hors de la
     * donnée Alpine : une carte, dont la donnée est imbriquée dans celle de
     * la grille, appelle ses méthodes avec une portée où `$root` est la
     * carte.
     */
    window.piliqiGrille = () => {
        // Les gestes de la grille que le serveur retient sans la redessiner (voir GrilleDesGraphiques).
        const gestesDePreference = ['changerLeType', 'changerLaMesure', 'changerLAffichage', 'monter', 'descendre', 'retirerLeGraphique']
        let racine = null
        let composant = null
        let minuterie = null
        const arrets = []

        return {
            ordre: [],
            disponibles: [],
            retires: [],
            enAttente: 0,
            alerte: '',
            serveur: { ordre: [], cartes: {} },

            init() {
                racine = this.$root
                composant = this.$wire.id
                this.lireLaPage()
                this.ordre = [...this.serveur.ordre]
                this.$watch('ordre', () => this.ordonner())

                arrets.push(Livewire.hook('commit', ({ component, commit, succeed, fail }) => {
                    if (component.id !== composant) {
                        return
                    }

                    const gestes = commit.calls.filter((appel) => gestesDePreference.includes(appel.method)).length

                    succeed(({ effects }) => {
                        if (effects.html !== undefined) {
                            setTimeout(() => this.apresLeRedessin(), 0)
                        }
                    })
                    fail(() => {
                        if (gestes > 0) {
                            this.echec(gestes)
                        }
                    })
                }))

                arrets.push(Livewire.hook('request', ({ payload, fail }) => {
                    let composants

                    try {
                        composants = JSON.parse(payload).components ?? []
                    } catch (erreur) {
                        return
                    }

                    const seulementNosGestes = composants.length > 0 && composants.every((unComposant) => {
                        try {
                            return JSON.parse(unComposant.snapshot).memo.id === composant
                                && unComposant.calls.length > 0
                                && unComposant.calls.every((appel) => gestesDePreference.includes(appel.method))
                        } catch (erreur) {
                            return false
                        }
                    })

                    if (seulementNosGestes) {
                        // L'alerte de la grille remplace la fenêtre d'erreur de Livewire ; une session expirée garde la sienne.
                        fail(({ status, preventDefault }) => {
                            if (status !== 419) {
                                preventDefault()
                            }
                        })
                    }
                }))
            },

            destroy() {
                arrets.forEach((arreter) => arreter())
            },

            get aAjouter() {
                return this.disponibles.filter((graphique) => ! this.ordre.includes(graphique.valeur))
            },

            sections() {
                return Array.from(racine.querySelectorAll('[data-grille-cartes] > [data-graphique]'))
            },

            /*
             * Ce que la page écrit : les cartes dans l'ordre du serveur, avec le
             * type, la mesure et l'affichage qu'il en a retenus, et les
             * graphiques que le menu « Ajouter un graphique » peut proposer.
             */
            lireLaPage() {
                this.disponibles = JSON.parse(racine.dataset.disponibles ?? '[]')
                this.serveur = { ordre: [], cartes: {} }

                for (const section of this.sections().filter((carte) => ! this.retires.includes(carte.dataset.graphique))) {
                    const etat = { type: section.dataset.type }

                    if (section.dataset.mesure !== undefined) {
                        etat.mesure = section.dataset.mesure
                    }

                    if (section.dataset.affichage !== undefined) {
                        etat.affichage = section.dataset.affichage
                    }

                    this.serveur.ordre.push(section.dataset.graphique)
                    this.serveur.cartes[section.dataset.graphique] = etat
                }
            },

            /*
             * Un geste de préférence : l'écran change aussitôt (`local`), puis le
             * serveur le retient en arrière-plan (`enregistrer`, un appel de la
             * grille qui ne la redessine pas et rend ce qu'il a retenu, ou null
             * quand il refuse). Tant qu'un geste n'a pas de réponse, la page
             * garde ce que la personne a fait, même si le serveur redessine la
             * grille pour un autre geste ; la dernière réponse remet la page à
             * l'état que le serveur a retenu.
             */
            appliquer(local, enregistrer, valeur) {
                local(valeur)
                this.enAttente++
                Promise.resolve(enregistrer(valeur)).then((etat) => this.accuser(etat))
            },

            accuser(etat) {
                this.enAttente--

                if (etat !== null && etat !== undefined) {
                    this.serveur = etat
                }

                if (this.enAttente === 0) {
                    this.adopter()
                }
            },

            /*
             * L'enregistrement n'a pas abouti (réseau coupé, erreur du serveur) :
             * l'alerte est celle de la grille, qui ne passe pas par le serveur
             * que le geste n'a pas pu joindre ; un refus du serveur, lui, a déjà
             * son alerte (voir GrilleDesGraphiques::modifier()).
             */
            echec(gestes) {
                this.enAttente = Math.max(0, this.enAttente - gestes)
                this.alerte = 'L’enregistrement n’a pas abouti : votre dernier choix n’a pas pu être retenu, et l’écran revient à ce qui est enregistré. Vérifiez votre connexion, puis recommencez.'
                clearTimeout(minuterie)
                minuterie = setTimeout(() => { this.alerte = '' }, 10000)

                if (this.enAttente === 0) {
                    this.adopter()
                }
            },

            /*
             * Plus aucun geste sans réponse : la page montre ce que le serveur a
             * retenu en dernier. Une carte qu'il a retirée disparaît de la page
             * (cachée jusque-là), une qu'il a gardée revient, et une carte qu'il
             * vient d'écrire et que la page ne connaissait pas (un graphique
             * ajouté) vient à la fin.
             */
            adopter() {
                for (const section of this.sections()) {
                    const graphique = section.dataset.graphique

                    if (this.retires.includes(graphique) && ! this.serveur.ordre.includes(graphique)) {
                        section.querySelectorAll('[data-graphique-pose]').forEach((pose) => Alpine.$data(pose)?.getChart?.()?.destroy())
                        section.remove()
                    }
                }

                this.retires = []

                const presentes = this.sections().map((section) => section.dataset.graphique)

                this.ordre = [
                    ...this.serveur.ordre.filter((graphique) => presentes.includes(graphique)),
                    ...presentes.filter((graphique) => ! this.serveur.ordre.includes(graphique)),
                ]

                for (const section of this.sections()) {
                    const etat = this.serveur.cartes[section.dataset.graphique]

                    if (etat !== undefined) {
                        Object.assign(Alpine.$data(section), etat)
                    }
                }

                this.ordonner()
            },

            /*
             * Le serveur a redessiné la grille (période, magasin, comparaison,
             * graphique ajouté) : sans geste en attente, la page est celle qu'il
             * a écrite ; sinon elle garde les gestes de la personne, auxquels les
             * cartes nouvelles s'ajoutent.
             */
            apresLeRedessin() {
                this.disponibles = JSON.parse(racine.dataset.disponibles ?? '[]')

                if (this.enAttente === 0) {
                    this.lireLaPage()
                    this.adopter()

                    return
                }

                const presentes = this.sections().map((section) => section.dataset.graphique)

                this.ordre = [
                    ...this.ordre.filter((graphique) => presentes.includes(graphique)),
                    ...presentes.filter((graphique) => ! this.ordre.includes(graphique) && ! this.retires.includes(graphique)),
                ]
                this.ordonner()
            },

            deplacer(graphique, sens) {
                const rang = this.ordre.indexOf(graphique)
                const autre = rang + sens

                if (rang < 0 || autre < 0 || autre >= this.ordre.length) {
                    return
                }

                const ordre = [...this.ordre]
                ;[ordre[rang], ordre[autre]] = [ordre[autre], ordre[rang]]
                this.ordre = ordre
            },

            retirer(graphique) {
                this.retires = [...this.retires, graphique]
                this.ordre = this.ordre.filter((autre) => autre !== graphique)
            },

            /*
             * Les cartes dans l'ordre de `ordre`, sans en recréer aucune : une
             * carte qui change de place garde son graphique, ses tableaux ouverts
             * et, si elle l'avait, le focus.
             */
            ordonner() {
                const grille = racine.querySelector('[data-grille-cartes]')
                const actif = document.activeElement
                let precedente = null

                if (grille === null) {
                    return
                }

                for (const graphique of this.ordre) {
                    const section = this.sections().find((carte) => carte.dataset.graphique === graphique)

                    if (section === undefined) {
                        continue
                    }

                    const attendue = precedente === null ? grille.firstElementChild : precedente.nextElementSibling

                    if (section !== attendue) {
                        grille.insertBefore(section, attendue)
                    }

                    precedente = section
                }

                if (actif !== null && actif !== document.body && document.activeElement !== actif && actif.isConnected) {
                    actif.focus({ preventScroll: true })
                }
            },
        }
    }

    /*
     * Pose, dans la zone d'une carte, le graphique de Chart.js du type qu'elle
     * a maintenant, à partir du modèle que la zone porte (le composant
     * « chart » de Filament, chargé une fois) ; changer le type, ou montrer
     * l'autre classement, en pose un autre sans rien demander au serveur. La
     * zone est ignorée par Livewire (wire:ignore) : le serveur ne la touche
     * que pour la remplacer, quand sa série change.
     */
    window.piliqiPoserLeGraphique = (zone, type, visible) => {
        const ancien = zone.querySelector(':scope > [data-graphique-pose]')

        if (ancien !== null) {
            Alpine.$data(ancien)?.getChart?.()?.destroy()
            ancien.remove()
        }

        if (! visible) {
            return
        }

        const pose = zone.querySelector(':scope > template').content.firstElementChild.cloneNode(true)
        pose.setAttribute('data-graphique-pose', type)
        zone.append(pose)
    }

    /*
     * Les lignes d'un tableau de calcul de la carte « Écart des prix d'achat »,
     * écrites dans la page en JSON compact (voir ecart-des-prix-d-achat.blade.php)
     * et posées dans le tableau quand il est montré : chaque ligne est
     * [rang de sa classe, cellules], une cellule son texte seul ou [texte,
     * rang de sa classe, étendue] ; le rang est celui de la classe dans la
     * table `classes` du JSON, qui écrit chaque classe une fois (le rang 0 est
     * « aucune classe »). `montre` vaut false quand le tableau n'est pas
     * montré : il se vide.
     */
    window.piliqiPoserLesLignes = (corps, montre, affichage, mois) => {
        if (! montre) {
            corps.replaceChildren()

            return
        }

        const racine = corps.closest('[data-ecart-vues]')
        racine._calculs ??= JSON.parse(racine.querySelector(':scope > script[data-ecart="calculs"]').textContent)
        const { classes } = racine._calculs
        const lignes = document.createDocumentFragment()

        for (const [rangDeLaClasse, cellules] of racine._calculs[affichage][mois]) {
            const ligne = document.createElement('tr')
            ligne.className = classes[rangDeLaClasse]

            for (const donnee of cellules) {
                const [texte, rangDeLaClasseDeLaCellule = 0, etendue] = typeof donnee === 'string' ? [donnee] : donnee
                const cellule = document.createElement('td')
                cellule.className = classes[rangDeLaClasseDeLaCellule]
                cellule.colSpan = etendue ?? 1
                cellule.textContent = texte
                ligne.append(cellule)
            }

            lignes.append(ligne)
        }

        corps.replaceChildren(lignes)
    }
</script>
