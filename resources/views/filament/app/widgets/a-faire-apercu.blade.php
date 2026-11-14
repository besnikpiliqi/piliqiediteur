{{-- Voir AFaireApercu et ChosesAFaireDuMagasin. --}}
<x-filament-widgets::widget>
    @foreach ($parties as $titre => $choses)
        @if ($titre === 'À faire' || $choses !== [])
            <x-filament::section :icon="$titre === 'À faire' ? 'heroicon-o-clipboard-document-check' : 'heroicon-o-eye'" @class(['mt-6' => $titre === 'À surveiller'])>
                <x-slot name="heading">{{ $titre }}</x-slot>

                @if ($titre === 'À surveiller')
                    <x-slot name="description">Ce que votre équipe doit faire, sans bouton d'action.</x-slot>
                @endif

                @if ($choses === [])
                    <p class="text-sm text-gray-500 dark:text-gray-400">Rien à faire pour le moment.</p>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($choses as $chose)
                            <li class="flex flex-wrap items-start justify-between gap-3 py-3" data-a-faire="{{ $chose->cle }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-filament::badge :color="$chose->urgence->getColor()">{{ $chose->urgence->getLabel() }}</x-filament::badge>
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $chose->groupe }}</span>
                                    </div>
                                    <p class="mt-1 font-semibold">{{ $chose->phrase }}</p>
                                    @if ($chose->precision !== null)
                                        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $chose->precision }}</p>
                                    @endif

                                    @if ($chose->lignes !== [])
                                        <table class="mt-2 w-full text-start text-sm">
                                            <tbody>
                                                @foreach ($chose->lignes as $ligne)
                                                    <tr class="border-t border-gray-200 dark:border-white/10">
                                                        <td class="py-1 pe-3">
                                                            @if ($ligne['url'] !== null)
                                                                <x-filament::link :href="$ligne['url']">{{ $ligne['libelle'] }}</x-filament::link>
                                                            @else
                                                                {{ $ligne['libelle'] }}
                                                            @endif
                                                        </td>
                                                        <td class="py-1 pe-3">{{ $ligne['description'] ?? '' }}</td>
                                                        <td class="py-1 pe-3 text-end">{{ $ligne['montant'] ?? '' }}</td>
                                                        <td @class(['py-1 pe-3', 'font-semibold text-danger-600 dark:text-danger-400' => $ligne['date_en_rouge']])>{{ $ligne['date'] ?? '' }}</td>
                                                        <td class="py-1">
                                                            @if ($ligne['badge'] !== null)
                                                                <x-filament::badge color="danger">{{ $ligne['badge'] }}</x-filament::badge>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif

                                    @if ($chose->lienDesLignes !== null)
                                        <p class="mt-2 text-sm">
                                            <x-filament::link :href="$chose->lienDesLignes['url']">{{ $chose->lienDesLignes['libelle'] }}</x-filament::link>
                                        </p>
                                    @endif
                                </div>

                                @if ($chose->url !== null)
                                    <x-filament::button tag="a" :href="$chose->url" size="sm" :color="$chose->aSurveiller ? 'gray' : 'primary'">{{ $chose->bouton }}</x-filament::button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        @endif
    @endforeach
</x-filament-widgets::widget>
