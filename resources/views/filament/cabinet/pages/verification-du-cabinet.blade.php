{{-- Voir VerificationDuCabinet. --}}
<x-filament-panels::page>
    @php($cabinet = $this->societe())
    @php($verification = $this->verification())
    @php($paris = \App\Domain\Calendrier\Calendrier::deParis())

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Où en est votre cabinet</x-slot>

        @if ($cabinet->estValidee())
            <p>Votre cabinet est validé depuis le {{ $paris->afficher($cabinet->validee_le, 'd/m/Y') }}.</p>
        @elseif ($cabinet->estClose())
            <p>L'inscription de votre cabinet est close ({{ $cabinet->motif_cloture?->getLabel() }}) : son espace reste consultable, en lecture seule.</p>
        @else
            <p>Votre cabinet attend le code de son courrier recommandé.</p>
        @endif

        @if ($cabinet->attendSonCode())
            @include('filament.rappel-du-type', ['societe' => $cabinet])
        @endif

        <p>Votre cabinet invitera et liera ses clients une fois validé, son code d'activité contrôlé et son inscription au tableau de l'Ordre vérifiée.</p>
    </x-filament::section>

    @include('filament.societe.courrier-de-verification')

    <x-filament::section icon="heroicon-o-briefcase">
        <x-slot name="heading">Code d'activité</x-slot>

        <p>Code lu au répertoire Sirene : {{ $verification?->code_activite ?? 'aucun' }}@if ($verification?->nomenclature_activite !== null) ({{ $verification->nomenclature_activite }})@endif.</p>
        @if ($verification?->aSonActiviteControlee())
            <p>Contrôlé le {{ $paris->afficher($verification->activite_controlee_le, 'd/m/Y') }}.</p>
        @elseif ($verification?->aUneAutreActivite())
            <p>Ce code n'est pas 69.20Z (activités comptables) : votre cabinet ne peut pas être vérifié.</p>
        @else
            <p>À contrôler par l'éditeur, avec votre inscription à l'Ordre.</p>
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-academic-cap">
        <x-slot name="heading">Inscription au tableau de l'Ordre des experts-comptables</x-slot>

        @if ($verification?->numero_ordre !== null)
            <p>N° d'inscription : {{ $verification->numero_ordre }} · conseil régional : {{ $verification->conseil_regional_ordre }}.</p>
        @endif

        @if ($verification === null)
            <p>À demander.</p>
        @elseif ($verification->ordre_retire_le !== null)
            <p>Radiation de l'Ordre constatée par l'éditeur le {{ $paris->afficher($verification->ordre_retire_le, 'd/m/Y') }} : votre cabinet n'a plus accès à ses clients.</p>
        @elseif ($verification->estVerifieALOrdre())
            <p>Vérifiée le {{ $paris->afficher($verification->ordre_verifie_le, 'd/m/Y') }} {{ $verification->ordre_voie?->getLabel() }}.</p>
        @elseif ($verification->attendLEditeur())
            <p>Demandée le {{ $paris->afficher($verification->ordre_demande_le, 'd/m/Y') }} : l'éditeur vérifie votre inscription au tableau de l'Ordre et vous prévient.</p>
        @elseif ($verification->ordre_refuse_le !== null)
            <p>Refusée le {{ $paris->afficher($verification->ordre_refuse_le, 'd/m/Y') }} : {{ $verification->ordre_motif_refus }}</p>
        @elseif ($this->annuaireAutomatique())
            <p>Donnez votre n° d'inscription au tableau et votre conseil régional : l'annuaire de l'Ordre est consulté pour le SIREN de votre cabinet.</p>
        @else
            <p>Donnez votre n° d'inscription au tableau et votre conseil régional : l'éditeur vérifie votre inscription au tableau de l'Ordre et vous prévient.</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
