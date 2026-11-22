{{--
    La page « Charges » (voir App\Filament\PageDesCharges, modèle C1) : les
    filtres, le tableau « type de charge × mois », la TVA du mois choisi
    (collectée, déductible, à payer réelle et estimée, puis, au réel
    normal, le report du crédit de TVA et le message de la TVA), le bloc du
    crédit de TVA, puis la liste. Chaque
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
        @if ($sansTva !== null)
            <x-filament::section heading="La TVA du mois" data-tva-du-mois="{{ $this->acces()->societe->regime_tva === null ? 'regime-a-choisir' : 'franchise' }}">
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ $sansTva }}</p>
            </x-filament::section>
        @endif
    @else
        @php($montants = $tva['montants'])
        @php($report = $tva['report'])
        @php($periode = $report?->deLaPeriodeDu($tva['mois']))
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
                        <dt class="text-gray-500 dark:text-gray-400">TVA du mois (réelle)</dt>
                        <dd class="text-lg font-semibold">{{ PageDesCharges::enEuros($montants->reelle()) }}</dd>
                        <dd class="text-xs text-gray-500 dark:text-gray-400">avec les seules charges validées, avant le report du crédit</dd>
                    </div>
                    <div class="piliqi-charges__tuile" data-tva="estimation">
                        <dt class="text-gray-500 dark:text-gray-400">TVA du mois (estimation)</dt>
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

            {{-- Le report du crédit de TVA (lot TVA27) : la TVA de la période du mois choisi (le mois, ou son trimestre au trimestriel), le crédit reporté de la période d'avant, puis la TVA à payer ou le crédit à reporter, la réelle et, quand des charges à valider la changent, l'estimation. --}}
            @if ($periode !== null)
                @php($cadence = $report->cadence)
                @php($declaree = $periode->statut === \App\Domain\Charges\Enums\StatutDuReport::Declaree)
                <div class="mt-3 overflow-x-auto" data-report-du-credit="{{ $periode->statut->value }}">
                    <table class="w-full text-sm">
                        <caption class="mb-2 text-start font-medium text-gray-700 dark:text-gray-200">TVA {{ $cadence === \App\Domain\Tenancy\Enums\CadenceDeLaTva::Mensuelle ? 'du mois' : 'du trimestre' }} après le report du crédit : {{ \Illuminate\Support\Str::ucfirst($cadence->libelle($periode->periode)) }}</caption>
                        <thead>
                            <tr class="text-start text-gray-500 dark:text-gray-400">
                                <th scope="col" class="py-1 pe-3 text-start font-medium"></th>
                                <th scope="col" class="py-1 pe-3 text-end font-medium">TVA collectée</th>
                                <th scope="col" class="py-1 pe-3 text-end font-medium">TVA déductible</th>
                                <th scope="col" class="py-1 pe-3 text-end font-medium">TVA {{ $cadence === \App\Domain\Tenancy\Enums\CadenceDeLaTva::Mensuelle ? 'du mois' : 'du trimestre' }}</th>
                                <th scope="col" class="py-1 pe-3 text-end font-medium">Crédit reporté de la période d'avant</th>
                                <th scope="col" class="py-1 pe-3 text-end font-medium">TVA à payer</th>
                                <th scope="col" class="py-1 text-end font-medium">Crédit de TVA à reporter</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Une page qui écrit déjà @php(…) en ligne n'a pas de bloc @php : Blade les confondrait. --}}
                            @php($m = $periode->montants)
                            @php($lignesDuReport = ['reelle' => ['Réelle', $periode->reelle, $m->collectee(), $m->deductible()]] + ($periode->estimationDifferente() ? ['estimee' => ['Estimation, avec les charges à valider', $periode->estimee, $m->collectee() + $m->autoliquideeAValider, $m->deductible() + $m->deductibleDesChargesAValider]] : []))
                            @foreach ($lignesDuReport as $cle => [$libelle, $solde, $collectee, $deductible])
                                <tr class="border-t border-gray-100 dark:border-white/5" data-ligne-du-report="{{ $cle }}">
                                    <th scope="row" class="py-1 pe-3 text-start font-medium text-gray-700 dark:text-gray-200">{{ $libelle }}</th>
                                    <td class="piliqi-credit__montant py-1 pe-3">{{ PageDesCharges::enEuros($collectee) }}</td>
                                    <td class="piliqi-credit__montant py-1 pe-3">{{ PageDesCharges::enEuros($deductible) }}</td>
                                    <td class="piliqi-credit__montant py-1 pe-3">{{ PageDesCharges::enEuros($solde->tva) }}</td>
                                    <td class="piliqi-credit__montant py-1 pe-3">{{ $declaree ? '—' : PageDesCharges::enEuros($solde->creditReporte) }}</td>
                                    <td class="piliqi-credit__montant py-1 pe-3" data-a-payer>{{ $declaree ? 'déclarée' : PageDesCharges::enEuros($solde->aPayer ?? 0) }}</td>
                                    <td class="piliqi-credit__montant py-1" data-credit-a-reporter>{{ PageDesCharges::enEuros($solde->creditAReporter) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-sm" data-calcul-du-report>{{ \App\Domain\Charges\Support\TextesDuCreditDeTva::calculDeLaPeriode($cadence, $periode->periode, $periode->reelle, $periode->statut) }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ \App\Domain\Charges\Support\TextesDuCreditDeTva::SANS_LES_REMBOURSEMENTS }}</p>
            @elseif ($report !== null)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-report-du-credit="hors-de-la-chaine">Ce mois vient avant l'arrivée de la société dans PILIQI, ou n'est pas encore commencé : il n'a pas de report du crédit de TVA.</p>
            @elseif ($tva['avec_les_ventes'])
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400" data-report-du-credit="sans">{{ \App\Domain\Charges\Support\TextesDuCreditDeTva::SANS_LE_REPORT }}</p>
            @endif

            @if ($tva['sans_celles_de_la_societe'])
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ \App\Domain\TableauDeBord\Services\SeriesDesGraphiques::SANS_LES_CHARGES_DE_LA_SOCIETE }}</p>
            @endif

            @if ($messageDeLaTva !== null)
                <div class="mt-3">
                    @include('filament.tva.message-du-credit', ['message' => $messageDeLaTva])
                </div>
            @endif
        </x-filament::section>
    @endif

    {{-- Le bloc du crédit de TVA (lot TVA27, maquette « Message TVA avant PILIQI ») : les déclarations de la société et leurs gestes ; jamais dans un magasin. --}}
    @if ($credit !== null)
        <div id="credit-de-tva">
            <x-filament::section data-credit-de-tva>
                <x-slot name="heading">{{ $credit['pour_le_comptable'] ? 'Le crédit de TVA de la société' : \App\Domain\Charges\Support\TextesDuCreditDeTva::TITRE_DE_LA_SAISIE }}</x-slot>
                <x-slot name="description">{{ $credit['intro'] }}</x-slot>

                <div class="piliqi-credit__message piliqi-credit__message--info" data-conseil-du-credit>
                    <p class="piliqi-credit__titre">Faites vérifier ce montant par {{ $credit['pour_le_comptable'] ? 'le comptable' : 'votre expert-comptable' }}.</p>
                    <p>{{ \App\Domain\Charges\Services\CreditsDeTva::CONSEIL }}</p>
                </div>

                @if ($credit['declarations'] === [])
                    <p class="mt-3 text-sm text-gray-600 dark:text-gray-400" data-aucune-declaration>Aucun crédit de TVA indiqué pour le moment : tant qu'il manque, la TVA à payer est une estimation sans crédit.</p>
                @else
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full text-sm" data-declarations-du-credit>
                            <thead>
                                <tr class="text-gray-500 dark:text-gray-400">
                                    <th scope="col" class="py-1 pe-3 text-start font-medium">Déclaration</th>
                                    <th scope="col" class="py-1 pe-3 text-end font-medium">Solde</th>
                                    <th scope="col" class="py-1 pe-3 text-start font-medium">État</th>
                                    <th scope="col" class="py-1 pe-3 text-start font-medium">Gestes</th>
                                    <th scope="col" class="py-1 text-start font-medium"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($credit['declarations'] as $declaration)
                                    <tr class="border-t border-gray-100 align-top dark:border-white/5" data-declaration="{{ $declaration['uuid'] }}">
                                        <td class="py-2 pe-3">
                                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $declaration['periode'] }}</span>
                                            @if ($declaration['point_de_depart'])
                                                <span class="block text-xs text-gray-500 dark:text-gray-400" data-point-de-depart>point de départ du report</span>
                                            @elseif (! $declaration['de_la_cadence'])
                                                <span class="block text-xs text-gray-500 dark:text-gray-400">d'un autre rythme de déclaration, gardée en historique</span>
                                            @endif
                                        </td>
                                        <td class="piliqi-credit__montant py-2 pe-3">{{ $declaration['solde'] }}</td>
                                        <td class="py-2 pe-3"><x-filament::badge :color="$declaration['couleur']" data-etat-du-credit>{{ $declaration['etat'] }}</x-filament::badge></td>
                                        <td class="py-2 pe-3 text-gray-600 dark:text-gray-400">
                                            @foreach ($declaration['gestes'] as $geste)
                                                <span class="block">{{ $geste }}</span>
                                            @endforeach
                                        </td>
                                        <td class="py-2">
                                            @if ($declaration['de_la_cadence'])
                                                <div class="flex flex-wrap gap-2">
                                                    @if ($credit['est_proprietaire'])
                                                        {{ ($this->modifierLeCreditAction)(['credit' => $declaration['uuid']]) }}
                                                    @endif
                                                    @if ($credit['peut_verifier'] && ! $declaration['verifiee'])
                                                        {{ ($this->confirmerLeCreditAction)(['credit' => $declaration['uuid']]) }}
                                                    @endif
                                                    @if ($credit['peut_verifier'])
                                                        {{ ($this->corrigerLeCreditAction)(['credit' => $declaration['uuid']]) }}
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">Un montant positif est refusé : « {{ \App\Domain\Charges\Actions\DeclarerUnCreditDeTvaAction::REFUS_POSITIF }} » Vous pouvez déclarer un autre {{ $credit['cadence']->nomDeLaPeriode() }} plus tard : le plus récent sert de point de départ.</p>

                @if ($credit['peut_declarer'])
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        {{ $this->declarerLeCreditAction }}
                        @if ($credit['disponible_le'] !== null)
                            <span class="text-sm text-gray-600 dark:text-gray-400" data-credit-disponible-le>Disponible le {{ $credit['disponible_le'] }}.</span>
                        @endif
                    </div>
                @endif
            </x-filament::section>
        </div>
    @endif

    {{ $this->table }}

    <x-filament-actions::modals />
</div>
