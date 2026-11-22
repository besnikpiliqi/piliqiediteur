{{-- Voir TableauDeBordDeLaSociete, GrilleDesGraphiques et MessagesDuCreditDeTva. --}}
<x-filament-panels::page>
    @if (($chargesAValider = $this->chargesAValider()) > 0)
        <x-filament::section icon="heroicon-o-receipt-percent" data-charges-a-valider="{{ $chargesAValider }}">
            <p>{{ trans_choice('{1} 1 charge à valider|[2,*] :count charges à valider', $chargesAValider) }}.
                <x-filament::link :href="\App\Filament\App\Pages\ChargesDeLaSociete::getUrl(['vue' => 'a_valider'])">Valider</x-filament::link></p>
        </x-filament::section>
    @endif
    @if ($this->creditAVerifier())
        <x-filament::section icon="heroicon-o-scale" data-credit-de-tva-a-verifier>
            <p>Crédit de TVA de départ à vérifier.
                <x-filament::link :href="\App\Filament\App\Pages\ChargesDeLaSociete::getUrl().'#credit-de-tva'">Vérifier</x-filament::link></p>
        </x-filament::section>
    @endif
    @if (($messageDeLaTva = $this->messageDeLaTva()) !== null)
        @include('filament.tva.message-du-credit', ['message' => $messageDeLaTva])
    @endif
    @livewire(\App\Filament\GrilleDesGraphiques::NOM, ['ecran' => \App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord::ExpertComptable->value, 'lazy' => 'on-load'], key('graphiques-de-l-expert-comptable'))
</x-filament-panels::page>
