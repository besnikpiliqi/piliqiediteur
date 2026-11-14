{{-- Voir LaSociete. --}}
<x-filament-panels::page>
    @php($societe = $this->societe())

    <x-filament::section icon="heroicon-o-identification">
        <x-slot name="heading">Identité et réglages fiscaux</x-slot>
        <x-slot name="description">En lecture seule : seul le propriétaire de la société les change.</x-slot>

        <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div><dt class="text-sm text-gray-500">Dénomination</dt><dd>{{ $societe->raison_sociale ?? 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-gray-500">SIREN</dt><dd>{{ $societe->siren }}</dd></div>
            <div><dt class="text-sm text-gray-500">Forme juridique</dt><dd>{{ $societe->formeImprimee() ?? 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Type d'activité</dt><dd>{{ $societe->type_activite->getLabel() }}</dd></div>
            <div><dt class="text-sm text-gray-500">Immatriculation</dt><dd>{{ $societe->immatriculation ?? 'Non renseignée' }}</dd></div>
            <div><dt class="text-sm text-gray-500">N° de TVA</dt><dd>{{ $societe->numero_tva ?? 'Non renseigné' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Siège</dt><dd>{{ collect([$societe->siege_adresse, $societe->siege_complement_adresse, trim(($societe->siege_code_postal ?? '').' '.($societe->siege_ville ?? ''))])->filter()->implode(', ') ?: 'Non renseigné' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Régime de TVA</dt><dd>{{ $societe->regime_tva?->getLabel() ?? 'Non choisi' }}{{ $societe->option_debits_tva ? ' · option pour les débits' : '' }}</dd></div>
            <div><dt class="text-sm text-gray-500">Mois de fin d'exercice</dt><dd>{{ \App\Domain\Calendrier\Calendrier::nomDuMois((int) $societe->mois_fin_exercice) }}</dd></div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
