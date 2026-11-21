{{-- Voir AucunMagasin. --}}
<x-filament-panels::page.simple>
    <form method="post" action="{{ filament()->getLogoutUrl() }}" class="text-center">
        @csrf
        <x-filament::link tag="button" type="submit" color="gray">Me déconnecter</x-filament::link>
    </form>
</x-filament-panels::page.simple>
