{{--
    La cloche des trois espaces : le bouton de Filament
    (filament-panels::components.topbar.database-notifications-trigger), le
    même, qui reste actif pendant que la cloche regarde s'il y a du nouveau
    (voir App\Filament\CartesDesNotifications::intervalle()). Le bouton de
    Filament se désactive pendant chaque requête de son composant
    (wire:loading.attr="disabled"), et un clic tombé pendant ce regard se
    perdait sans ouvrir la liste ; l'ouvrir ne fait aucune requête.
--}}
<x-filament::icon-button
    :badge="$unreadNotificationsCount ?: null"
    color="gray"
    icon="heroicon-o-bell"
    icon-alias="panels::topbar.open-database-notifications-button"
    icon-size="lg"
    :label="__('filament-panels::layout.actions.open_database_notifications.label')"
    :wire:loading.attr="false"
    class="fi-topbar-database-notifications-btn"
/>
