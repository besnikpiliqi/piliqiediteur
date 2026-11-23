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
    `svg` (voir ecart-des-prix-d-achat.blade.php) et n'appelle pas ce script.

    Les noms longs (demande de l'éditeur du 20/11/2026) : sur l'axe des noms
    d'un graphique en barres, comme dans la légende d'un anneau ou d'un
    camembert, un nom trop long pour la place s'écrit à partir de sa
    gauche, son début visible, la fin cachée par « … » ; une info-bulle
    (`.piliqi-graphiques__nom-complet`, voir graphiques.css) en montre le nom
    entier quand la souris est au-dessus du nom, et au toucher ou au clic
    sur un téléphone ou une tablette, où il n'y a pas de souris. Le plugin
    `piliqiNomsLongs`, que le composant « chart » de Filament charge par
    `window.filamentChartJsPlugins`, dessine l'info-bulle ; la largeur
    disponible vient de la largeur du graphique, relue à chaque dessin.
--}}
<script>
    (() => {
        // La largeur moyenne d'un caractère des graduations (corps 12), en pixels.
        const CARACTERE = 6.5
        const DUREE_AU_TOUCHER = 5000

        const tronquer = (texte, maximum) => texte.length <= maximum ? texte : `${texte.slice(0, Math.max(1, maximum - 1)).trimEnd()}…`

        // Le nombre de caractères qu'un nom peut garder : 40 % de la largeur du
        // graphique pour l'axe des noms d'un graphique en barres couchées, la
        // largeur de sa rangée pour des barres debout, la moitié de la largeur
        // pour la légende d'un anneau ou d'un camembert.
        window.piliqiNombreDeCaracteres = (graphique, axe) => {
            const nombre = Math.max(1, graphique.data.labels.length)
            const largeur = axe === 'y'
                ? graphique.width * 0.4
                : (axe === 'x' ? (graphique.chartArea ? graphique.chartArea.width : graphique.width) / nombre : (graphique.width - 60) / 2)

            return Math.max(6, Math.floor(largeur / CARACTERE))
        }
        window.piliqiNomTronque = tronquer

        // La légende que Chart.js donne à ce type de graphique (celle d'un
        // anneau lit ses parts, non ses jeux), que la nôtre complète.
        window.piliqiLegendeParDefaut = (graphique) => graphique.constructor.overrides[graphique.config.type]?.plugins?.legend ?? graphique.constructor.defaults.plugins.legend

        const bulle = (graphique) => {
            if (! graphique.piliqiBulle) {
                const hote = graphique.canvas.parentNode
                const element = document.createElement('div')

                if (getComputedStyle(hote).position === 'static') {
                    hote.style.position = 'relative'
                }

                element.className = 'piliqi-graphiques__nom-complet'
                element.setAttribute('role', 'tooltip')
                element.setAttribute('data-nom-complet', '')
                element.hidden = true
                hote.appendChild(element)
                graphique.piliqiBulle = element
            }

            return graphique.piliqiBulle
        }

        const cacher = (graphique) => {
            if (graphique.piliqiBulle) {
                graphique.piliqiBulle.hidden = true
            }
        }

        const montrer = (graphique, nom, x, y, auToucher) => {
            const element = bulle(graphique)

            element.textContent = nom
            element.hidden = false
            element.style.left = `${Math.max(4, Math.min(x + 12, graphique.width - element.offsetWidth - 4))}px`
            element.style.top = `${y + element.offsetHeight + 20 > graphique.height ? Math.max(4, y - element.offsetHeight - 10) : y + 16}px`
            clearTimeout(graphique.piliqiMinuterie)

            if (auToucher) {
                graphique.piliqiMinuterie = setTimeout(() => cacher(graphique), DUREE_AU_TOUCHER)
            }
        }

        // L'axe des noms d'un graphique en barres, ou null : le rang du nom sous
        // ce point, quand ce point est dans la zone des noms, hors de la zone du
        // dessin.
        const nomSousLePoint = (graphique, x, y) => {
            const axe = graphique.options.indexAxis === 'y' ? 'y' : 'x'
            const echelle = graphique.scales[axe]

            if (! echelle || echelle.type !== 'category' || echelle.options.display === false || ! graphique.chartArea) {
                return null
            }

            const dansLaZone = axe === 'y'
                ? x < graphique.chartArea.left && y >= graphique.chartArea.top && y <= graphique.chartArea.bottom
                : y > graphique.chartArea.bottom && x >= graphique.chartArea.left && x <= graphique.chartArea.right

            if (! dansLaZone) {
                return null
            }

            const rang = Math.round(echelle.getValueForPixel(axe === 'y' ? y : x))

            return graphique.piliqiTronques && graphique.piliqiTronques.has(rang) ? rang : null
        }

        const plugin = {
            id: 'piliqiNomsLongs',
            beforeUpdate: (graphique) => { graphique.piliqiTronques = new Set() },
            afterEvent: (graphique, arguments_) => {
                if (! graphique.options.plugins.piliqiNomsLongs || ! graphique.options.plugins.piliqiNomsLongs.actif) {
                    return
                }

                const evenement = arguments_.event

                if (evenement.type === 'mouseout') {
                    return cacher(graphique)
                }

                if (! ['mousemove', 'click', 'touchstart'].includes(evenement.type) || evenement.x === null || evenement.y === null) {
                    return
                }

                // Un anneau ou un camembert n'a pas d'axe des noms (ses axes sont
                // masqués) : sa légende ouvre et ferme elle-même l'info-bulle (voir
                // options.plugins.legend).
                const echelle = graphique.scales[graphique.options.indexAxis === 'y' ? 'y' : 'x']

                if (! echelle || echelle.type !== 'category' || echelle.options.display === false) {
                    return
                }

                const rang = nomSousLePoint(graphique, evenement.x, evenement.y)

                rang === null
                    ? cacher(graphique)
                    : montrer(graphique, graphique.data.labels[rang], evenement.x, evenement.y, evenement.type !== 'mousemove')
            },
        }

        if (! (window.filamentChartJsPlugins ?? []).some((existant) => existant.id === plugin.id)) {
            window.filamentChartJsPlugins = [...(window.filamentChartJsPlugins ?? []), plugin]
        }

        window.piliqiMontrerLeNomComplet = montrer
        window.piliqiCacherLeNomComplet = cacher
    })()

    // L'info-bulle d'une barre d'une carte par période (lot GRAPH-AN) : le
    // serveur écrit, sur chaque colonne de son `svg` (`data-info-bulle`), du
    // JSON, le titre de la barre et une ligne par série ; la page ne le lit
    // qu'avec textContent, jamais comme du HTML. Elle suit la souris, ou la
    // colonne qui a le focus, et se ferme par Échap, quand la souris quitte la
    // colonne ou que la page défile (WCAG 1.4.13).
    ;(() => {
        if (window.piliqiInfoBullesDesBarres) {
            return
        }

        window.piliqiInfoBullesDesBarres = true

        let bulle = null

        const element = () => {
            if (bulle === null) {
                bulle = document.createElement('div')
                bulle.className = 'piliqi-graphiques__info-bulle'
                bulle.setAttribute('role', 'tooltip')
                bulle.setAttribute('data-info-bulle-des-barres', '')
                bulle.hidden = true
                document.body.appendChild(bulle)
            }

            return bulle
        }

        const cacher = () => {
            if (bulle !== null) {
                bulle.hidden = true
            }
        }

        const montrer = (colonne, x, y) => {
            let contenu

            try {
                contenu = JSON.parse(colonne.getAttribute('data-info-bulle'))
            } catch (erreur) {
                return cacher()
            }

            const infoBulle = element()
            const titre = document.createElement('strong')

            titre.textContent = contenu.titre
            infoBulle.replaceChildren(titre)

            for (const ligne of contenu.lignes) {
                const texte = document.createElement('span')

                texte.className = 'piliqi-graphiques__info-bulle-ligne'

                if (ligne.couleur !== '') {
                    const puce = document.createElement('i')

                    puce.className = 'piliqi-graphiques__puce piliqi-graphiques__puce--info'
                    puce.style.background = ligne.couleur
                    puce.setAttribute('aria-hidden', 'true')
                    texte.appendChild(puce)
                }

                texte.appendChild(document.createTextNode(ligne.texte))
                infoBulle.appendChild(texte)
            }

            infoBulle.hidden = false

            const boite = infoBulle.getBoundingClientRect()
            let gauche = x + 14
            let haut = y + 14

            if (gauche + boite.width > window.innerWidth - 8) {
                gauche = x - boite.width - 14
            }

            if (haut + boite.height > window.innerHeight - 8) {
                haut = y - boite.height - 14
            }

            infoBulle.style.left = `${Math.max(8, gauche)}px`
            infoBulle.style.top = `${Math.max(8, haut)}px`
        }

        const colonneSous = (cible) => cible instanceof Element ? cible.closest('.piliqi-graphiques__periode-dessin [data-info-bulle]') : null

        document.addEventListener('mousemove', (evenement) => {
            const colonne = colonneSous(evenement.target)

            colonne === null ? cacher() : montrer(colonne, evenement.clientX, evenement.clientY)
        })
        document.addEventListener('focusin', (evenement) => {
            const colonne = colonneSous(evenement.target)

            if (colonne !== null) {
                const boite = colonne.getBoundingClientRect()

                montrer(colonne, boite.left + boite.width / 2, boite.top + 20)
            }
        })
        document.addEventListener('focusout', cacher)
        document.addEventListener('keydown', (evenement) => {
            if (evenement.key === 'Escape') {
                cacher()
            }
        })
        document.addEventListener('scroll', cacher, true)
    })()

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
        // La légende d'un anneau ou d'un camembert : les noms trop longs
        // s'écrivent par leur début, et l'info-bulle dit le nom entier au
        // survol, au clic ou au toucher.
        const legende = circulaire ? {
            display: true,
            labels: {
                generateLabels: (graphique) => window.piliqiLegendeParDefaut(graphique).labels.generateLabels(graphique)
                    .map((element) => ({ ...element, text: window.piliqiNomTronque(element.text, window.piliqiNombreDeCaracteres(graphique, null)) })),
            },
            onHover: (evenement, element, legend) => {
                const complet = legend.chart.data.labels[element.index]

                if (element.text !== complet) {
                    window.piliqiMontrerLeNomComplet(legend.chart, complet, evenement.x, evenement.y, false)
                }
            },
            onLeave: (evenement, element, legend) => window.piliqiCacherLeNomComplet(legend.chart),
            onClick: function (evenement, element, legend) {
                window.piliqiLegendeParDefaut(legend.chart).onClick.call(this, evenement, element, legend)
                const complet = legend.chart.data.labels[element.index]

                if (element.text !== complet) {
                    window.piliqiMontrerLeNomComplet(legend.chart, complet, evenement.x, evenement.y, true)
                }
            },
        } : { display: config.jeux.length > 1 }

        const options = {
            maintainAspectRatio: false,
            indexAxis: config.type === 'barres_couchees' ? 'y' : 'x',
            plugins: {
                piliqiNomsLongs: { actif: true },
                legend: legende,
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
            const axeDesNoms = config.type === 'barres_couchees' ? 'y' : 'x'

            options.scales = {
                [config.type === 'barres_couchees' ? 'x' : 'y']: { ticks: { callback: graduation(unite) } },
                [axeDesNoms]: {
                    ticks: {
                        // Le nom commence à gauche : trop long, il perd sa fin, jamais son début.
                        callback: function (valeur) {
                            const complet = this.getLabelForValue(valeur)
                            const tronque = window.piliqiNomTronque(complet, window.piliqiNombreDeCaracteres(this.chart, axeDesNoms))

                            if (tronque !== complet && this.chart.piliqiTronques) {
                                this.chart.piliqiTronques.add(Number(valeur))
                            }

                            return tronque
                        },
                    },
                },
            }

            if (datasets.some((serie) => serie.yAxisID === 'y2')) {
                options.scales.y2 = { position: 'right', grid: { display: false }, ticks: { callback: graduation('euros') } }
            }
        }

        return { cachedData: { labels: config.etiquettes, datasets }, options, type: forme }
    }
</script>
