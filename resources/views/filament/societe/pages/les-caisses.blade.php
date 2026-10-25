{{-- Voir LesCaisses. --}}
<x-filament-panels::page>
    @php($euros = fn (float|string $montant): string => number_format((float) $montant, 2, ',', ' ').' €')
    @php($calendrier = \App\Domain\Calendrier\Calendrier::deParis())

    <x-filament::section>
        <label class="flex flex-wrap items-center gap-3">
            <span class="text-sm font-medium">Relevés du</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="jour" />
            </x-filament::input.wrapper>
        </label>
    </x-filament::section>

    @foreach ($caisses as $caissesDuMagasin)
        @php($magasin = $caissesDuMagasin['magasin'])
        <x-filament::section icon="heroicon-o-building-storefront">
            <x-slot name="heading">{{ $magasin->nom }} ({{ $magasin->code }})</x-slot>
            <x-slot name="headerEnd">
                <x-filament::link :href="$caissesDuMagasin['url']">Ouvrir dans le magasin</x-filament::link>
            </x-slot>

            <p class="mb-3">
                @if ($caissesDuMagasin['caisses_ouvertes'] === [])
                    Caisses encore ouvertes : aucune.
                @else
                    Caisses encore ouvertes : {{ implode(', ', $caissesDuMagasin['caisses_ouvertes']) }}.
                @endif
            </p>

            @if ($caissesDuMagasin['releves']->isEmpty())
                <p>Aucun relevé de caisse fermé le {{ \App\Domain\Calendrier\Calendrier::afficherJour($journee, 'd/m/Y') }}.</p>
            @else
                <table class="w-full text-start text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="py-1 pe-3 text-start font-medium">Vendeur</th>
                            <th class="py-1 pe-3 text-start font-medium">Période</th>
                            <th class="py-1 pe-3 text-start font-medium">Fermeture</th>
                            <th class="py-1 pe-3 text-end font-medium">Ventes</th>
                            <th class="py-1 pe-3 text-end font-medium">TTC vendu</th>
                            <th class="py-1 pe-3 text-start font-medium">Encaissé</th>
                            <th class="py-1 pe-3 text-end font-medium">Crédit d'avoir</th>
                            <th class="py-1 text-end font-medium">Reste dû</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($caissesDuMagasin['releves'] as $releve)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-1 pe-3">{{ $releve->vendeur_nom }}</td>
                                <td class="py-1 pe-3">{{ $calendrier->afficher($releve->periode_debut, 'd/m/Y H:i') }} → {{ $calendrier->afficher($releve->jusqu_au, 'd/m/Y H:i') }}</td>
                                <td class="py-1 pe-3">{{ $releve->libelleDeFermeture() }}</td>
                                <td class="py-1 pe-3 text-end">{{ $releve->nombre_ventes }}</td>
                                <td class="py-1 pe-3 text-end">{{ $euros($releve->total_ttc) }}</td>
                                <td class="py-1 pe-3">
                                    {{ $euros($releve->totalEncaisse()) }}
                                    @if ($releve->encaissementsEnClair() !== [])
                                        ({{ implode(' ; ', $releve->encaissementsEnClair()) }})
                                    @endif
                                </td>
                                <td class="py-1 pe-3 text-end">{{ $euros($releve->credit_avoir) }}</td>
                                <td class="py-1 text-end">{{ $euros($releve->reste_du) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
