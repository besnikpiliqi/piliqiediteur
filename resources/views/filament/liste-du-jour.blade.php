{{--
    La fenêtre qu'un clic sur un jour d'un graphique par période ouvre (choix
    de l'éditeur du 16/11/2026, lot GRAPH-AN ; maquette 01-graph-an.html du
    22/11/2026) : pour chaque magasin compté, un tableau des listes de
    documents que la personne y voit en entier, avec le nombre de documents
    de ce jour, leur total HT net et le lien vers la liste du magasin,
    filtrée sur ce jour. Aucun nom de client, aucun numéro de document : un
    document ne s'ouvre que pour qui peut le voir, depuis la liste. Dans le
    panneau d'un magasin, comme avec un seul magasin compté, aucun nom de
    magasin. La fenêtre est celle du navigateur (`dialog`) : elle garde le
    focus, se ferme par Échap, et Livewire apprend sa fermeture ; Livewire
    ne touche jamais à ses attributs (wire:ignore.self), sans quoi il
    retirerait l'attribut `open` que le navigateur y écrit.

    Variables : $liste (voir GrilleDesGraphiques::listeDuJour()).
--}}
<dialog
    class="piliqi-graphiques__fenetre"
    aria-labelledby="titre-de-la-liste-du-jour"
    wire:ignore.self
    x-data
    x-init="if (! $el.open) { $el.showModal() }"
    x-on:close="$wire.fermerLaListe()"
    x-on:click="if ($event.target === $el) { $el.close() }"
    data-liste-du-jour
>
    <div class="piliqi-graphiques__fenetre-contenu">
        <div class="piliqi-graphiques__fenetre-tete">
            <div>
                <h2 class="piliqi-graphiques__fenetre-titre" id="titre-de-la-liste-du-jour">{{ $liste['titre'] }}</h2>
                <p class="piliqi-graphiques__gris">Ce que le clic sur ce jour ouvre : les listes de documents, déjà filtrées sur ce jour.</p>
            </div>

            <x-filament::button size="sm" color="gray" x-on:click="$el.closest('dialog').close()" data-geste="fermer-la-liste">Fermer</x-filament::button>
        </div>

        <div class="piliqi-graphiques__fenetre-corps">
            @forelse ($liste['magasins'] as $duMagasin)
                <section data-liste-du-magasin>
                    @if ($duMagasin['nom'] !== null)
                        <h3 class="piliqi-graphiques__fenetre-magasin">{{ $duMagasin['nom'] }}</h3>
                    @endif

                    <div class="piliqi-graphiques__chiffres-de-la-carte piliqi-graphiques__chiffres-de-la-carte--ouvert">
                        <table>
                            <thead>
                                <tr>
                                    <th scope="col">Liste</th>
                                    <th scope="col" class="piliqi-graphiques__montant">Documents</th>
                                    <th scope="col" class="piliqi-graphiques__montant">Total HT net</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($duMagasin['listes'] as $uneListe)
                                    <tr>
                                        <th scope="row"><a class="piliqi-graphiques__lien" href="{{ $uneListe['url'] }}" data-liste-lien>{{ $uneListe['libelle'] }}</a></th>
                                        <td class="piliqi-graphiques__montant">{{ $uneListe['nombre'] }}</td>
                                        <td class="piliqi-graphiques__montant">{{ $uneListe['ht'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @empty
                <p class="piliqi-graphiques__gris">Aucun magasin compté.</p>
            @endforelse
        </div>

        <div class="piliqi-graphiques__fenetre-pied">
            <span class="piliqi-graphiques__gris">Un document ne s'ouvre que pour qui peut le voir.</span>
            <x-filament::button size="sm" x-on:click="$el.closest('dialog').close()">Revenir au tableau de bord</x-filament::button>
        </div>
    </div>
</dialog>
