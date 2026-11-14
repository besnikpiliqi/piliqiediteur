{{-- Au bout du tableau des produits de la fenêtre « Ajouter des produits », dans la partie qui défile : les produits suivants. --}}
<td colspan="{{ count($columns) }}" class="px-3 py-3 text-center">
    <x-filament::button color="gray" wire:click="voirPlus">
        Voir {{ \App\Filament\App\Livewire\SelecteurDeProduits::PAR_PAGE }} de plus
    </x-filament::button>
</td>
