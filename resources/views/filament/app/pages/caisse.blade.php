<x-filament-panels::page>
    @php($journeeCloturee = $this->journeeCloturee())
    @php($derniereVente = $this->derniereVenteEnregistree())

    @if ($journeeCloturee !== null)
        <x-filament::section icon="heroicon-o-lock-closed" icon-color="danger">
            <x-slot name="heading">{{ $journeeCloturee }}</x-slot>
        </x-filament::section>
    @endif

    @if ($derniereVente !== null)
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $derniereVente }}</p>
    @endif

    <form wire:submit="encaisser">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4" size="lg" :disabled="$journeeCloturee !== null">
            Encaisser
        </x-filament::button>
    </form>
</x-filament-panels::page>
