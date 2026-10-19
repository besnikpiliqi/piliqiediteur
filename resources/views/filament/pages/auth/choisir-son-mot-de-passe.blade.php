{{-- Voir ChoisirSonMotDePasse. --}}
<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="choisir">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="true"
        />
    </x-filament-panels::form>

    <form method="post" action="{{ filament()->getLogoutUrl() }}" class="text-center">
        @csrf
        <x-filament::link tag="button" type="submit" color="gray">Me déconnecter</x-filament::link>
    </form>
</x-filament-panels::page.simple>
