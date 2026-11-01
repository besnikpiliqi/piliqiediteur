{{-- Voir FicheDuClient. --}}
<x-filament-panels::page>
    @php($lien = $this->lien())
    @php($client = $this->client())

    <x-filament::section icon="heroicon-o-identification">
        <x-slot name="heading">Identité et réglages fiscaux</x-slot>

        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">Dénomination</dt><dd>{{ $client->raison_sociale ?? 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-gray-500">SIREN</dt><dd>{{ $client->siren }}</dd></div>
            <div><dt class="text-sm text-gray-500">Forme juridique</dt><dd>{{ $client->formeImprimee() ?? 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Type d'activité</dt><dd>{{ $client->type_activite->getLabel() }}</dd></div>
            <div><dt class="text-sm text-gray-500">Immatriculation</dt><dd>{{ $client->immatriculation ?? 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-gray-500">N° de TVA</dt><dd>{{ $client->numero_tva ?? 'Non renseigné' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Siège</dt><dd>{{ collect([$client->siege_adresse, $client->siege_complement_adresse, trim(($client->siege_code_postal ?? '').' '.($client->siege_ville ?? ''))])->filter()->implode(', ') ?: 'Non renseigné' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Régime de TVA</dt><dd>{{ $client->regime_tva?->getLabel() ?? 'Non choisi' }}{{ $client->option_debits_tva ? ' · option pour les débits' : '' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Mois de fin d'exercice</dt><dd>{{ \App\Domain\Calendrier\Calendrier::nomDuMois((int) $client->mois_fin_exercice) }}</dd></div>
            <div><dt class="text-sm text-gray-500">Statut</dt><dd>{{ $client->estClose() ? 'Inscription close' : ($client->estEnLectureSeule() ? 'Lecture seule' : ($client->estValidee() ? 'Validée' : 'En attente du courrier')) }}</dd></div>
        </dl>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-key">
        <x-slot name="heading">Autorisations accordées à votre cabinet</x-slot>

        @if ($this->autorisations() === [])
            <p>Ce client n'accorde aucune autorisation : votre cabinet n'ouvre aucun document, aucune fiche ni aucun téléchargement de ses magasins.</p>
        @else
            <ul class="list-disc pl-5">
                @foreach ($this->autorisations() as $autorisation)
                    <li>{{ $autorisation }}</li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-building-storefront">
        <x-slot name="heading">Magasins réels</x-slot>

        @forelse ($this->magasins() as $magasin)
            <div class="flex items-center justify-between gap-4 py-2">
                <div>
                    <p class="font-semibold">{{ $magasin->nom }} <span class="text-sm text-gray-500">({{ $magasin->code }})</span></p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ $magasin->ville ?? 'Ville non renseignée' }} · {{ $magasin->statut->getLabel() }}</p>
                </div>
                <x-filament::button tag="a" :href="$this->urlDuMagasin($magasin)" icon="heroicon-o-arrow-top-right-on-square">Ouvrir le magasin</x-filament::button>
            </div>
        @empty
            <p>Ce client n'a encore aucun magasin réel.</p>
        @endforelse
    </x-filament::section>

    @if (auth()->user()->estProprietaire())
        <x-filament::section icon="heroicon-o-users">
            <x-slot name="heading">Collaborateurs confiés</x-slot>

            <p>{{ $this->collaborateurs() === [] ? 'Aucun : vous seul suivez ce client.' : implode(', ', $this->collaborateurs()) }}</p>
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-link">
        <x-slot name="heading">Le lien</x-slot>

        <p>{{ $lien->origine->getLabel() }}, actif depuis le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($lien->actif_depuis, 'd/m/Y') }}@if ($lien->demande_par_nom) · demandé par {{ $lien->demande_par_nom }}@endif @if ($lien->repondu_par_nom) · accepté par {{ $lien->repondu_par_nom }}@endif.</p>
        <p class="text-sm text-gray-600 dark:text-gray-400">Les écritures, l'export comptable, l'état de TVA et les rapports de chaque client s'afficheront ici avec les prochaines étapes de {{ config('app.name') }}.</p>
    </x-filament::section>
</x-filament-panels::page>
