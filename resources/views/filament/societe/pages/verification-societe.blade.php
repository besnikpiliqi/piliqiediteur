{{-- Voir VerificationSociete. --}}
<x-filament-panels::page>
    @php($societe = $this->societe())

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Où en est votre société</x-slot>

        @if ($societe->estValidee())
            <p>Votre société est validée depuis le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($societe->validee_le, 'd/m/Y') }}.</p>
        @elseif ($societe->estClose())
            <p>L'inscription de votre société est close ({{ $societe->motif_cloture?->getLabel() }}) : vos magasins restent consultables, en lecture seule.</p>
        @else
            <p>Société en attente du code de son courrier recommandé : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu'à sa validation. Configurez-la et essayez {{ config('app.name') }} dans le magasin de démonstration, depuis le tableau de bord.</p>
        @endif

        @if ($societe->attendSonCode())
            @include('filament.rappel-du-type', ['societe' => $societe])
        @endif
    </x-filament::section>

    @include('filament.societe.courrier-de-verification')

    @if ($this->etablissementsAVerifier()->isNotEmpty())
        <x-filament::section icon="heroicon-o-building-office-2">
            <x-slot name="heading">Établissements à confirmer par l'INSEE</x-slot>

            @foreach ($this->etablissementsAVerifier() as $magasin)
                @php($refus = $this->refusDuDernierControle($magasin))
                <p>{{ $magasin->nom }} (SIRET {{ $magasin->siret }}) : établissement pas encore confirmé par le répertoire Sirene ; il ne vendra qu'après sa confirmation.@if ($refus !== null) Dernier contrôle : {{ $refus }} Corrigez son NIC.@endif</p>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
