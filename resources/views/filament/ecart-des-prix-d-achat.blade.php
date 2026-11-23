{{--
    Le contenu de la carte « Écart des prix d'achat » (décision de l'éditeur
    du 21/11/2026, D76, B6 et B7) : la structure de la maquette que l'éditeur
    a validée, dans la carte commune des graphiques (voir
    grille-des-graphiques.blade.php, qui garde l'en-tête : type, « Monter »,
    « Descendre », croix). Les textes, les classes et les nombres viennent de
    TextesDeLEcartDesPrixDAchat::lecture() et du `svg` de
    SvgDeLEcartDesPrixDAchat, tels quels. Chaque élément qu'un test lit porte
    un `data-ecart`.

    Choisir un mois, changer d'affichage avec le menu « Afficher : », ouvrir
    « Voir les chiffres » et « Voir le calcul… » n'appellent jamais le
    serveur pour lire des données (décision de l'éditeur du 23/11/2026) : la
    page contient déjà tout ce qu'ils montrent, les deux affichages et les
    douze mois, et la donnée Alpine de la carte (`affichage`, `chiffres`,
    `calcul`) et celle de la vue (`mois`) choisissent ce qui se voit. Le menu
    retient l'affichage en arrière-plan (voir GrilleDesGraphiques).

    Ce qui change d'un mois à l'autre (le titre du mois, le grand chiffre, la
    pastille, le détail) s'écrit douze fois ; ce qui n'en change pas (le
    dessin, les légendes, la méthode, le tableau « Voir les chiffres ») une
    seule fois ; et les lignes du calcul achat par achat de chaque mois et
    des douze mois, qui comptent des milliers d'achats, ne s'écrivent pas
    en HTML : elles sont dans la page en JSON compact (le `script` de la
    vue) et le tableau montré les pose quand le calcul est ouvert (voir
    piliqiPoserLesLignes() dans graphiques-du-panneau.blade.php). La vue
    change de clé avec la période, le magasin, le premier mois de la période
    et ce que le calcul contient : l'un de ces choix rend le mois de départ,
    et rien d'autre ne le fait revenir, pas même le menu « Afficher : ». Les
    classes de la maquette s'écrivent sous le préfixe de la feuille des
    graphiques (voir public/css/app/graphiques.css).

    Variables : $ecart (le mois de départ `depart`, la lecture du départ de
    chaque affichage `parMois` et `surDouzeMois`, les douze lectures `mois`
    du mois, le `svg` du dessin), $note (la phrase grise des magasins non
    comptés), $carte (la carte, dont l'affichage retenu).
--}}
@php
    $classes = fn (string $classe): string => implode(' ', array_map(fn (string $nom): string => 'piliqi-graphiques__ecart-'.$nom, array_values(array_filter(explode(' ', $classe)))));
    // Une ligne : [classe, cellules] ; une cellule : son texte seul, ou [texte, classe, étendue] quand elle a l'un des deux.
    $cellule = fn (array $cellule): array|string => $cellule['c'] === '' && $cellule['colspan'] === 1 ? $cellule['t'] : ($cellule['colspan'] === 1 ? [$cellule['t'], $classes($cellule['c'])] : [$cellule['t'], $classes($cellule['c']), $cellule['colspan']]);
    $lignesEnJson = fn (array $lignes): array => array_map(
        fn (array $ligne): array => [$classes($ligne['classe']), array_map($cellule, $ligne['cellules'])],
        $lignes,
    );
    $calculs = json_encode(
        ['avant' => array_map(fn (array $unMois): array => $lignesEnJson($unMois['calcul']['lignes']), $ecart['mois']), 'debut' => [$lignesEnJson($ecart['surDouzeMois']['calcul']['lignes'])]],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
    $depart = $ecart['depart'];
    $parMois = $ecart['parMois'];
    $surDouzeMois = $ecart['surDouzeMois'];
    $idDesChiffres = 'ecart-des-prix-d-achat-chiffres';
    $idDuCalculParMois = 'ecart-des-prix-d-achat-calcul';
    $idDuCalculSurDouzeMois = 'ecart-des-prix-d-achat-calcul-sur-douze-mois';
    $choisi = $carte->affichage;
@endphp

<label class="piliqi-graphiques__ecart-menu">
    <span data-ecart="etiquette">{{ $parMois['etiquette'] }}</span>
    <x-filament::input.wrapper>
        <x-filament::input.select x-on:change="appliquer((valeur) => affichage = valeur, (valeur) => $wire.changerLAffichage('{{ $graphique->value }}', valeur), $event.target.value)" data-geste="affichage" data-ecart="menu">
            @foreach ($parMois['options'] as $option)
                <option value="{{ $option['value'] }}" @selected($choisi === $option['value']) x-bind:selected="affichage === '{{ $option['value'] }}'">{{ $option['texte'] }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</label>

<div class="piliqi-graphiques__ecart-vues" wire:key="ecart-vues-{{ implode('|', [$ecart['cle'], md5($calculs)]) }}" x-data="{ mois: {{ $depart }} }" x-init="$watch('affichage', (valeur) => { if (valeur === 'debut') { chiffres = false } })" data-ecart-vues>
    <script type="application/json" data-ecart="calculs">{!! $calculs !!}</script>

    {{-- Un total par mois. --}}
    <div class="piliqi-graphiques__ecart-vues" data-ecart-affichage="avant" x-show="affichage === 'avant'" @if ($choisi !== 'avant') x-cloak @endif>
        @foreach ($ecart['mois'] as $rang => $unMois)
            <div data-ecart-mois="{{ $rang }}" x-show="mois === {{ $rang }}" @if ($rang !== $depart) x-cloak @endif>
                <div class="piliqi-graphiques__ecart-mois" data-ecart="mois-choisi">{{ $unMois['moisChoisi'] }}</div>
                <div class="piliqi-graphiques__ecart-chiffre">
                    <span class="{{ $classes($unMois['grand']['classe']) }}" data-ecart="grand">{{ $unMois['grand']['texte'] }}</span>
                    <span class="{{ $classes($unMois['pastille']['classe']) }}" data-ecart="pastille">{{ $unMois['pastille']['texte'] }}</span>
                </div>
                <div class="piliqi-graphiques__gris" data-ecart="detail">{{ $unMois['detail'] }}</div>
            </div>
        @endforeach

        <div class="piliqi-graphiques__ecart-bloc" data-ecart="vue-mois">
            {!! $ecart['svg'] !!}
            <div class="piliqi-graphiques__ecart-legende">
                <span class="piliqi-graphiques__ecart-l-plus" data-ecart="legende-plus">{{ $parMois['legendes']['plus'] }}</span>
                <span class="piliqi-graphiques__ecart-l-moins" data-ecart="legende-moins">{{ $parMois['legendes']['moins'] }}</span>
                <span class="piliqi-graphiques__ecart-l-net" data-ecart="legende-net">{{ $parMois['legendes']['net'] }}</span>
            </div>
        </div>

        <p class="piliqi-graphiques__gris" data-ecart="methode">{{ $parMois['methode'] }}</p>

        <div class="piliqi-graphiques__ecart-liens">
            <x-filament::link tag="button" type="button" x-on:click="chiffres = ! chiffres" x-bind:aria-expanded="chiffres" aria-expanded="false" aria-controls="{{ $idDesChiffres }}" wire:target="geste-de-la-page" data-ecart="voir-chiffres"><span x-text="chiffres ? @js($parMois['chiffres']['lien']['texteOuvert']) : @js($parMois['chiffres']['lien']['texte'])">{{ $parMois['chiffres']['lien']['texte'] }}</span></x-filament::link>

            <x-filament::link tag="button" type="button" x-on:click="calcul = ! calcul" x-bind:aria-expanded="calcul" aria-expanded="false" aria-controls="{{ $idDuCalculParMois }}" wire:target="geste-de-la-page" data-ecart="voir-calcul"><span x-text="calcul ? @js($parMois['calcul']['lien']['texteOuvert']) : @js($parMois['calcul']['lien']['texte'])">{{ $parMois['calcul']['lien']['texte'] }}</span></x-filament::link>
        </div>

        <div class="piliqi-graphiques__ecart-bloc" id="{{ $idDesChiffres }}" x-show="chiffres" x-cloak data-ecart="chiffres">
            <div class="{{ $classes('defiler') }}">
                <table>
                    <thead>
                        <tr data-ecart="chiffres-tete">
                            @foreach ($parMois['chiffres']['tete'] as $titre)
                                <th scope="col">{{ $titre }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody data-ecart="chiffres-corps">
                        @foreach ($parMois['chiffres']['lignes'] as $ligne)
                            <tr @if ($ligne['classe'] !== '') class="{{ $classes($ligne['classe']) }}" @endif>
                                @foreach ($ligne['cellules'] as $cellule)
                                    <td @if ($cellule['c'] !== '') class="{{ $classes($cellule['c']) }}" @endif @if ($cellule['colspan'] !== 1) colspan="{{ $cellule['colspan'] }}" @endif>{{ $cellule['t'] }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="piliqi-graphiques__gris" data-ecart="chiffres-note">{{ $parMois['chiffres']['note'] }}</p>
        </div>

        <div class="piliqi-graphiques__ecart-bloc piliqi-graphiques__ecart-calcul" id="{{ $idDuCalculParMois }}" x-show="calcul" x-cloak data-ecart="calcul">
            @foreach ($ecart['mois'] as $rang => $unMois)
                <div class="piliqi-graphiques__ecart-bloc" data-ecart-mois="{{ $rang }}" x-show="mois === {{ $rang }}" @if ($rang !== $depart) x-cloak @endif>
                    <h4 class="piliqi-graphiques__ecart-titre-du-calcul" data-ecart="calcul-titre">{{ $unMois['calcul']['titre'] }}</h4>
                    <div class="{{ $classes('defiler') }}">
                        <table>
                            <thead>
                                <tr data-ecart="calcul-tete">
                                    @foreach ($parMois['calcul']['tete'] as $titre)
                                        <th scope="col">{{ $titre }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody data-ecart="calcul-corps" x-effect="piliqiPoserLesLignes($el, calcul && affichage === 'avant' && mois === {{ $rang }}, 'avant', {{ $rang }})"></tbody>
                        </table>
                    </div>
                    <div class="piliqi-graphiques__ecart-notes" data-ecart="calcul-notes">
                        @foreach ($unMois['calcul']['notes'] as $texte)
                            <p class="piliqi-graphiques__gris">{{ $texte }}</p>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Un seul total pour les douze mois. --}}
    <div class="piliqi-graphiques__ecart-vues" data-ecart-affichage="debut" x-show="affichage === 'debut'" @if ($choisi !== 'debut') x-cloak @endif>
        <div class="piliqi-graphiques__ecart-mois" data-ecart="mois-choisi">{{ $surDouzeMois['moisChoisi'] }}</div>
        <div class="piliqi-graphiques__ecart-chiffre">
            <span class="{{ $classes($surDouzeMois['grand']['classe']) }}" data-ecart="grand">{{ $surDouzeMois['grand']['texte'] }}</span>
            <span class="{{ $classes($surDouzeMois['pastille']['classe']) }}" data-ecart="pastille">{{ $surDouzeMois['pastille']['texte'] }}</span>
        </div>
        <div class="piliqi-graphiques__gris" data-ecart="detail">{{ $surDouzeMois['detail'] }}</div>

        <div class="piliqi-graphiques__ecart-bloc" data-ecart="vue-periode">
            <div class="piliqi-graphiques__ecart-duo">
                <div class="piliqi-graphiques__ecart-stat">
                    <span class="piliqi-graphiques__ecart-stat-nom">{{ $surDouzeMois['stats']['nomBase'] }}</span>
                    <span class="piliqi-graphiques__ecart-stat-val" data-ecart="stat-base">{{ $surDouzeMois['stats']['base'] }}</span>
                </div>
                <div class="piliqi-graphiques__ecart-stat">
                    <span class="piliqi-graphiques__ecart-stat-nom">{{ $surDouzeMois['stats']['nomCout'] }}</span>
                    <span class="piliqi-graphiques__ecart-stat-val" data-ecart="stat-cout">{{ $surDouzeMois['stats']['cout'] }}</span>
                </div>
            </div>
        </div>

        <p class="piliqi-graphiques__gris" data-ecart="methode">{{ $surDouzeMois['methode'] }}</p>

        <div class="piliqi-graphiques__ecart-liens">
            <x-filament::link tag="button" type="button" hidden aria-expanded="false" aria-controls="{{ $idDesChiffres }}" data-ecart="voir-chiffres">{{ $surDouzeMois['chiffres']['lien']['texte'] }}</x-filament::link>

            <x-filament::link tag="button" type="button" x-on:click="calcul = ! calcul" x-bind:aria-expanded="calcul" aria-expanded="false" aria-controls="{{ $idDuCalculSurDouzeMois }}" wire:target="geste-de-la-page" data-ecart="voir-calcul"><span x-text="calcul ? @js($surDouzeMois['calcul']['lien']['texteOuvert']) : @js($surDouzeMois['calcul']['lien']['texte'])">{{ $surDouzeMois['calcul']['lien']['texte'] }}</span></x-filament::link>
        </div>

        <div class="piliqi-graphiques__ecart-bloc piliqi-graphiques__ecart-calcul" id="{{ $idDuCalculSurDouzeMois }}" x-show="calcul" x-cloak data-ecart="calcul">
            <div class="piliqi-graphiques__ecart-bloc">
                <h4 class="piliqi-graphiques__ecart-titre-du-calcul" data-ecart="calcul-titre">{{ $surDouzeMois['calcul']['titre'] }}</h4>
                <div class="{{ $classes($surDouzeMois['calcul']['classeDefiler']) }}">
                    <table>
                        <thead>
                            <tr data-ecart="calcul-tete">
                                @foreach ($surDouzeMois['calcul']['tete'] as $titre)
                                    <th scope="col">{{ $titre }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody data-ecart="calcul-corps" x-effect="piliqiPoserLesLignes($el, calcul && affichage === 'debut', 'debut', 0)"></tbody>
                    </table>
                </div>
                <div class="piliqi-graphiques__ecart-notes" data-ecart="calcul-notes">
                    @foreach ($surDouzeMois['calcul']['notes'] as $texte)
                        <p class="piliqi-graphiques__gris">{{ $texte }}</p>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    @if ($note !== null)
        <p class="piliqi-graphiques__gris" data-ecart="note" data-note>{{ $note }}</p>
    @endif
</div>
