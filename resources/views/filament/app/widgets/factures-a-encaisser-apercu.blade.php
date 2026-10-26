{{-- Voir FacturesAEncaisserApercu. --}}
<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-banknotes" :icon-color="$totaux['nombre'] > 0 ? 'danger' : 'gray'">
        <x-slot name="heading">Factures à encaisser</x-slot>

        @if ($totaux['nombre'] === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">Aucune facture à encaisser.</p>
        @else
            <p class="font-semibold">
                {{ trans_choice('{1} 1 facture à encaisser|[2,*] :count factures à encaisser', $totaux['nombre']) }}
                · {{ $calculateur->enEuros($totaux['restant_du']) }} restant dû
            </p>

            @if ($totaux['nombre_en_retard'] > 0)
                <p class="font-semibold text-danger-600 dark:text-danger-400">
                    dont {{ trans_choice('{1} 1 en retard|[2,*] :count en retard', $totaux['nombre_en_retard']) }}
                    · {{ $calculateur->enEuros($totaux['restant_du_en_retard']) }}
                </p>
            @endif

            <table class="mt-4 w-full text-start text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-3 text-start font-medium">Numéro</th>
                        <th class="py-1 pe-3 text-start font-medium">Client</th>
                        <th class="py-1 pe-3 text-end font-medium">Restant dû</th>
                        <th class="py-1 pe-3 text-start font-medium">Échéance</th>
                        <th class="py-1 text-start font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plusAnciennes as $facture)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1 pe-3">
                                <x-filament::link :href="\App\Filament\App\Resources\FactureResource::getUrl('view', ['record' => $facture])">{{ $facture->numero }}</x-filament::link>
                            </td>
                            <td class="py-1 pe-3">{{ $facture->nomDuClient() ?? '—' }}</td>
                            <td class="py-1 pe-3 text-end">{{ $calculateur->enEuros($facture->resteDuEnCentimes()) }}</td>
                            <td @class(['py-1 pe-3', 'font-semibold text-danger-600 dark:text-danger-400' => $facture->estEchue()])>{{ $facture->date_echeance === null ? '—' : \App\Domain\Calendrier\Calendrier::afficherJour($facture->date_echeance, 'd/m/Y') }}</td>
                            <td class="py-1">
                                @if ($facture->paiement_contre_passe)
                                    <x-filament::badge color="danger">Paiement contre-passé</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <p class="mt-4">
                <x-filament::link :href="$urlDeLaListe">Voir toutes les factures à encaisser</x-filament::link>
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
