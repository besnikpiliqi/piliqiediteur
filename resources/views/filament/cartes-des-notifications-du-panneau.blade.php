{{--
    Les cartes des notifications dans chaque panneau, pour un compte
    connecté (voir App\Filament\CartesDesNotifications), et leur conduite
    dans le navigateur : la pile se place sous la barre du haut et sous les
    messages de Filament du même coin, ajoute les cartes d'un passage, la
    plus récente en haut, en garde trois au plus, fait bouger la cloche un
    instant et la met à jour ; chaque carte reste le temps dit, suspendu
    tant que la souris ou le clavier est dessus et reparti en entier quand
    ils la quittent, puis s'en va. Sa croix et la fin de son temps ne
    touchent pas la notification. Un bouton qui marque la notification
    comme lue (le markAsRead() de Filament) la marque par la cloche, puis
    ouvre son adresse. Avec « réduire les animations », la carte apparaît
    et s'en va sans glisser, sa barre ne défile pas et la cloche ne bouge
    pas (resources/css/cartes-des-notifications.css).
--}}
@if (filament()->auth()->check())
    @livewire(\App\Filament\CartesDesNotifications::NOM)

    <script>
        document.addEventListener('alpine:init', () => {
            const AU_PLUS = 3
            const SORTIE_EN_MS = 300
            const sansAnimation = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches

            window.Alpine.data('pileDesCartes', () => ({
                init() {
                    const coin = document.querySelector('.fi-no')

                    this.placer()

                    if (coin !== null) {
                        new MutationObserver(() => this.placer()).observe(coin, { attributes: true, childList: true, subtree: true })
                    }

                    window.addEventListener('resize', () => this.placer())

                    if (this.$root.children.length > 0) {
                        this.sonner()
                    }
                },

                // Sous la barre du haut et sous les messages de Filament
                // (« Enregistré »...) affichés dans le même coin.
                placer() {
                    const barre = document.querySelector('.fi-topbar')
                    let haut = barre === null ? 0 : barre.getBoundingClientRect().bottom

                    document.querySelectorAll('.fi-no .fi-no-notification').forEach((avis) => {
                        if (avis.offsetParent !== null && window.getComputedStyle(avis).visibility !== 'hidden') {
                            haut = Math.max(haut, avis.getBoundingClientRect().bottom)
                        }
                    })

                    const top = `${Math.round(haut) + 12}px`

                    if (this.$root.style.top !== top) {
                        this.$root.style.top = top
                    }
                },

                ajouter(cartes) {
                    cartes.forEach((carte) => this.$root.insertAdjacentHTML('afterbegin', carte))

                    Array.from(this.$root.children)
                        .filter((carte) => ! carte.classList.contains('piliqi-carte--partante'))
                        .slice(AU_PLUS)
                        .forEach((carte) => carte.dispatchEvent(new CustomEvent('piliqi-fermer-la-carte')))

                    this.sonner()
                    window.Livewire.dispatch('databaseNotificationsSent')
                },

                sonner() {
                    document.querySelectorAll('.fi-topbar-database-notifications-btn').forEach((cloche) => {
                        cloche.classList.remove('piliqi-cloche-qui-sonne')
                        void cloche.offsetWidth
                        cloche.classList.add('piliqi-cloche-qui-sonne')
                    })
                },
            }))

            window.Alpine.data('carteDeNotification', ({ notification, duree }) => ({
                minuterie: null,

                aMarquerCommeLue: false,

                init() {
                    this.$root.addEventListener('piliqi-fermer-la-carte', () => this.fermer())
                    this.reprendre()
                },

                reprendre() {
                    if (this.$root.matches(':hover') || this.$root.matches(':focus-within') || this.$root.classList.contains('piliqi-carte--partante')) {
                        return
                    }

                    clearTimeout(this.minuterie)
                    this.$refs.temps.classList.remove('piliqi-carte__temps--en-cours')
                    void this.$refs.temps.offsetWidth
                    this.$refs.temps.classList.add('piliqi-carte__temps--en-cours')
                    this.minuterie = setTimeout(() => this.fermer(), duree)
                },

                suspendre() {
                    clearTimeout(this.minuterie)
                    this.$refs.temps.classList.remove('piliqi-carte__temps--en-cours')
                },

                fermer() {
                    if (this.$root.classList.contains('piliqi-carte--partante')) {
                        return
                    }

                    clearTimeout(this.minuterie)
                    this.$root.classList.add('piliqi-carte--partante')
                    setTimeout(() => this.$root.remove(), sansAnimation() ? 0 : SORTIE_EN_MS)
                },

                // Les gestes que les boutons d'une notification de Filament
                // appellent : close(), markAsRead() et markAsUnread().
                close() {
                    this.fermer()
                },

                markAsRead() {
                    this.aMarquerCommeLue = true
                },

                markAsUnread() {},

                async suivre(evenement) {
                    const lien = evenement.target.closest('a[href]')

                    if (lien === null || ! this.aMarquerCommeLue) {
                        return
                    }

                    this.aMarquerCommeLue = false

                    if (evenement.ctrlKey || evenement.metaKey || evenement.shiftKey || lien.target === '_blank') {
                        this.$wire.marquerCommeLue(notification)

                        return
                    }

                    evenement.preventDefault()

                    try {
                        await this.$wire.marquerCommeLue(notification)
                    } finally {
                        window.location.assign(lien.href)
                    }
                },
            }))
        })
    </script>
@endif
