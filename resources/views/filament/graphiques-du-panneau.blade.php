{{--
    La mise en forme des graphiques du tableau de bord (voir
    App\Filament\GrilleDesGraphiques), dans chaque panneau : chaque carte
    passe sa série à piliqiGraphique(), qui en fait la configuration du
    composant « chart » de Filament, celui de Chart.js déjà servi par PILIQI
    (filament/widgets) : les couleurs viennent du thème (les teintes de
    Filament, plus claires en sombre), lues à chaque dessin, les nombres et
    les info-bulles en français et en euros (« 18 420 € », « 1,2 k € »). Rien
    ne vient d'un autre site.
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
</script>
