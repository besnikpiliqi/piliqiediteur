<x-filament-panels::page>
    @php($journeeCloturee = $this->journeeCloturee())

    @if ($journeeCloturee !== null)
        <x-filament::section icon="heroicon-o-lock-closed" icon-color="danger">
            <x-slot name="heading">{{ $journeeCloturee }}</x-slot>
        </x-filament::section>
    @endif

    <form wire:submit="encaisser">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4" size="lg" :disabled="$journeeCloturee !== null">
            Encaisser
        </x-filament::button>
    </form>
</x-filament-panels::page>
