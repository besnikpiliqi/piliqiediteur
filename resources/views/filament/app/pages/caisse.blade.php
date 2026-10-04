<x-filament-panels::page>
    <form wire:submit="encaisser">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4" size="lg">
            Encaisser
        </x-filament::button>
    </form>
</x-filament-panels::page>
