{{-- Voir App\Filament\GrilleDesGraphiques et graphiques-du-panneau.blade.php. --}}
@php
    use App\Domain\TableauDeBord\Enums\Graphique;
    use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
    use App\Domain\TableauDeBord\Support\SerieDuGraphique;

    $enTexte = fn (?int $valeur, string $unite): string => $valeur === null ? '–' : ($unite === SerieDuGraphique::NOMBRE ? number_format($valeur, 0, ',', ' ') : $euros($valeur));
@endphp

<x-filament-widgets::widget class="piliqi-graphiques">
    @if (array_filter($chiffres) !== [])
        <div class="piliqi-graphiques__chiffres" aria-label="Chiffres clés du mois">
            @if ($chiffres['ca'] !== null)
                <div class="piliqi-graphiques__chiffre" data-chiffre="ca">
                    <div class="piliqi-graphiques__libelle">CA HT du mois</div>
                    <div class="piliqi-graphiques__valeur">{{ $euros($chiffres['ca']['valeur']) }}</div>
                    @if ($chiffres['ca']['variation'] !== null)
                        <div class="piliqi-graphiques__precision">{{ $chiffres['ca']['variation'] }}</div>
                    @endif
                </div>
            @endif

            @if ($chiffres['achats'] !== null)
                <div class="piliqi-graphiques__chiffre" data-chiffre="achats">
                    <div class="piliqi-graphiques__libelle">Achats HT du mois</div>
                    <div class="piliqi-graphiques__valeur">{{ $euros($chiffres['achats']['valeur']) }}</div>
                    @if ($chiffres['achats']['variation'] !== null)
                        <div class="piliqi-graphiques__precision">{{ $chiffres['achats']['variation'] }}</div>
                    @endif
                </div>
            @endif

            @if ($chiffres['tva'] !== null)
                <div class="piliqi-graphiques__chiffre" data-chiffre="tva">
                    <div class="piliqi-graphiques__libelle">
                        TVA à payer (estimation)
                        <x-filament::icon
                            icon="heroicon-m-information-circle"
                            class="h-4 w-4"
                            title="Calculée depuis vos documents et vos charges validées. L'état de TVA officiel, avec l'exigibilité des services et les règles de la déclaration, viendra avec la comptabilité."
                            label="Calculée depuis vos documents et vos charges validées. L'état de TVA officiel, avec l'exigibilité des services et les règles de la déclaration, viendra avec la comptabilité."
                        />
                    </div>
                    @if ($chiffres['tva']['franchise'])
                        <div class="piliqi-graphiques__valeur">TVA non applicable (franchise en base)</div>
                    @else
                        <div class="piliqi-graphiques__valeur" data-tva="reelle">{{ $euros($chiffres['tva']['a_payer']) }}</div>
                        <div class="piliqi-graphiques__precision">collectée {{ $euros($chiffres['tva']['collectee']) }} − déductible {{ $euros($chiffres['tva']['deductible']) }}{{ $chiffres['tva']['avec_les_charges'] ? ($chiffres['tva']['sans_celles_de_la_societe'] ? ' (sans les charges de « Toute la société »)' : '') : ' (sans les charges)' }}</div>
                        @if ($chiffres['tva']['estimation'] !== null)
                            <div class="piliqi-graphiques__precision" data-tva="estimation">estimation avec les charges à valider : {{ $euros($chiffres['tva']['estimation']) }}</div>
                        @endif
                    @endif
                </div>
            @endif

            @if ($chiffres['restant_du'] !== null)
                <div class="piliqi-graphiques__chiffre" data-chiffre="restant_du">
                    <div class="piliqi-graphiques__libelle">Restant dû des clients</div>
                    <div class="piliqi-graphiques__valeur">{{ $euros($chiffres['restant_du']['valeur']) }}</div>
                    <div class="piliqi-graphiques__precision">dont {{ $euros($chiffres['restant_du']['en_retard']) }} en retard</div>
                </div>
            @endif
        </div>
    @endif

    <div class="piliqi-graphiques__filtres">
        <label class="piliqi-graphiques__filtre">
            <span>Période</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:change="choisirLaPeriode($event.target.value)" data-filtre="periode">
                    @foreach ($periodes as $valeur => $libelle)
                        <option value="{{ $valeur }}" @selected($reglages->periode->value === $valeur)>{{ $libelle }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        @if ($magasins !== [])
            <label class="piliqi-graphiques__filtre">
                <span>Magasin</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:change="choisirLeMagasin($event.target.value)" data-filtre="magasin">
                        <option value="" @selected($magasinChoisi === null)>Tous mes magasins</option>
                        @foreach ($magasins as $uuid => $nom)
                            <option value="{{ $uuid }}" @selected($magasinChoisi === $uuid)>{{ $nom }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif

        <label class="piliqi-graphiques__case">
            <x-filament::input.checkbox wire:change="comparer($event.target.checked)" :checked="$reglages->comparer" data-filtre="comparer" />
            <span>Comparer avec l'année précédente</span>
        </label>

        {{-- Le menu revient de lui-même sur « — Choisir — » dès qu'un graphique est choisi, et ce choix n'envoie rien (constat de l'éditeur du 15/11/2026) ; un graphique affiché n'y est plus proposé. --}}
        <label class="piliqi-graphiques__filtre">
            <span>Ajouter un graphique</span>
            <x-filament::input.wrapper :disabled="$aAjouter === []">
                @if ($aAjouter === [])
                    <x-filament::input.select disabled data-filtre="ajouter">
                        <option value="" selected>Tous les graphiques sont affichés</option>
                    </x-filament::input.select>
                @else
                    <x-filament::input.select
                        x-on:change="const graphique = $event.target.value; $event.target.value = ''; if (graphique !== '') { $wire.ajouterUnGraphique(graphique) }"
                        data-filtre="ajouter"
                    >
                        <option value="" selected>— Choisir —</option>
                        @foreach ($aAjouter as $graphique)
                            <option value="{{ $graphique->value }}">{{ $graphique->getLabel() }}</option>
                        @endforeach
                    </x-filament::input.select>
                @endif
            </x-filament::input.wrapper>
        </label>
    </div>

    @if ($cartes === [])
        <p class="piliqi-graphiques__gris">Aucun graphique : ajoutez-en un par le menu « Ajouter un graphique ».</p>
    @endif

    <div class="piliqi-graphiques__grille">
        @foreach ($cartes as $rang => ['carte' => $carte, 'serie' => $serie, 'config' => $config])
            @php($graphique = $carte->graphique)
            <section
                class="piliqi-graphiques__carte"
                wire:key="carte-{{ $graphique->value }}"
                data-graphique="{{ $graphique->value }}"
                aria-label="{{ $graphique->getLabel() }}"
                x-data="{ chiffres: false }"
            >
                {{-- Le titre passe à la ligne dans sa colonne ; le type, « Monter », « Descendre » et la croix restent ensemble, à droite de la première ligne, quelle que soit sa longueur (constat de l'éditeur du 15/11/2026). --}}
                <div class="piliqi-graphiques__tete" data-tete>
                    <h3 class="piliqi-graphiques__titre" data-titre>{{ $graphique->getLabel() }}</h3>

                    <div class="piliqi-graphiques__commandes" data-commandes>
                        <x-filament::input.wrapper class="piliqi-graphiques__type">
                            <x-filament::input.select aria-label="Type du graphique" wire:change="changerLeType('{{ $graphique->value }}', $event.target.value)" data-geste="type">
                                @foreach ($graphique->types() as $type)
                                    <option value="{{ $type->value }}" @selected($carte->type === $type)>{{ $type->getLabel() }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>

                        <x-filament::button size="xs" color="gray" wire:click="monter('{{ $graphique->value }}')" :disabled="$rang === 0" data-geste="monter">Monter</x-filament::button>
                        <x-filament::button size="xs" color="gray" wire:click="descendre('{{ $graphique->value }}')" :disabled="$rang === count($cartes) - 1" data-geste="descendre">Descendre</x-filament::button>
                        <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" label="Retirer ce graphique" tooltip="Retirer ce graphique" wire:click="retirerLeGraphique('{{ $graphique->value }}')" data-geste="retirer" />
                    </div>
                </div>

                @if ($graphique === Graphique::MeilleursProduits)
                    <div class="piliqi-graphiques__mesure">
                        <x-filament::input.wrapper>
                            <x-filament::input.select aria-label="Classer par" wire:change="changerLaMesure('{{ $graphique->value }}', $event.target.value)" data-geste="mesure">
                                <option value="{{ CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES }}" @selected(! $carte->parQuantite())>Par chiffre d'affaires</option>
                                <option value="{{ CarteDuTableauDeBord::PAR_QUANTITE }}" @selected($carte->parQuantite())>Par quantité</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif

                <p class="piliqi-graphiques__gris">{{ $graphique->ceQuIlCompte() }}</p>

                @if ($serie->note !== null)
                    <p class="piliqi-graphiques__gris" data-note>{{ $serie->note }}</p>
                @endif

                @if ($serie->estVide())
                    <p class="piliqi-graphiques__gris" data-vide>Aucune donnée sur la période.</p>
                @else
                    {{-- Une carte déplacée, ou dont la série change, reçoit un nouveau canevas : Chart.js n'en redessine jamais un qui porte déjà un graphique. --}}
                    <div class="piliqi-graphiques__zone" wire:key="dessin-{{ $graphique->value }}-{{ $rang }}-{{ md5(json_encode($config)) }}">
                        <div
                            x-load
                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                            wire:ignore
                            x-data="chart(piliqiGraphique(@js($config)))"
                            style="height: 100%;"
                        >
                            <canvas x-ref="canvas" role="img" aria-label="{{ $graphique->getLabel() }}"></canvas>
                            <span x-ref="backgroundColorElement" class="text-gray-100 dark:text-gray-800"></span>
                            <span x-ref="borderColorElement" class="text-gray-400"></span>
                            <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                            <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
                        </div>
                    </div>
                @endif

                <div>
                    <x-filament::link tag="button" type="button" x-on:click="chiffres = ! chiffres" x-bind:aria-expanded="chiffres" data-geste="chiffres">
                        <span x-text="chiffres ? 'Masquer les chiffres' : 'Voir les chiffres'">Voir les chiffres</span>
                    </x-filament::link>
                </div>

                <div class="piliqi-graphiques__chiffres-de-la-carte" x-show="chiffres" x-cloak data-chiffres-de-la-carte>
                    <table>
                        <thead>
                            <tr>
                                <th scope="col"></th>
                                @foreach ($serie->jeux as $jeu)
                                    <th scope="col">{{ $jeu['libelle'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($serie->etiquettes as $position => $etiquette)
                                <tr>
                                    <th scope="row">{{ $etiquette }}@if (isset($serie->infoBulles[$position])) <span class="piliqi-graphiques__gris">({{ $serie->infoBulles[$position] }})</span>@endif</th>
                                    @foreach ($serie->jeux as $jeu)
                                        <td class="piliqi-graphiques__montant">{{ $enTexte($jeu['valeurs'][$position] ?? null, $jeu['unite']) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endforeach
    </div>
</x-filament-widgets::widget>
