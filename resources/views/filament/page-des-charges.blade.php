{{--
    La page « Charges » (voir App\Filament\PageDesCharges, modèle C1) : les
    filtres, le tableau « type de charge × mois », la TVA du mois choisi
    (collectée, déductible, à payer réelle et estimée), puis la liste. Chaque
    case porte le symbole et la couleur de l'état le moins avancé de ses
    charges, et leur nombre quand il y en a plusieurs ; un clic sur une case
    filtre la liste dessous.
--}}
@php
    use App\Domain\Charges\Enums\EtatDeLaCharge;
    use App\Domain\Charges\Enums\FormatDeLEtat;
    use App\Domain\Charges\Support\TextesDesCharges;
    use App\Filament\PageDesCharges;
@endphp

<div class="space-y-6" data-page-des-charges>
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="block font-medium text-gray-700 dark:text-gray-200">Période</span>
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select wire:model.live="periode" data-filtre-periode>
                    @foreach ($this->periodes() as $valeur => $libelle)
                        <option value="{{ $valeur }}">{{ $libelle }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm">
            <span class="block font-medium text-gray-700 dark:text-gray-200">Vue</span>
            <x-filament::input.wrapper class="mt-1">
                <x-filament::input.select wire:model.live="vue" data-filtre-vue>
                    @foreach (\App\Domain\Charges\Services\ChargesDeLaSociete::VUES as $valeur => $libelle)
                        <option value="{{ $valeur }}">{{ $libelle }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        @if (count($this->magasinsDuFiltre()) > 1)
            <label class="text-sm">
                <span class="block font-medium text-gray-700 dark:text-gray-200">Magasin</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="magasinFiltre" data-filtre-magasin>
                        @foreach ($this->magasinsDuFiltre() as $valeur => $libelle)
                            <option value="{{ $valeur }}">{{ $libelle }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
        <div class="ms-auto" data-telecharger-l-etat>
            <x-filament::dropdown placement="bottom-end">
                <x-slot name="trigger">
                    <x-filament::button color="gray" icon="heroicon-m-arrow-down-tray">Télécharger</x-filament::button>
                </x-slot>
                <x-filament::dropdown.list>
                    @foreach (FormatDeLEtat::cases() as $format)
                        <x-filament::dropdown.list.item wire:click="telechargerLEtat('{{ $format->value }}')" data-format-de-l-etat="{{ $format->value }}">{{ $format->getLabel() }}</x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        </div>
    </div>

    <x-filament::section heading="Ce qui est déjà saisi" description="Une case par charge du mois : verte quand elle est validée, orange quand elle attend sa validation, grise quand la pièce est déposée sans ses chiffres, rouge quand elle est renvoyée au client. Cliquez sur une case pour voir ses charges dans la liste.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm" data-tableau-des-mois>
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-3 font-medium">Type</th>
                        @foreach ($mois as $unMois)
                            <th class="px-1 py-1 text-center font-medium">{{ TextesDesCharges::abregeDuMois($unMois) }}</th>
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
                                        <span class="piliqi-charges__rien" title="Rien de saisi" aria-hidden="true">·</span>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="filtrerLaCase(@js($cle), @js($unMois))"
                                            @class(['piliqi-charges__case', 'piliqi-charges__case--'.$case['etat']->value, 'piliqi-charges__case--choisie' => $this->caseType === $cle && $this->caseMois === $unMois])
                                            title="{{ $case['etat']->getLabel() }}{{ $case['nombre'] > 1 ? ' ('.$case['nombre'].' charges)' : '' }}"
                                            aria-label="{{ $ligne['libelle'] }}, {{ TextesDesCharges::nomDuMois($unMois) }} : {{ $case['etat']->getLabel() }}{{ $case['nombre'] > 1 ? ' ('.$case['nombre'].' charges)' : '' }}"
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
        <div class="piliqi-charges__legende" data-legende-des-charges>
            @foreach (EtatDeLaCharge::cases() as $etat)
                <span><span class="piliqi-charges__case piliqi-charges__case--{{ $etat->value }}" aria-hidden="true">{{ $etat->symbole() }}</span>{{ $etat->getLabel() }}</span>
            @endforeach
            <span><span class="piliqi-charges__rien" aria-hidden="true">·</span>Rien de saisi</span>
        </div>
        @if ($this->caseType !== null)
            <p class="mt-3 text-sm">
                Liste filtrée sur une case, {{ TextesDesCharges::nomDuMois((string) $this->caseMois) }}.
                <button type="button" wire:click="oublierLaCase" class="font-medium text-primary-600 underline dark:text-primary-400">Voir toute la période</button>
            </p>
        @endif
    </x-filament::section>

    @if ($tva === null)
        <x-filament::section heading="La TVA du mois" data-tva-du-mois="franchise">
            <p class="text-sm text-gray-600 dark:text-gray-400">TVA non applicable (franchise en base).</p>
        </x-filament::section>
    @else
        @php($montants = $tva['montants'])
        <x-filament::section data-tva-du-mois="{{ $tva['avec_les_ventes'] ? 'complete' : 'charges' }}">
            <x-slot name="heading">La TVA du mois</x-slot>
            <x-slot name="description">{{ $tva['avec_les_ventes'] ? 'Telle que votre tableau de bord la compte : les ventes, les achats et les charges validées, puis l\'estimation avec les charges à valider.' : 'Les seules charges que vous voyez, sans les ventes ni les achats, que vous ne voyez pas.' }}</x-slot>
            <label class="mb-4 inline-block text-sm">
                <span class="block font-medium text-gray-700 dark:text-gray-200">Mois</span>
                <x-filament::input.wrapper class="mt-1">
                    <x-filament::input.select wire:model.live="moisDeLaTva" data-filtre-mois-de-la-tva>
                        @foreach ($mois as $unMois)
                            <option value="{{ $unMois }}">{{ ucfirst(TextesDesCharges::nomDuMois($unMois)) }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <dl class="piliqi-charges__tuiles text-sm">
                @if ($tva['avec_les_ventes'])
                    <div class="piliqi-charges__tuile" data-tva="collectee">
                        <dt class="text-gray-500 dark:text-gray-400">TVA collectée</dt>
                        <dd class="text-lg font-semibold">{{ PageDesCharges::enEuros($montants->collectee()) }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-gray-400">ventes nettes des avoirs{{ $montants->autoliquideeValidee > 0 ? ', dont '.PageDesCharges::enEuros($montants->autoliquideeValidee).' autoliquidés' : '' }}</dd>
                    </div>
                @endif
                <div class="piliqi-charges__tuile" data-tva="deductible">
                    <dt class="text-gray-500 dark:text-gray-400">TVA déductible</dt>
                    <dd class="text-lg font-semibold">{{ PageDesCharges::enEuros($montants->deductible()) }}</dd>
                    <dd class="text-xs text-gray-500 dark:text-gray-400">{{ $tva['avec_les_ventes'] ? 'achats '.PageDesCharges::enEuros($montants->deductibleDesAchats).' + ' : '' }}charges validées {{ PageDesCharges::enEuros($montants->deductibleDesChargesValidees) }}</dd>
                </div>
                @if ($tva['avec_les_ventes'])
                    <div class="piliqi-charges__tuile" data-tva="reelle">
                        <dt class="text-gray-500 dark:text-gray-400">TVA à payer (réelle)</dt>
                        <dd class="text-lg font-semibold">{{ PageDesCharges::enEuros($montants->reelle()) }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-gray-400">avec les seules charges validées</dd>
                    </div>
                    <div class="piliqi-charges__tuile" data-tva="estimation">
                        <dt class="text-gray-500 dark:text-gray-400">TVA à payer (estimation)</dt>
                        <dd class="text-lg font-semibold">{{ PageDesCharges::enEuros($montants->estimee()) }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-gray-400">avec {{ PageDesCharges::enEuros($montants->deductibleDesChargesAValider) }} de TVA de charges à valider</dd>
                    </div>
                @else
                    <div class="piliqi-charges__tuile" data-tva="a-valider">
                        <dt class="text-gray-500 dark:text-gray-400">TVA déductible des charges à valider</dt>
                        <dd class="text-lg font-semibold">{{ PageDesCharges::enEuros($montants->deductibleDesChargesAValider) }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-gray-400">elle ne compte qu'en estimation tant qu'elles ne sont pas validées</dd>
                    </div>
                @endif
            </dl>
            @if ($tva['sans_celles_de_la_societe'])
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ \App\Domain\TableauDeBord\Services\SeriesDesGraphiques::SANS_LES_CHARGES_DE_LA_SOCIETE }}</p>
            @endif
        </x-filament::section>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
