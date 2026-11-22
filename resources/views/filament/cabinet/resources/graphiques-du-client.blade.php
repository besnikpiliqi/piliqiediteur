{{-- Voir GraphiquesDuClient, GrilleDesGraphiques et MessagesDuCreditDeTva. --}}
<x-filament-panels::page>
    @if (($messageDeLaTva = $this->messageDeLaTva()) !== null)
        @include('filament.tva.message-du-credit', ['message' => $messageDeLaTva])
    @endif

    @if ($this->voitLesGraphiques())
        @livewire(\App\Filament\GrilleDesGraphiques::NOM, ['ecran' => \App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord::Cabinet->value, 'lien' => (string) $this->lien()->uuid, 'lazy' => 'on-load'], key('graphiques-du-client-'.$this->lien()->uuid))
    @else
        <x-filament::section icon="heroicon-o-chart-bar">
            <p data-graphiques-sans-magasin>{{ \App\Filament\Cabinet\Resources\ClientDuCabinetResource\Pages\GraphiquesDuClient::SANS_MAGASIN }}</p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
