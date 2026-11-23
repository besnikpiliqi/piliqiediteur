{{-- Voir TableauDeBordDeLaSociete et GrilleDesGraphiques. --}}
<x-filament-panels::page>
    @if (($chargesAValider = $this->chargesAValider()) > 0)
        <x-filament::section icon="heroicon-o-receipt-percent" data-charges-a-valider="{{ $chargesAValider }}">
            <p>{{ trans_choice('{1} 1 charge à valider|[2,*] :count charges à valider', $chargesAValider) }}.
                <x-filament::link :href="\App\Filament\App\Pages\ChargesDeLaSociete::getUrl(['vue' => 'a_valider'])">Valider</x-filament::link></p>
        </x-filament::section>
    @endif
    @livewire(\App\Filament\GrilleDesGraphiques::NOM, ['ecran' => \App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord::ExpertComptable->value, 'lazy' => 'on-load'], key('graphiques-de-l-expert-comptable'))
</x-filament-panels::page>
