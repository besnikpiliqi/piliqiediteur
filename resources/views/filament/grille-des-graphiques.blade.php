{{-- Voir App\Filament\GrilleDesGraphiques et graphiques-du-panneau.blade.php. --}}
@php
    use App\Domain\TableauDeBord\Enums\Graphique;
    use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
    use App\Domain\TableauDeBord\Support\SerieDuGraphique;

    $enTexte = fn (?int $valeur, string $unite): string => $valeur === null ? '–' : ($unite === SerieDuGraphique::NOMBRE ? number_format($valeur, 0, ',', ' ') : $euros($valeur));
@endphp

<x-filament-widgets::widget class="piliqi-graphiques">
    {{-- Les gestes qui ne changent que l'affichage (type, « Monter », « Descendre », croix, « Classer par », « Afficher : ») se font aussitôt dans la page et s'enregistrent en arrière-plan (décision de l'éditeur du 23/11/2026) : voir piliqiGrille() dans graphiques-du-panneau.blade.php. --}}
    <div x-data="piliqiGrille()" data-disponibles="{{ json_encode($disponibles) }}">
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

            {{-- La TVA à payer (lot TVA27, maquette « Message TVA avant PILIQI ») : sur toute la société, celle de la période en cours après le report du crédit, la pastille du crédit à reporter, celle de la déclaration qui sert de point de départ, et le calcul ; ailleurs, celle du mois, sans le report. Rien en franchise en base ni avant le choix du régime. --}}
            @if ($chiffres['tva'] !== null)
                {{-- Un bloc, jamais la forme en une ligne : la vue a d'autres blocs de PHP, et Blade lirait comme du PHP tout ce qui suit une forme en une ligne, jusqu'à la fin du bloc suivant. --}}
                @php
                    $tva = $chiffres['tva'];
                @endphp
                <div class="piliqi-graphiques__chiffre" data-chiffre="tva">
                    <div class="piliqi-graphiques__libelle">
                        {{ $tva['report'] ? 'TVA à payer (estimation) · '.\Illuminate\Support\Str::ucfirst((string) $tva['periode']) : 'TVA à payer (estimation)' }}
                        <x-filament::icon
                            icon="heroicon-m-information-circle"
                            class="h-4 w-4"
                            title="Calculée depuis vos documents et vos charges validées, avec le crédit de TVA reporté de la période d'avant. L'état de TVA officiel, avec l'exigibilité des services et les règles de la déclaration, viendra avec la comptabilité."
                            label="Calculée depuis vos documents et vos charges validées, avec le crédit de TVA reporté de la période d'avant. L'état de TVA officiel, avec l'exigibilité des services et les règles de la déclaration, viendra avec la comptabilité."
                        />
                    </div>
                    <div class="piliqi-graphiques__valeur" data-tva="reelle">{{ $euros($tva['a_payer']) }}</div>
                    @if ($tva['report'])
                        <div class="piliqi-graphiques__pastilles">
                            @if ($tva['credit_a_reporter'] > 0)
                                <x-filament::badge color="info" data-tva="credit-a-reporter">Crédit de TVA à reporter : {{ $euros($tva['credit_a_reporter']) }}</x-filament::badge>
                            @endif
                            @if ($tva['pastille'] !== null)
                                <x-filament::badge :color="$tva['pastille']['couleur']" data-tva="credit-de-depart">{{ $tva['pastille']['texte'] }}</x-filament::badge>
                            @endif
                        </div>
                        <div class="piliqi-graphiques__precision" data-tva="calcul">{{ $tva['calcul'] }}</div>
                        <div class="piliqi-graphiques__precision">collectée {{ $euros($tva['collectee']) }} − déductible {{ $euros($tva['deductible']) }}</div>
                        @if ($tva['estimation'] !== null)
                            <div class="piliqi-graphiques__precision" data-tva="estimation">estimation avec les charges à valider : {{ $tva['estimation'] >= 0 ? $euros($tva['estimation']).' à payer' : $euros(-$tva['estimation']).' de crédit à reporter' }}</div>
                        @endif
                    @else
                        <div class="piliqi-graphiques__precision">collectée {{ $euros($tva['collectee']) }} − déductible {{ $euros($tva['deductible']) }}{{ $tva['avec_les_charges'] ? ($tva['sans_celles_de_la_societe'] ? ' (sans les charges de « Toute la société »)' : '') : ' (sans les charges)' }}</div>
                        @if ($tva['estimation'] !== null)
                            <div class="piliqi-graphiques__precision" data-tva="estimation">estimation avec les charges à valider : {{ $euros($tva['estimation']) }}</div>
                        @endif
                        <div class="piliqi-graphiques__precision" data-tva="sans-le-report">sans le report du crédit de TVA, qui se calcule sur toute la société</div>
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

        {{-- Le menu revient de lui-même sur « — Choisir — » dès qu'un graphique est choisi, et ce choix n'envoie rien (constat de l'éditeur du 15/11/2026) ; un graphique affiché n'y est plus proposé, et un graphique retiré l'est aussitôt, sans attendre le serveur. --}}
        <label class="piliqi-graphiques__filtre">
            <span>Ajouter un graphique</span>
            <x-filament::input.wrapper alpine-disabled="aAjouter.length === 0">
                <x-filament::input.select
                    x-on:change="const graphique = $event.target.value; $event.target.value = ''; if (graphique !== '') { $wire.ajouterUnGraphique(graphique) }"
                    x-bind:disabled="aAjouter.length === 0"
                    :disabled="count($affiches) === count($disponibles)"
                    data-filtre="ajouter"
                >
                    <option value="" selected x-text="aAjouter.length === 0 ? 'Tous les graphiques sont affichés' : '— Choisir —'">{{ count($affiches) === count($disponibles) ? 'Tous les graphiques sont affichés' : '— Choisir —' }}</option>
                    @foreach ($disponibles as $graphique)
                        <option value="{{ $graphique['valeur'] }}" @if (in_array($graphique['valeur'], $affiches, true)) hidden disabled @endif x-bind:hidden="ordre.includes('{{ $graphique['valeur'] }}')" x-bind:disabled="ordre.includes('{{ $graphique['valeur'] }}')">{{ $graphique['libelle'] }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    <div class="piliqi-graphiques__alerte" role="alert" x-show="alerte !== ''" x-cloak data-alerte-de-la-grille>
        <span x-text="alerte"></span>
        <button type="button" class="piliqi-graphiques__alerte-fermer" aria-label="Fermer l'alerte" x-on:click="alerte = ''">×</button>
    </div>

    <p class="piliqi-graphiques__gris" x-show="ordre.length === 0" @if ($cartes !== []) x-cloak @endif>Aucun graphique : ajoutez-en un par le menu « Ajouter un graphique ».</p>

    <div class="piliqi-graphiques__grille" data-grille-cartes>
        @foreach ($cartes as $rang => ['carte' => $carte, 'serie' => $serie, 'faces' => $faces, 'ecart' => $ecart])
            @php
                $graphique = $carte->graphique;
                $valeur = $graphique->value;
                $plusieursFaces = count($faces) > 1;
                $etatDeLaCarte = "{ chiffres: false, calcul: false, type: '{$carte->type->value}'"
                    .($graphique === Graphique::MeilleursProduits ? ", mesure: '{$carte->mesure}'" : '')
                    .($ecart !== null ? ", affichage: '{$carte->affichage}', titres: ".\Illuminate\Support\Js::from($ecart['titres']) : '')
                    .' }';
            @endphp
            {{-- L'écart des prix d'achat est nommé par son titre, celui de l'affichage choisi ; les autres cartes par leur libellé. Le type, la mesure et l'affichage que le serveur a retenus sont écrits dans la carte (data-) pour que la page les relise après chaque redessin (voir piliqiGrille()). --}}
            <section
                @class(['piliqi-graphiques__carte', 'piliqi-graphiques__carte--ecart' => $ecart !== null])
                wire:key="carte-{{ $valeur }}"
                data-graphique="{{ $valeur }}"
                data-type="{{ $carte->type->value }}"
                @if ($graphique === Graphique::MeilleursProduits) data-mesure="{{ $carte->mesure }}" @endif
                @if ($ecart !== null) data-affichage="{{ $carte->affichage }}" @endif
                @if ($ecart !== null) aria-labelledby="titre-{{ $valeur }}" @else aria-label="{{ $graphique->getLabel() }}" @endif
                x-data="{{ $etatDeLaCarte }}"
                x-show="! retires.includes('{{ $valeur }}')"
            >
                {{-- Le titre passe à la ligne dans sa colonne ; le type, « Monter », « Descendre » et la croix restent ensemble, à droite de la première ligne, quelle que soit sa longueur (constat de l'éditeur du 15/11/2026). --}}
                <div class="piliqi-graphiques__tete" data-tete>
                    <h3 class="piliqi-graphiques__titre" id="titre-{{ $valeur }}" data-titre @if ($ecart !== null) x-text="titres[affichage]" @endif>{{ $ecart === null ? $graphique->getLabel() : $ecart['titres'][$carte->affichage] }}</h3>

                    <div class="piliqi-graphiques__commandes" data-commandes>
                        <x-filament::input.wrapper class="piliqi-graphiques__type">
                            <x-filament::input.select aria-label="Type du graphique" x-on:change="appliquer((valeur) => type = valeur, (valeur) => $wire.changerLeType('{{ $valeur }}', valeur), $event.target.value)" data-geste="type">
                                @foreach ($graphique->types() as $type)
                                    <option value="{{ $type->value }}" @selected($carte->type === $type) x-bind:selected="type === '{{ $type->value }}'">{{ $type->getLabel() }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>

                        <x-filament::button size="xs" color="gray" x-on:click="appliquer(() => deplacer('{{ $valeur }}', -1), () => $wire.monter('{{ $valeur }}'))" x-bind:disabled="ordre.indexOf('{{ $valeur }}') <= 0" :disabled="$rang === 0" wire:target="geste-de-la-page" data-geste="monter">Monter</x-filament::button>
                        <x-filament::button size="xs" color="gray" x-on:click="appliquer(() => deplacer('{{ $valeur }}', 1), () => $wire.descendre('{{ $valeur }}'))" x-bind:disabled="ordre.indexOf('{{ $valeur }}') < 0 || ordre.indexOf('{{ $valeur }}') === ordre.length - 1" :disabled="$rang === count($cartes) - 1" wire:target="geste-de-la-page" data-geste="descendre">Descendre</x-filament::button>
                        <x-filament::icon-button icon="heroicon-m-x-mark" color="gray" label="Retirer ce graphique" tooltip="Retirer ce graphique" x-on:click="appliquer(() => retirer('{{ $valeur }}'), () => $wire.retirerLeGraphique('{{ $valeur }}'))" wire:target="geste-de-la-page" data-geste="retirer" />
                    </div>
                </div>

                @if ($graphique === Graphique::MeilleursProduits)
                    <div class="piliqi-graphiques__mesure">
                        <x-filament::input.wrapper>
                            <x-filament::input.select aria-label="Classer par" x-on:change="appliquer((valeur) => mesure = valeur, (valeur) => $wire.changerLaMesure('{{ $valeur }}', valeur), $event.target.value)" data-geste="mesure">
                                <option value="{{ CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES }}" @selected(! $carte->parQuantite()) x-bind:selected="mesure === '{{ CarteDuTableauDeBord::PAR_CHIFFRE_D_AFFAIRES }}'">Par chiffre d'affaires</option>
                                <option value="{{ CarteDuTableauDeBord::PAR_QUANTITE }}" @selected($carte->parQuantite()) x-bind:selected="mesure === '{{ CarteDuTableauDeBord::PAR_QUANTITE }}'">Par quantité</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif

                @if ($ecart !== null)
                    @include('filament.ecart-des-prix-d-achat', ['ecart' => $ecart, 'note' => $serie->note, 'graphique' => $graphique, 'carte' => $carte])
                @else
                    <p class="piliqi-graphiques__gris">{{ $graphique->ceQuIlCompte() }}</p>

                    @if ($serie->note !== null)
                        <p class="piliqi-graphiques__gris" data-note>{{ $serie->note }}</p>
                    @endif

                    @if ($graphique === Graphique::TvaAPayerParMois && $lienDuCredit !== null)
                        <p class="piliqi-graphiques__gris" data-lien-du-credit><x-filament::link :href="$lienDuCredit">Indiquer ou voir le crédit de TVA</x-filament::link></p>
                    @endif

                    @foreach ($faces as $variante => ['serie' => $face, 'config' => $config])
                        {{-- Les deux classements des meilleurs produits sont dans la page : « Classer par » montre l'un ou l'autre sans appeler le serveur (décision de l'éditeur du 23/11/2026). --}}
                        <div @if ($plusieursFaces) x-show="mesure === '{{ $variante }}'" @if ($carte->mesure !== $variante) x-cloak @endif @endif data-face="{{ $variante }}">
                            @if ($face->estVide())
                                <p class="piliqi-graphiques__gris" data-vide>Aucune donnée sur la période.</p>
                            @else
                                {{-- La zone ne se redessine pas avec le serveur (wire:ignore) : elle change de graphique, de type ou de classement, dans la page, sans le serveur (voir piliqiPoserLeGraphique()), et ne reçoit un nouveau canevas que si sa série change (sa clé). Chart.js n'en redessine jamais un qui porte déjà un graphique. --}}
                                <div class="piliqi-graphiques__zone" wire:key="dessin-{{ $valeur }}-{{ $variante }}-{{ md5(json_encode($config)) }}" wire:ignore x-effect="piliqiPoserLeGraphique($el, type, {{ $plusieursFaces ? "mesure === '{$variante}'" : 'true' }})">
                                    <template>
                                        <div
                                            x-load
                                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                                            x-data="chart(piliqiGraphique({ ...@js($config), type: type }))"
                                            style="height: 100%;"
                                        >
                                            <canvas x-ref="canvas" role="img" aria-label="{{ $graphique->getLabel() }}"></canvas>
                                            <span x-ref="backgroundColorElement" class="text-gray-100 dark:text-gray-800"></span>
                                            <span x-ref="borderColorElement" class="text-gray-400"></span>
                                            <span x-ref="gridColorElement" class="text-gray-200 dark:text-gray-800"></span>
                                            <span x-ref="textColorElement" class="text-gray-500 dark:text-gray-400"></span>
                                        </div>
                                    </template>
                                </div>
                            @endif
                        </div>
                    @endforeach

                    <div>
                        <x-filament::link tag="button" type="button" x-on:click="chiffres = ! chiffres" x-bind:aria-expanded="chiffres" wire:target="geste-de-la-page" data-geste="chiffres">
                            <span x-text="chiffres ? 'Masquer les chiffres' : 'Voir les chiffres'">Voir les chiffres</span>
                        </x-filament::link>
                    </div>

                    @foreach ($faces as $variante => ['serie' => $face])
                        <div class="piliqi-graphiques__chiffres-de-la-carte" x-show="chiffres{{ $plusieursFaces ? " && mesure === '{$variante}'" : '' }}" x-cloak data-chiffres-de-la-carte>
                            <table>
                                <thead>
                                    <tr>
                                        <th scope="col"></th>
                                        @foreach ($face->jeuxDuTableau() as $jeu)
                                            <th scope="col">{{ $jeu['libelle'] }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($face->etiquettes as $position => $etiquette)
                                        <tr>
                                            <th scope="row">{{ $etiquette }}@if (isset($face->infoBulles[$position])) <span class="piliqi-graphiques__gris">({{ $face->infoBulles[$position] }})</span>@endif</th>
                                            @foreach ($face->jeuxDuTableau() as $jeu)
                                                <td class="piliqi-graphiques__montant">{{ $enTexte($jeu['valeurs'][$position] ?? null, $jeu['unite']) }}</td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif
            </section>
        @endforeach
    </div>
    </div>
</x-filament-widgets::widget>
