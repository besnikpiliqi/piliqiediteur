{{-- Voir AccepterLaTransmission. --}}
<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="accepter">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="true"
        />
    </x-filament-panels::form>
</x-filament-panels::page.simple>
