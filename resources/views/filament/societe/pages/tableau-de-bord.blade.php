{{-- Voir TableauDeBord. --}}
<x-filament-panels::page>
    @php($societe = $this->societe())
    @php($manquantes = $societe->mentionsLegalesManquantes())

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

    <x-filament::section icon="heroicon-o-building-storefront">
        <x-slot name="heading">Vos magasins</x-slot>

        <ul>
            @foreach ($this->magasins() as $magasin)
                <li>
                    <strong>{{ $magasin->nom }}</strong> ({{ $magasin->code }}) · SIRET {{ $magasin->siret }}
                    · {{ $magasin->etablissement_verifie_le === null ? 'établissement à vérifier' : 'établissement vérifié' }}
                    · {{ $magasin->motifDeLectureSeule() ?? 'ouvert' }}
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-panels::page>
