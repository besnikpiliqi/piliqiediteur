{{-- Ouvert dans la fenêtre d'une action du formulaire de la page : Entrée dans un champ (recherche, quantité) validerait ce formulaire et fermerait la fenêtre. --}}
<div class="space-y-4" x-data x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()">
    @php($choisis = $this->produitsSelectionnes())
    @php($nombre = count($choisis))

    <div class="flex items-center gap-3">
        <label for="tri-des-produits" class="text-sm font-medium text-gray-950 dark:text-white">Trier</label>

        <x-filament::input.wrapper>
            <x-filament::input.select id="tri-des-produits" wire:model.live="tri">
                <option value="{{ \App\Filament\App\Livewire\SelecteurDeProduits::TRI_PAR_NOM }}">Par nom</option>
                <option value="{{ \App\Filament\App\Livewire\SelecteurDeProduits::TRI_PLUS_RECENTS }}">Plus récents d'abord</option>
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    {{ $this->table }}

    @if ($this->resteDAutresProduits())
        <div class="flex justify-center">
            <x-filament::button color="gray" wire:click="voirPlus">
                Voir {{ \App\Filament\App\Livewire\SelecteurDeProduits::PAR_PAGE }} de plus
            </x-filament::button>
        </div>
    @endif

    <x-filament::section>
        @if ($nombre > 0)
            <ul class="space-y-2">
                @foreach ($choisis as $choisi)
                    <li wire:key="choisi-{{ $choisi['produit']->getKey() }}" class="flex items-center gap-3">
                        <span class="flex-1 text-sm">{{ $choisi['produit']->nom }} ({{ $choisi['produit']->reference }})</span>

                        <x-filament::input.wrapper class="w-32">
                            <x-filament::input
                                type="number"
                                min="1"
                                step="1"
                                aria-label="Quantité de {{ $choisi['produit']->nom }}"
                                value="{{ $choisi['quantite'] }}"
                                wire:change="changerLaQuantite({{ $choisi['produit']->getKey() }}, $event.target.value)"
                            />
                        </x-filament::input.wrapper>

                        <x-filament::icon-button
                            icon="heroicon-m-x-mark"
                            color="gray"
                            label="Retirer {{ $choisi['produit']->nom }}"
                            wire:click="retirer({{ $choisi['produit']->getKey() }})"
                        />
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm font-medium">{{ $this->resumeDeLaSelection($choisis) }}</p>

            <x-filament::button wire:click="ajouterLaSelection" :disabled="$nombre === 0">
                {{ $nombre <= 1 ? 'Ajouter le produit' : 'Ajouter les '.$nombre.' produits' }}
            </x-filament::button>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</div>
