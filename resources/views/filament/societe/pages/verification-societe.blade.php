{{-- Voir VerificationSociete. --}}
<x-filament-panels::page>
    @php($societe = $this->societe())
    @php($etat = $this->etat())

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Où en est votre société</x-slot>

        @if ($etat->validee)
            <p>Votre société est validée depuis le {{ $societe->validee_le->setTimezone(config('app.timezone'))->format('d/m/Y') }}.</p>
        @elseif ($etat->refusee)
            <p>L'inscription de votre société a été refusée : vos magasins restent consultables, en lecture seule.</p>
        @elseif ($etat->reclamationPossible())
            <p>Ce SIREN est déjà utilisé par un autre compte {{ config('app.name') }}. L'éditeur a été prévenu et va examiner votre demande.</p>
        @else
            <p>Société en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu'à sa validation. Essayez {{ config('app.name') }} dans le magasin de démonstration, depuis le tableau de bord.</p>
        @endif
    </x-filament::section>

    <x-filament::section icon="heroicon-o-building-office-2">
        <x-slot name="heading">1. Entreprise et établissements vérifiés auprès de l'INSEE</x-slot>

        <p>SIREN : {{ $societe->siren }} — {{ $etat->entrepriseEtEtablissements ? 'vérifiés' : 'à vérifier' }}</p>
        @foreach ($this->etablissementsAVerifier() as $magasin)
            <p>{{ $magasin->nom }} (SIRET {{ $magasin->siret }}) : établissement pas encore confirmé par le répertoire Sirene.</p>
        @endforeach
    </x-filament::section>

    <x-filament::section icon="heroicon-o-identification">
        <x-slot name="heading">2. Votre qualité de représentant</x-slot>

        @if ($etat->preuveDeQualite !== null)
            <p>Prouvée.</p>
        @elseif ($etat->piecesExaminees)
            <p>Vos pièces ont été examinées par l'éditeur : saisissez le code du courrier recommandé pour terminer.</p>
        @else
            <p>Prouvez-la par la vérification de votre identité, l'accord d'un dirigeant, ou des pièces examinées par l'éditeur.</p>
        @endif

        @php($identite = $this->identiteVerifiee())
        @if ($identite !== null)
            <p>Identité vérifiée : {{ trim(($identite['prenoms'][0] ?? '').' '.($identite['nom'] ?? '')) }}@if (($identite['naissance'] ?? null) !== null), née ou né en {{ $identite['naissance'] }}@endif.</p>
        @endif
    </x-filament::section>

    @if ($etat->attendUnCourrier() || $this->codeEnCours() !== null)
        <x-filament::section icon="heroicon-o-envelope">
            <x-slot name="heading">3. Contrôle de l'adresse officielle</x-slot>

            @if ($this->codeEnCours() !== null)
                <p>Un code est en route vers l'adresse officielle de votre entreprise : saisissez-le dès sa réception.</p>
            @else
                <p>Un code par courrier recommandé, ou par lettre recommandée électronique remise à un dirigeant, est nécessaire.</p>
            @endif
        </x-filament::section>
    @endif

    @if ($this->signaux() !== [])
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Points à lever</x-slot>

            <ul>
                @foreach ($this->signaux() as $signal)
                    <li>{{ $signal }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
