{{--
    Complète les champs déclarés avec ->saisieEnDirect() (voir
    AppServiceProvider) : ceux-ci ne sont jamais réécrits par le serveur tant
    qu'ils ont le focus (modificateur "unintrusive"), mais une réponse
    Livewire arrivée pendant la frappe peut avoir remplacé leur valeur côté
    composant par une plus ancienne. Après chaque réponse, on renvoie donc ce
    que le champ affiche réellement si cela diffère : le calcul en direct
    porte toujours sur ce que l'utilisateur voit et tape, jamais sur une
    frappe perdue.
--}}
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('commit', ({ component, succeed }) => {
            succeed(() => {
                setTimeout(() => {
                    const champ = document.activeElement;

                    if (! (champ instanceof HTMLInputElement)) {
                        return;
                    }

                    const attribut = champ.getAttributeNames()
                        .find((nom) => nom.startsWith('wire:model') && nom.split('.').includes('unintrusive'));

                    if (! attribut || champ.closest('[wire\\:id]')?.getAttribute('wire:id') !== component.id) {
                        return;
                    }

                    const valeurDuComposant = component.$wire.get(champ.getAttribute(attribut));

                    if (String(valeurDuComposant ?? '') !== champ.value) {
                        champ.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }, 0);
            });
        });
    });
</script>
