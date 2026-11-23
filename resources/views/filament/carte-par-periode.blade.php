{{--
    Le contenu d'une carte par période (choix de l'éditeur du 16/11/2026, lot
    GRAPH-AN ; maquette 01-graph-an.html du 22/11/2026), dans la carte commune
    des graphiques (voir grille-des-graphiques.blade.php, qui garde l'en-tête :
    type, « Monter », « Descendre », croix) : le menu « Afficher » (les
    montants ou l'écart en % sur un an) et la case « Ajouter la tendance »,
    la ligne grise de la carte et ses phrases, le fil du détail ouvert avec
    « Revenir », le dessin que le serveur écrit (voir SvgDesBarresParPeriode),
    sa légende, la phrase qui dit qu'une barre se clique, et « Voir les
    chiffres », le tableau des mêmes valeurs au centime. Les textes, les
    classes et les nombres viennent de LectureDUneCarteParPeriode.

    Variables : $carte, $serie, $lecture (la lecture), $graphique.
--}}
<div class="piliqi-graphiques__options" data-options>
    <label @class(['piliqi-graphiques__option', 'piliqi-graphiques__option--coupee' => ! $lecture['ecartPossible']])>
        <span>Afficher</span>
        <x-filament::input.wrapper :disabled="! $lecture['ecartPossible']">
            <x-filament::input.select :disabled="! $lecture['ecartPossible']" wire:change="changerLeMode('{{ $graphique->value }}', $event.target.value)" data-geste="mode">
                <option value="montants" @selected(! $lecture['ecart'])>Les montants</option>
                <option value="ecart" @selected($lecture['ecart'])>Écart en % sur un an</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </label>

    <label @class(['piliqi-graphiques__case', 'piliqi-graphiques__case--coupee' => ! $lecture['tendancePossible']])>
        <x-filament::input.checkbox wire:change="ajouterLaTendance('{{ $graphique->value }}', $event.target.checked)" :checked="$lecture['tendance']" :disabled="! $lecture['tendancePossible']" data-geste="tendance" />
        <span>{{ $lecture['tendanceLibelle'] }}</span>
    </label>
</div>

<p class="piliqi-graphiques__gris">{{ $serie->descriptif ?? $graphique->ceQuIlCompte() }}</p>

@if ($serie->note !== null)
    <p class="piliqi-graphiques__gris" data-note>{{ $serie->note }}</p>
@endif

@if ($lecture['enDetail'])
    <nav class="piliqi-graphiques__fil" aria-label="Où vous êtes dans le détail" data-fil>
        <button type="button" class="piliqi-graphiques__fil-lien" wire:click="remonterAuNiveau('{{ $graphique->value }}', 0)" data-fil-niveau="0">{{ $lecture['racine'] }}</button>

        @foreach ($lecture['chemin'] as $niveau => $etape)
            <span class="piliqi-graphiques__fil-sep" aria-hidden="true">›</span>

            @if ($loop->last)
                <span class="piliqi-graphiques__fil-ici" aria-current="page" data-fil-ici>{{ $etape['libelle'] }}</span>
            @else
                <button type="button" class="piliqi-graphiques__fil-lien" wire:click="remonterAuNiveau('{{ $graphique->value }}', {{ $niveau + 1 }})" data-fil-niveau="{{ $niveau + 1 }}">{{ $etape['libelle'] }}</button>
            @endif
        @endforeach

        <x-filament::button size="xs" color="gray" wire:click="revenir('{{ $graphique->value }}')" class="piliqi-graphiques__revenir" data-geste="revenir">Revenir</x-filament::button>
    </nav>
@endif

@if ($lecture['vide'])
    <p class="piliqi-graphiques__gris" data-vide>Aucune donnée sur la période.</p>
@else
    <div class="piliqi-graphiques__zone-periode" data-zone-periode>
        {!! $lecture['dessin'] !!}
    </div>

    @if ($lecture['legende'] !== [])
        <div class="piliqi-graphiques__legende" data-legende>
            @foreach ($lecture['legende'] as $element)
                <span><i class="piliqi-graphiques__puce piliqi-graphiques__puce--{{ $element['forme'] }} piliqi-graphiques__puce--{{ $element['cle'] }}" aria-hidden="true"></i>{{ $element['libelle'] }}</span>
            @endforeach
        </div>
    @endif

    @if ($lecture['astuce'] !== null)
        <p class="piliqi-graphiques__gris" data-astuce>{{ $lecture['astuce'] }}</p>
    @endif

    <div>
        <x-filament::link tag="button" type="button" x-on:click="chiffres = ! chiffres" x-bind:aria-expanded="chiffres" aria-expanded="false" data-geste="chiffres">
            <span x-text="chiffres ? 'Masquer les chiffres' : 'Voir les chiffres'">Voir les chiffres</span>
        </x-filament::link>
    </div>

    <div class="piliqi-graphiques__chiffres-de-la-carte" x-show="chiffres" x-cloak data-chiffres-de-la-carte>
        <table>
            <thead>
                <tr>
                    @foreach ($lecture['tableau']['tete'] as $titre)
                        <th scope="col">{{ $titre }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($lecture['tableau']['lignes'] as $ligne)
                    <tr>
                        <th scope="row">{{ $ligne['libelle'] }}</th>
                        @foreach ($ligne['cellules'] as $cellule)
                            <td @class(['piliqi-graphiques__montant', 'piliqi-graphiques__montant--negatif' => $cellule['negatif']])>{{ $cellule['texte'] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
