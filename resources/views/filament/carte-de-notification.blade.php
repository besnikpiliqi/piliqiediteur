{{--
    Une carte de notification (voir App\Filament\CartesDesNotifications) :
    l'icône, le titre, le texte et les boutons de la notification de la
    cloche, nettoyés comme la cloche les nettoie, une croix et la barre du
    temps qui reste. La carte porte son propre identifiant ($carte) ; seul
    un bouton qui marque la notification comme lue (markAsRead()) touche à
    la notification ($notification), par la cloche.
--}}
@php
    $titre = $contenu->getTitle();
    $texte = $contenu->getBody();
    $icone = $contenu->getIcon();
    $actions = $contenu->getActions();
@endphp

<div
    x-data="carteDeNotification({ notification: @js($notification), duree: @js($duree) })"
    x-on:mousemove="survoler()"
    x-on:mouseleave="quitter()"
    x-on:focusin="prendreLeFocus()"
    x-on:focusout="reprendre()"
    x-on:click="suivre($event)"
    data-carte="{{ $carte }}"
    style="--piliqi-carte-duree: {{ $duree }}ms"
    class="piliqi-carte"
>
    <div class="piliqi-carte__corps">
        @if (filled($icone))
            <x-filament-notifications::icon
                :color="$contenu->getIconColor()"
                :icon="$icone"
                :size="$contenu->getIconSize()"
            />
        @endif

        <div class="piliqi-carte__texte">
            @if (filled($titre))
                <x-filament-notifications::title>
                    {{ str($titre)->sanitizeHtml()->toHtmlString() }}
                </x-filament-notifications::title>
            @endif

            @if (filled($texte))
                <x-filament-notifications::body class="mt-1">
                    {{ str($texte)->sanitizeHtml()->toHtmlString() }}
                </x-filament-notifications::body>
            @endif

            @if ($actions !== [])
                <x-filament-notifications::actions :actions="$actions" class="mt-3" />
            @endif
        </div>

        {{--
            La croix ne fait aucune requête : elle reste active pendant que
            la page regarde s'il y a du nouveau (:wire:loading.attr="false"
            retire la désactivation que le bouton de Filament pose pendant
            chaque requête de son composant, qui perdait un clic tombé à ce
            moment et enlevait le focus du clavier à la croix).
        --}}
        <x-filament::icon-button
            color="gray"
            icon="heroicon-m-x-mark"
            label="Fermer"
            x-on:click="fermer()"
            :wire:loading.attr="false"
            class="piliqi-carte__fermer"
        />
    </div>

    <div class="piliqi-carte__temps" aria-hidden="true"><i x-ref="temps"></i></div>
</div>
