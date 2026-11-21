{{-- Voir BienvenueApercu. --}}
<x-filament-widgets::widget>
    @include('filament.bienvenue', ['compte' => $compte, 'role' => $role])
</x-filament-widgets::widget>
