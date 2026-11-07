{{--
    La page « Télécharger les documents » d'un magasin, de l'espace de la
    société et de l'espace du cabinet (voir TelechargeLesDocumentsParPeriode).

    @param \Illuminate\Support\Collection<int, \App\Domain\Tenancy\Models\Magasin> $magasinsProposes
    @param list<\App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable> $typesProposes
    @param bool $avecLesAchats
    @param array<string, string> $raccourcis
    @param list<\App\Domain\Facturation\Enums\OrigineDesAchats> $origines
    @param list<\App\Domain\Facturation\Enums\FormatDuTelechargement> $formats
    @param bool $periodeValide
    @param list<\App\Domain\Facturation\Services\DocumentTrouve> $documents
    @param int $nombreTrouves
    @param int $nombreDePages
    @param int $nombreSelectionnes
    @param string $totalSelectionne
    @param \Closure(int): string $euros
    @param \Illuminate\Support\Collection<int, \App\Domain\Tenancy\Models\TelechargementDeDocuments> $telechargements
    @param array<string, string>|null $clients  dans l'espace du cabinet : les clients proposés, par uuid
    @param bool $plusieursMagasins  hors d'un magasin : les cases des magasins
--}}
<x-filament-panels::page>
    @if ($clients !== null)
        <x-filament::section>
            <label class="flex flex-wrap items-center gap-3">
                <span class="text-sm font-medium">Client</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="client">
                        @foreach ($clients as $uuid => $nom)
                            <option value="{{ $uuid }}">{{ $nom }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        </x-filament::section>
    @endif

    <x-filament::section heading="Période et documents">
        <div class="flex flex-wrap items-end gap-4">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">Du</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="du" />
                </x-filament::input.wrapper>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium">Au</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="au" />
                </x-filament::input.wrapper>
            </label>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($raccourcis as $nom => $libelle)
                <x-filament::button size="sm" color="gray" wire:click="raccourci('{{ $nom }}')">{{ $libelle }}</x-filament::button>
            @endforeach
        </div>

        @unless ($periodeValide)
            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">Choisissez une période : son premier jour, puis son dernier, au plus tard le même.</p>
        @endunless

        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Les jours sont ceux du calendrier de chaque magasin : le jour d'émission d'une vente ou d'un avoir, la date de la facture du fournisseur d'un achat, la journée d'une clôture, le jour d'enregistrement d'un paiement.</p>

        <fieldset class="mt-4">
            <legend class="text-sm font-medium">Types de documents</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                @foreach ($typesProposes as $type)
                    <label class="flex items-center gap-2">
                        <x-filament::input.checkbox wire:model.live="types" value="{{ $type->value }}" />
                        <span class="text-sm">{{ $type->getLabel() }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        @if ($avecLesAchats)
            <fieldset class="mt-4">
                <legend class="text-sm font-medium">Origine des factures d'achat et des avoirs fournisseurs</legend>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach ($origines as $origine)
                        <label class="flex items-center gap-2">
                            <input type="radio" class="fi-radio-input" wire:model.live="origine" value="{{ $origine->value }}" />
                            <span class="text-sm">{{ $origine->getLabel() }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        @if ($plusieursMagasins)
            <fieldset class="mt-4">
                <legend class="text-sm font-medium">Magasins</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($magasinsProposes as $magasin)
                        <label class="flex items-center gap-2">
                            <x-filament::input.checkbox wire:model.live="magasinsCoches" value="{{ $magasin->getKey() }}" />
                            <span class="text-sm">{{ $magasin->nom }} ({{ $magasin->code }}){{ $magasin->est_demonstration ? ' — démonstration, documents sans valeur' : '' }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Documents trouvés : {{ $nombreTrouves }}</x-slot>
        <x-slot name="headerEnd">
            <div class="flex flex-wrap gap-2">
                <x-filament::button size="sm" color="gray" wire:click="toutSelectionner" :disabled="$nombreTrouves === 0">Tout sélectionner</x-filament::button>
                <x-filament::button size="sm" color="gray" wire:click="toutDeselectionner" :disabled="$nombreSelectionnes === 0">Tout désélectionner</x-filament::button>
            </div>
        </x-slot>

        @if ($documents === [])
            <p class="text-sm">Aucun document pour cette recherche.</p>
        @else
            <table class="w-full text-start text-sm">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-3"><span class="sr-only">Choisir</span></th>
                        <th class="py-1 pe-3 text-start font-medium">Type</th>
                        <th class="py-1 pe-3 text-start font-medium">Magasin</th>
                        <th class="py-1 pe-3 text-start font-medium">Numéro</th>
                        <th class="py-1 pe-3 text-start font-medium">Date</th>
                        <th class="py-1 pe-3 text-start font-medium">Tiers</th>
                        <th class="py-1 text-end font-medium">TTC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($documents as $document)
                        <tr class="border-t border-gray-200 dark:border-white/10" wire:key="document-{{ $document->cle() }}">
                            <td class="py-1 pe-3">
                                <x-filament::input.checkbox wire:model.live="selection" value="{{ $document->cle() }}" aria-label="Choisir {{ $document->numero }}" />
                            </td>
                            <td class="py-1 pe-3">{{ $document->type->libelleDUnDocument() }}{{ $document->origine === null ? '' : ' ('.$document->origine.')' }}</td>
                            <td class="py-1 pe-3">{{ $document->magasin }}</td>
                            <td class="py-1 pe-3">{{ $document->numero }}</td>
                            <td class="py-1 pe-3">{{ \App\Domain\Calendrier\Calendrier::afficherJour($document->jour, 'd/m/Y') }}</td>
                            <td class="py-1 pe-3">{{ $document->tiers ?? '—' }}</td>
                            <td class="py-1 text-end">{{ $euros($document->ttc) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if ($nombreDePages > 1)
                <div class="mt-3 flex items-center gap-3">
                    <x-filament::button size="sm" color="gray" wire:click="pagePrecedente" :disabled="$this->pageDeLaListe <= 1">Page précédente</x-filament::button>
                    <span class="text-sm">Page {{ $this->pageDeLaListe }} sur {{ $nombreDePages }}</span>
                    <x-filament::button size="sm" color="gray" wire:click="pageSuivante" :disabled="$this->pageDeLaListe >= $nombreDePages">Page suivante</x-filament::button>
                </div>
            @endif
        @endif

        <p class="mt-3 text-sm font-medium" data-selection>Sélection : {{ $nombreSelectionnes }} document{{ $nombreSelectionnes > 1 ? 's' : '' }}, {{ $totalSelectionne }} TTC</p>
    </x-filament::section>

    <x-filament::section heading="Format et téléchargement">
        <div class="flex flex-wrap items-center gap-4">
            @foreach ($formats as $format)
                <label class="flex items-center gap-2">
                    <input type="radio" class="fi-radio-input" wire:model.live="format" value="{{ $format->value }}" />
                    <span class="text-sm">{{ $format->getLabel() }}</span>
                </label>
            @endforeach
            <x-filament::button icon="heroicon-o-arrow-down-tray" wire:click="telecharger" :disabled="$nombreSelectionnes === 0">Télécharger</x-filament::button>
        </div>
        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Le fichier se prépare en arrière-plan : une notification vous prévient quand il est prêt, et son lien vaut sept jours, pour vous seul.</p>
    </x-filament::section>

    <x-filament::section heading="Vos téléchargements des sept derniers jours">
        @if ($telechargements->isEmpty())
            <p class="text-sm">Aucun téléchargement.</p>
        @else
            <ul class="space-y-1 text-sm">
                @foreach ($telechargements as $telechargement)
                    <li>
                        Du {{ \App\Domain\Calendrier\Calendrier::afficherJour((string) $telechargement->du, 'd/m/Y') }} au {{ \App\Domain\Calendrier\Calendrier::afficherJour((string) $telechargement->au, 'd/m/Y') }}, {{ $telechargement->format->getLabel() }} :
                        @switch ($telechargement->statut)
                            @case (\App\Domain\Tenancy\Enums\StatutDuTelechargement::Pret)
                                {{ $telechargement->nombre_documents }} document{{ $telechargement->nombre_documents > 1 ? 's' : '' }},
                                <x-filament::link :href="$telechargement->lien()" :download="$telechargement->nomDuFichier()">télécharger</x-filament::link>
                                @break
                            @case (\App\Domain\Tenancy\Enums\StatutDuTelechargement::Echec)
                                {{ $telechargement->motif_echec }}
                                @break
                            @default
                                en préparation.
                        @endswitch
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
