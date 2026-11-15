{{-- Voir TableauDeBordDeLaSociete et GrilleDesGraphiques. --}}
<x-filament-panels::page>
    @livewire(\App\Filament\GrilleDesGraphiques::NOM, ['ecran' => \App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord::ExpertComptable->value, 'lazy' => 'on-load'], key('graphiques-de-l-expert-comptable'))
</x-filament-panels::page>
