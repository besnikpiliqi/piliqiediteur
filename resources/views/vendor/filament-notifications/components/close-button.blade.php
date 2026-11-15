{{--
    La croix d'une notification de Filament
    (filament-notifications::components.close-button), la même, qui reste
    active pendant les requêtes de son composant : dans la liste de la
    cloche, qui regarde s'il y a du nouveau (voir
    App\Filament\CartesDesNotifications::intervalle()), le bouton de
    Filament se désactivait pendant chaque regard (wire:loading.attr), et un
    clic tombé à ce moment se perdait.
--}}
<x-filament::icon-button
    color="gray"
    icon="heroicon-m-x-mark"
    icon-alias="notifications::notification.close-button"
    x-on:click="close"
    :wire:loading.attr="false"
    class="fi-no-notification-close-btn"
/>
