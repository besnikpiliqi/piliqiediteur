{{-- Voir TableauDeBordDuCabinet. --}}
<x-filament-panels::page>
    @php($cabinet = $this->cabinet())

    @include('filament.bienvenue', ['compte' => auth()->user(), 'role' => auth()->user()->libelleDuRoleDeSociete()])

    @if ($this->resteDesEtapes())
        <x-filament::section icon="heroicon-o-list-bullet">
            <x-slot name="heading">Vos étapes</x-slot>

            <ol class="space-y-3">
                @foreach ($this->etapes() as $etape)
                    <li class="flex items-start gap-3">
                        @if ($etape['faite'])
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-600" label="Faite" />
                        @else
                            <x-filament::icon icon="heroicon-o-ellipsis-horizontal-circle" class="h-6 w-6 text-gray-400" label="À faire" />
                        @endif
                        <div>
                            @if ($etape['url'] !== null)
                                <a href="{{ $etape['url'] }}" class="font-semibold underline">{{ $etape['titre'] }}</a>
                            @else
                                <span class="font-semibold">{{ $etape['titre'] }}</span>
                            @endif
                            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $etape['etat'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Vérification du cabinet</x-slot>

        <p>{{ $this->etatDeLaVerification() }}</p>
        <p>SIREN : {{ $cabinet->siren }}</p>
    </x-filament::section>

    @php($calculateur = app(\App\Domain\Facturation\Services\CalculateurLigne::class))

    <x-filament::section icon="heroicon-o-building-office-2">
        <x-slot name="heading">Vos clients</x-slot>

        @if ($clients === [])
            <p>{{ $cabinet->estUnCabinetVerifie() ? 'Aucun client lié ne vous est ouvert pour le moment.' : 'Vos clients s\'afficheront ici une fois votre cabinet vérifié.' }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pe-4">Client</th>
                            <th class="py-2 pe-4">SIREN</th>
                            <th class="py-2 pe-4">Statut</th>
                            <th class="py-2 pe-4">Fin d'exercice</th>
                            <th class="py-2 pe-4">Magasins réels</th>
                            <th class="py-2 pe-4">Factures à encaisser</th>
                            <th class="py-2 pe-4">Lié depuis</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $ligne)
                            @php($client = $ligne['client'])
                            <tr class="border-b border-gray-100 dark:border-white/5">
                                <td class="py-2 pe-4">{{ $client->raison_sociale ?? 'Sans dénomination' }}</td>
                                <td class="py-2 pe-4">{{ $client->siren }}</td>
                                <td class="py-2 pe-4">{{ $client->estEnLectureSeule() ? 'Lecture seule' : ($client->estValidee() ? 'Validée' : 'En attente du courrier') }}</td>
                                <td class="py-2 pe-4">{{ \App\Domain\Calendrier\Calendrier::nomDuMois((int) $client->mois_fin_exercice) }}</td>
                                <td class="py-2 pe-4">{{ $ligne['magasins'] }}</td>
                                <td class="py-2 pe-4">
                                    @if ($ligne['factures'] === null)
                                        —
                                    @else
                                        {{ $ligne['factures']['nombre'] }} · {{ $calculateur->enEuros($ligne['factures']['restant_du']) }}@if ($ligne['factures']['nombre_en_retard'] > 0), dont {{ $calculateur->enEuros($ligne['factures']['restant_du_en_retard']) }} en retard @endif
                                    @endif
                                </td>
                                <td class="py-2 pe-4">{{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($ligne['actif_depuis'], 'd/m/Y') }}</td>
                                <td class="py-2"><x-filament::link :href="$ligne['url']">Ouvrir</x-filament::link></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">Les écritures, l'export comptable, l'état de TVA et les rapports de chaque client s'afficheront ici avec les prochaines étapes de {{ config('app.name') }}.</p>
    </x-filament::section>

    @if ($liensActifs->isNotEmpty())
        <x-filament::section icon="heroicon-o-link">
            <x-slot name="heading">Liens actifs</x-slot>
            <x-slot name="description">Votre cabinet n'est plus vérifié : il n'a plus accès à ses clients, et l'accès reprendra quand il sera de nouveau vérifié. Vous pouvez seulement mettre fin à ses liens.</x-slot>

            @foreach ($liensActifs as $lien)
                <div class="flex items-center justify-between gap-4 border-b border-gray-100 py-2 dark:border-white/10">
                    <p>{{ $lien->client->raison_sociale ?? 'Sans dénomination' }} · SIREN {{ $lien->client->siren }} · lié depuis le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($lien->actif_depuis, 'd/m/Y') }}</p>
                    {{ ($this->mettreFinAuLienAction)(['uuid' => $lien->uuid]) }}
                </div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
