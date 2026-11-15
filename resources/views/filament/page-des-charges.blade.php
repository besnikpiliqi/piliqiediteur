{{--
    La page « Charges » (voir App\Filament\PageDesCharges, modèle C1) : les
    filtres, le tableau « type de charge × mois », puis la liste. Chaque
    case porte le symbole et la couleur de l'état le moins avancé de ses
    charges, et leur nombre quand il y en a plusieurs ; un clic sur une case
    filtre la liste dessous.
--}}
@php
    use App\Domain\Charges\Enums\EtatDeLaCharge;
    use App\Filament\PageDesCharges;

    $couleurs = [
        'success' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400',
        'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400',
        'gray' => 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300',
        'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400',
    ];
@endphp

<div class="space-y-6" data-page-des-charges>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="block font-medium text-gray-700 dark:text-gray-200">Période</span>
            <select wire:model.live="periode" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" data-filtre-periode>
                @foreach ($this->periodes() as $valeur => $libelle)
                    <option value="{{ $valeur }}">{{ $libelle }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm">
            <span class="block font-medium text-gray-700 dark:text-gray-200">Vue</span>
            <select wire:model.live="vue" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" data-filtre-vue>
                <option value="toutes">Toutes les charges</option>
                <option value="a_valider">À valider</option>
                <option value="renvoyees">Renvoyées au client</option>
            </select>
        </label>
        @if (count($this->magasinsDuFiltre()) > 1)
            <label class="text-sm">
                <span class="block font-medium text-gray-700 dark:text-gray-200">Magasin</span>
                <select wire:model.live="magasinFiltre" class="mt-1 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" data-filtre-magasin>
                    @foreach ($this->magasinsDuFiltre() as $valeur => $libelle)
                        <option value="{{ $valeur }}">{{ $libelle }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    <x-filament::section heading="Ce qui est déjà saisi" description="Une case par charge du mois : verte quand elle est validée, orange quand elle attend sa validation, grise quand la pièce est déposée sans ses chiffres, rouge quand elle est renvoyée au client. Cliquez sur une case pour voir ses charges dans la liste.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-tableau-des-mois>
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-3 font-medium">Type</th>
                        @foreach ($mois as $unMois)
                            <th class="px-1 py-1 text-center font-medium">{{ PageDesCharges::abregeDuMois($unMois) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($tableau as $cle => $ligne)
                        <tr class="border-t border-gray-100 dark:border-white/5" data-type-de-charge="{{ $cle }}">
                            <td class="py-1 pe-3 font-medium text-gray-700 dark:text-gray-200">{{ $ligne['libelle'] }}</td>
                            @foreach ($mois as $unMois)
                                @php($case = $ligne['mois'][$unMois] ?? null)
                                <td class="px-1 py-1 text-center">
                                    @if ($case === null)
                                        <span class="text-gray-300 dark:text-gray-600" title="Rien de saisi">·</span>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="filtrerLaCase(@js($cle), @js($unMois))"
                                            class="inline-flex min-w-8 items-center justify-center gap-0.5 rounded-md px-1.5 py-0.5 text-xs font-semibold {{ $couleurs[$case['etat']->getColor()] }} {{ $this->caseType === $cle && $this->caseMois === $unMois ? 'ring-2 ring-primary-500' : '' }}"
                                            title="{{ $case['etat']->getLabel() }}{{ $case['nombre'] > 1 ? ' ('.$case['nombre'].' charges)' : '' }}"
                                            data-case="{{ $case['etat']->value }}"
                                            data-mois="{{ $unMois }}"
                                        >{{ $case['etat']->symbole() }}@if ($case['nombre'] > 1)<span>{{ $case['nombre'] }}</span>@endif</button>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="13" class="py-2 text-gray-500 dark:text-gray-400">Aucune charge sur cette période.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3 flex flex-wrap gap-3 text-xs text-gray-500 dark:text-gray-400">
            @foreach (EtatDeLaCharge::cases() as $etat)
                <span class="inline-flex items-center gap-1"><span class="rounded px-1 font-semibold {{ $couleurs[$etat->getColor()] }}">{{ $etat->symbole() }}</span>{{ $etat->getLabel() }}</span>
            @endforeach
            <span class="inline-flex items-center gap-1"><span class="px-1">·</span>Rien de saisi</span>
        </div>
        @if ($this->caseType !== null)
            <p class="mt-3 text-sm">
                Liste filtrée sur une case, {{ PageDesCharges::nomDuMois((string) $this->caseMois) }}.
                <button type="button" wire:click="oublierLaCase" class="font-medium text-primary-600 underline dark:text-primary-400">Voir toute la période</button>
            </p>
        @endif
    </x-filament::section>

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
