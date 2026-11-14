{{--
    Les cartes des notifications (voir App\Filament\CartesDesNotifications) :
    la pile en haut à droite, sous la cloche, que le navigateur tient seul
    (wire:ignore) pour qu'une carte encore montrée ne disparaisse pas au
    passage suivant ; les cartes du chargement de la page s'y rendent avec
    elle, celles de chaque intervalle y arrivent par l'événement du passage.
    Le rôle « status » annonce chaque carte aux lecteurs d'écran.
--}}
<div wire:poll.{{ \App\Filament\CartesDesNotifications::intervalle() }}="regarder" class="piliqi-cartes-des-notifications">
    <div
        wire:ignore
        x-data="pileDesCartes"
        x-on:piliqi-cartes-des-notifications.window="ajouter($event.detail.cartes)"
        class="piliqi-cartes"
        role="status"
    >
        @foreach ($cartes as $carte)
            {!! $carte !!}
        @endforeach
    </div>
</div>
