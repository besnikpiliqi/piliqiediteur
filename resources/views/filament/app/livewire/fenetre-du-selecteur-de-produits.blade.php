{{-- Une nouvelle instance pour un autre client ou une autre nature d'opération, dont dépend le total de la sélection. --}}
@livewire(\App\Filament\App\Livewire\SelecteurDeProduits::class, ['clientId' => $clientId, 'nature' => $nature], key('selecteur-de-produits-'.md5(serialize([$clientId, $nature]))))
