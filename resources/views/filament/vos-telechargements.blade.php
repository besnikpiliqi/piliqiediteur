{{--
    La section « Vos téléchargements des sept derniers jours » (voir
    App\Filament\VosTelechargements) : elle se relit d'elle-même tant
    qu'une demande est en préparation.

    @param \Illuminate\Support\Collection<int, \App\Domain\Tenancy\Models\TelechargementDeDocuments> $telechargements
    @param bool $enPreparation
    @param \Carbon\CarbonInterface $avertissementDepuis
--}}
<div data-vos-telechargements>
    {{-- La relecture tient sur un élément qui n'existe que tant qu'une demande est en préparation : retiré, il arrête la relecture. --}}
    @if ($enPreparation)
        <div wire:poll.{{ \App\Filament\VosTelechargements::INTERVALLE }} data-relecture></div>
    @endif
    <x-filament::section heading="Vos téléchargements des sept derniers jours">
        @if ($telechargements->isEmpty())
            <p class="text-sm">Aucun téléchargement.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($telechargements as $telechargement)
                    <li wire:key="telechargement-{{ $telechargement->uuid }}">
                        Du {{ \App\Domain\Calendrier\Calendrier::afficherJour((string) $telechargement->du, 'd/m/Y') }} au {{ \App\Domain\Calendrier\Calendrier::afficherJour((string) $telechargement->au, 'd/m/Y') }}, {{ $telechargement->format->getLabel() }} :
                        @switch ($telechargement->statut)
                            @case (\App\Domain\Tenancy\Enums\StatutDuTelechargement::Pret)
                                prêt, {{ $telechargement->nombre_documents }} document{{ $telechargement->nombre_documents > 1 ? 's' : '' }},
                                <x-filament::link :href="$telechargement->lien()" :download="$telechargement->nomDuFichier()">Télécharger</x-filament::link>
                                @break
                            @case (\App\Domain\Tenancy\Enums\StatutDuTelechargement::Echec)
                                échec : {{ $telechargement->motif_echec }}
                                @break
                            @default
                                en préparation.
                                @if ($telechargement->created_at !== null && $telechargement->created_at->lessThanOrEqualTo($avertissementDepuis))
                                    <span class="text-danger-600 dark:text-danger-400">{{ \App\Filament\VosTelechargements::TRAVAILLEUR_ARRETE }}</span>
                                @endif
                        @endswitch
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</div>
