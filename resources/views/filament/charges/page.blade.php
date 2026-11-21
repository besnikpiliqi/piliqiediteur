{{-- Voir App\Filament\PageDesCharges : le même composant dans les quatre écrans des charges. --}}
<x-filament-panels::page>
    @livewire(\App\Filament\PageDesCharges::NOM, ['ecran' => $ecran, 'lien' => $lien], key('charges-'.$ecran.'-'.($lien ?? '')))
</x-filament-panels::page>
