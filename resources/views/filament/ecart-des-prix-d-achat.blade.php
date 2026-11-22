{{--
    Le contenu de la carte « Écart des prix d'achat » (décision de l'éditeur
    du 21/11/2026, D76, B6 et B7) : la structure de la maquette que l'éditeur
    a validée, dans la carte commune des graphiques (voir
    grille-des-graphiques.blade.php, qui garde l'en-tête : type, « Monter »,
    « Descendre », croix). Les textes, les classes et les nombres viennent de
    TextesDeLEcartDesPrixDAchat::lecture() et le `svg` de
    SvgDeLEcartDesPrixDAchat, tels quels ; la page n'écrit que l'affichage
    choisi (le dessin et la légende par mois, ou les deux cartes de chiffres
    des douze mois) et le calcul du mois choisi ou de la période. Chaque
    élément qu'un test lit porte un `data-ecart`. « Voir les chiffres » et
    « Voir le calcul… » n'appellent pas le serveur : ils ouvrent ce que la
    page contient déjà, comme « Voir les chiffres » des autres cartes. Les
    classes de la maquette s'écrivent sous le préfixe de la feuille des
    graphiques (voir public/css/app/graphiques.css).

    Variables : $lecture (la lecture), $svg (le dessin par mois, null pour les
    douze mois), $note (la phrase grise des magasins non comptés).
--}}
@php
    $classes = fn (string $classe): string => implode(' ', array_map(fn (string $nom): string => 'piliqi-graphiques__ecart-'.$nom, array_values(array_filter(explode(' ', $classe)))));
    $surDouzeMois = $lecture['vues']['moisCachee'];
    $idDesChiffres = 'ecart-des-prix-d-achat-chiffres';
    $idDuCalcul = 'ecart-des-prix-d-achat-calcul';
@endphp

<label class="piliqi-graphiques__ecart-menu">
    <span data-ecart="etiquette">{{ $lecture['etiquette'] }}</span>
    <x-filament::input.wrapper>
        <x-filament::input.select wire:change="changerLAffichage('{{ $graphique->value }}', $event.target.value)" data-geste="affichage" data-ecart="menu">
            @foreach ($lecture['options'] as $option)
                <option value="{{ $option['value'] }}" @selected($lecture['valeurMenu'] === $option['value'])>{{ $option['texte'] }}</option>
            @endforeach
        </x-filament::input.select>
    </x-filament::input.wrapper>
</label>

<div>
    <div class="piliqi-graphiques__ecart-mois" data-ecart="mois-choisi">{{ $lecture['moisChoisi'] }}</div>
    <div class="piliqi-graphiques__ecart-chiffre">
        <span class="{{ $classes($lecture['grand']['classe']) }}" data-ecart="grand">{{ $lecture['grand']['texte'] }}</span>
        <span class="{{ $classes($lecture['pastille']['classe']) }}" data-ecart="pastille">{{ $lecture['pastille']['texte'] }}</span>
    </div>
    <div class="piliqi-graphiques__gris" data-ecart="detail">{{ $lecture['detail'] }}</div>
</div>

@if ($surDouzeMois)
    <div class="piliqi-graphiques__ecart-bloc" data-ecart="vue-periode">
        <div class="piliqi-graphiques__ecart-duo">
            <div class="piliqi-graphiques__ecart-stat">
                <span class="piliqi-graphiques__ecart-stat-nom">{{ $lecture['stats']['nomBase'] }}</span>
                <span class="piliqi-graphiques__ecart-stat-val" data-ecart="stat-base">{{ $lecture['stats']['base'] }}</span>
            </div>
            <div class="piliqi-graphiques__ecart-stat">
                <span class="piliqi-graphiques__ecart-stat-nom">{{ $lecture['stats']['nomCout'] }}</span>
                <span class="piliqi-graphiques__ecart-stat-val" data-ecart="stat-cout">{{ $lecture['stats']['cout'] }}</span>
            </div>
        </div>
    </div>
@else
    <div class="piliqi-graphiques__ecart-bloc" data-ecart="vue-mois">
        {!! $svg !!}
        <div class="piliqi-graphiques__ecart-legende">
            <span class="piliqi-graphiques__ecart-l-plus" data-ecart="legende-plus">{{ $lecture['legendes']['plus'] }}</span>
            <span class="piliqi-graphiques__ecart-l-moins" data-ecart="legende-moins">{{ $lecture['legendes']['moins'] }}</span>
            <span class="piliqi-graphiques__ecart-l-net" data-ecart="legende-net">{{ $lecture['legendes']['net'] }}</span>
        </div>
    </div>
