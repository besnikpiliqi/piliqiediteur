{{--
    Une liste d'une fiche en un seul tableau, au modèle D (voir
    App\Filament\TableauEntry et resources/css/tableaux.css) : les titres
    une fois en haut, une ligne par élément, « – » en gris pour une valeur
    chiffrée nulle, la ligne en gris quand toutes ses valeurs chiffrées
    sont nulles, le total en dernière ligne et les informations dessous.
--}}
@php
    $conteneurs = $getChildComponentContainers();
    $total = $getTotal();
    $informations = $getInformations();
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    <div
        {{
            $attributes
                ->merge([
                    'id' => $getId(),
                ], escape: false)
                ->merge($getExtraAttributes(), escape: false)
                ->class(['piliqi-tableau'])
        }}
    >
        @if (count($conteneurs) || $total !== null)
            @php
                $colonnes = $getColonnes();
            @endphp

            <table>
                <thead>
                    <tr>
                        @foreach ($colonnes as $colonne)
                            <th scope="col" @class(['piliqi-chiffre' => $colonne['chiffre']])>{{ $colonne['libelle'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($conteneurs as $conteneur)
                        @php
                            $ligne = $getLigne($conteneur);
                        @endphp

                        <tr @class(['piliqi-ligne-nulle' => $ligne['nulle']])>
                            @foreach ($ligne['cellules'] as $cellule)
                                <td @class(['piliqi-chiffre' => $cellule['chiffre']])>
                                    @if ($cellule['cachee'])
                                    @elseif ($cellule['nulle'])
                                        <span class="piliqi-nul">–</span>

                                        @if (filled($cellule['aide']))
                                            <div class="piliqi-aide">{{ $cellule['aide'] }}</div>
                                        @endif
                                    @else
                                        {{ $cellule['composant'] }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>

                        @foreach ($ligne['dessous'] as $dessous)
                            <tr class="piliqi-dessous">
                                <td colspan="{{ count($colonnes) }}">{{ $dessous }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>

                @if ($total !== null || count($informations))
                    <tfoot>
                        @if ($total !== null)
                            <tr class="piliqi-total">
                                @foreach ($total as $cellule)
                                    <td colspan="{{ $cellule['colspan'] }}" @class(['piliqi-chiffre' => $cellule['chiffre']])>
                                        @if ($cellule['nulle'])
                                            <span class="piliqi-nul">–</span>
                                        @else
                                            {{ $cellule['valeur'] }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endif

                        @foreach ($informations as $information)
                            <tr class="piliqi-information">
                                @foreach ($information as $cellule)
                                    <td colspan="{{ $cellule['colspan'] }}" @class(['piliqi-chiffre' => $cellule['chiffre']])>
                                        @if ($cellule['nulle'])
                                            <span class="piliqi-nul">–</span>
                                        @else
                                            {{ $cellule['valeur'] }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tfoot>
                @endif
            </table>
        @elseif (($placeholder = $getPlaceholder()) !== null)
            <x-filament-infolists::entries.placeholder>
                {{ $placeholder }}
            </x-filament-infolists::entries.placeholder>
        @endif
    </div>
</x-dynamic-component>
