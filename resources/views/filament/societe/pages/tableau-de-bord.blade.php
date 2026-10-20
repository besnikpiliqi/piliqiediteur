{{-- Voir TableauDeBord. --}}
<x-filament-panels::page>
    @php($societe = $this->societe())
    @php($manquantes = $societe->mentionsLegalesManquantes())

    @include('filament.bienvenue', ['compte' => auth()->user(), 'role' => auth()->user()->libelleDuRoleDeSociete()])

    @if ($this->resteDesEtapes())
        <x-filament::section icon="heroicon-o-list-bullet">
            <x-slot name="heading">Vos premières étapes</x-slot>

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
                            @if ($etape['titre'] === \App\Filament\Societe\Pages\TableauDeBord::ETAPE_DEMONSTRATION && ! $etape['faite'])
                                {{ $this->ouvrirLaDemonstrationAction }}
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Vérification de la société</x-slot>

        <p>{{ $this->etatDeLaVerification() }}</p>
        <p>SIREN : {{ $societe->siren }}</p>
    </x-filament::section>

    @if ($manquantes !== [])
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Identité incomplète pour facturer un professionnel</x-slot>

            <p>Vos tickets de caisse restent possibles, mais aucune facture à un professionnel ne peut être émise tant que ces mentions manquent : {{ implode(', ', $manquantes) }}.</p>
        </x-filament::section>
    @endif

    @php($demonstration = $this->demonstration())
    @if ($demonstration !== null)
        <x-filament::section icon="heroicon-o-academic-cap">
            <x-slot name="heading">{{ $demonstration->statut === \App\Domain\Tenancy\Enums\StatutMagasin::Ferme ? 'Démonstration (terminée)' : 'Démonstration' }}</x-slot>

            <p>
                <a href="{{ \Filament\Pages\Dashboard::getUrl(panel: 'app', tenant: $demonstration) }}" class="underline">Ouvrir le magasin de démonstration</a>
                · ses documents n'ont aucune valeur, et rien n'en passe à vos magasins réels.
            </p>

            {{ $this->ouvrirLaDemonstrationAction }}
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-building-storefront">
        <x-slot name="heading">Vos magasins</x-slot>

        @if ($this->peutNommerUnResponsable())
            <p class="mb-4">Nommer un responsable : <x-filament::link :href="\App\Filament\Societe\Resources\MembreResource::getUrl('index')">Comptes</x-filament::link></p>
        @endif

        @php($magasins = $this->magasins())
        @if ($magasins->isEmpty())
            <p>Aucun magasin pour le moment.</p>
        @else
            @php($aEncaisser = $this->facturesAEncaisser())
            @php($calculateur = app(\App\Domain\Tva\Services\CalculateurLigne::class))
            <table class="w-full text-start text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-3 text-start font-medium">Magasin</th>
                        <th class="py-1 pe-3 text-start font-medium">SIRET</th>
                        <th class="py-1 pe-3 text-start font-medium">État</th>
                        <th class="py-1 pe-3 text-end font-medium">À encaisser</th>
                        <th class="py-1 pe-3 text-end font-medium">Restant dû</th>
                        <th class="py-1 text-end font-medium">dont en retard</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($magasins as $magasin)
                        @php($totaux = $aEncaisser[$magasin->id] ?? null)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1 pe-3"><strong>{{ $magasin->nom }}</strong> ({{ $magasin->code }})</td>
                            <td class="py-1 pe-3">
                                {{ $magasin->siret }}
                                · {{ $magasin->etablissement_verifie_le === null ? 'établissement à vérifier' : 'établissement vérifié' }}
                            </td>
                            <td class="py-1 pe-3">{{ $magasin->motifDeLectureSeule() ?? 'ouvert' }}</td>
                            @if ($totaux === null)
                                <td class="py-1 pe-3 text-end">—</td>
                                <td class="py-1 pe-3 text-end">—</td>
                                <td class="py-1 text-end">—</td>
                            @else
                                <td class="py-1 pe-3 text-end">
                                    <x-filament::link :href="\App\Filament\App\Resources\FactureResource::urlDesFacturesAEncaisser($magasin)">{{ $totaux['nombre'] }}</x-filament::link>
                                </td>
                                <td class="py-1 pe-3 text-end">{{ $calculateur->enEuros($totaux['restant_du']) }}</td>
                                <td @class(['py-1 text-end', 'font-semibold text-danger-600 dark:text-danger-400' => $totaux['nombre_en_retard'] > 0])>
                                    {{ $totaux['nombre_en_retard'] }} · {{ $calculateur->enEuros($totaux['restant_du_en_retard']) }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