@endif

<p class="piliqi-graphiques__gris" data-ecart="methode">{{ $lecture['methode'] }}</p>

@if ($note !== null)
    <p class="piliqi-graphiques__gris" data-ecart="note" data-note>{{ $note }}</p>
@endif

<div class="piliqi-graphiques__ecart-liens">
    @if ($lecture['chiffres']['lien']['cache'])
        {{-- Les douze mois n'ont pas ce tableau : le lien est caché et ce qu'il avait ouvert se referme (l'élément change, Alpine en lit le x-init). --}}
        <x-filament::link tag="button" type="button" hidden wire:key="ecart-voir-chiffres-cache" x-init="chiffres = false" aria-expanded="false" aria-controls="{{ $idDesChiffres }}" data-ecart="voir-chiffres">{{ $lecture['chiffres']['lien']['texte'] }}</x-filament::link>
    @else
        <x-filament::link tag="button" type="button" wire:key="ecart-voir-chiffres" x-on:click="chiffres = ! chiffres" x-bind:aria-expanded="chiffres" aria-expanded="false" aria-controls="{{ $idDesChiffres }}" data-ecart="voir-chiffres"><span x-text="chiffres ? @js($lecture['chiffres']['lien']['texteOuvert']) : @js($lecture['chiffres']['lien']['texte'])">{{ $lecture['chiffres']['lien']['texte'] }}</span></x-filament::link>
    @endif

    <x-filament::link tag="button" type="button" x-on:click="calcul = ! calcul" x-bind:aria-expanded="calcul" aria-expanded="false" aria-controls="{{ $idDuCalcul }}" data-ecart="voir-calcul"><span x-text="calcul ? @js($lecture['calcul']['lien']['texteOuvert']) : @js($lecture['calcul']['lien']['texte'])">{{ $lecture['calcul']['lien']['texte'] }}</span></x-filament::link>
</div>

@unless ($surDouzeMois)
    <div class="piliqi-graphiques__ecart-bloc" id="{{ $idDesChiffres }}" x-show="chiffres" x-cloak data-ecart="chiffres">
        <div class="{{ $classes('defiler') }}">
            <table>
                <thead>
                    <tr data-ecart="chiffres-tete">
                        @foreach ($lecture['chiffres']['tete'] as $titre)
                            <th scope="col">{{ $titre }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody data-ecart="chiffres-corps">
                    @foreach ($lecture['chiffres']['lignes'] as $ligne)
                        <tr @if ($ligne['classe'] !== '') class="{{ $classes($ligne['classe']) }}" @endif>
                            @foreach ($ligne['cellules'] as $cellule)
                                <td @if ($cellule['c'] !== '') class="{{ $classes($cellule['c']) }}" @endif @if ($cellule['colspan'] !== 1) colspan="{{ $cellule['colspan'] }}" @endif>{{ $cellule['t'] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="piliqi-graphiques__gris" data-ecart="chiffres-note">{{ $lecture['chiffres']['note'] }}</p>
    </div>
@endunless

<div class="piliqi-graphiques__ecart-bloc piliqi-graphiques__ecart-calcul" id="{{ $idDuCalcul }}" x-show="calcul" x-cloak data-ecart="calcul">
    <h4 class="piliqi-graphiques__ecart-titre-du-calcul" data-ecart="calcul-titre">{{ $lecture['calcul']['titre'] }}</h4>
    <div class="{{ $classes($lecture['calcul']['classeDefiler']) }}">
        <table>
            <thead>
                <tr data-ecart="calcul-tete">
                    @foreach ($lecture['calcul']['tete'] as $titre)
                        <th scope="col">{{ $titre }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody data-ecart="calcul-corps">
                @foreach ($lecture['calcul']['lignes'] as $ligne)
                    <tr @if ($ligne['classe'] !== '') class="{{ $classes($ligne['classe']) }}" @endif>
                        @foreach ($ligne['cellules'] as $cellule)
                            <td @if ($cellule['c'] !== '') class="{{ $classes($cellule['c']) }}" @endif @if ($cellule['colspan'] !== 1) colspan="{{ $cellule['colspan'] }}" @endif>{{ $cellule['t'] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="piliqi-graphiques__ecart-notes" data-ecart="calcul-notes">
        @foreach ($lecture['calcul']['notes'] as $texte)
            <p class="piliqi-graphiques__gris">{{ $texte }}</p>
        @endforeach
    </div>
</div>
