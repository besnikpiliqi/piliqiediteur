{{-- Voir GraphiquesDuClient et GrilleDesGraphiques. --}}
<x-filament-panels::page>
    @livewire(\App\Filament\GrilleDesGraphiques::NOM, ['ecran' => \App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord::Cabinet->value, 'lien' => (string) $this->lien()->uuid], key('graphiques-du-client-'.$this->lien()->uuid))
</x-filament-panels::page>
