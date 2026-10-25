{{-- Voir VerificationSociete. --}}
<x-filament-panels::page>
    @php($societe = $this->societe())
    @php($courrier = $this->dernierCourrier())
    @php($destinataire = $this->destinataire())

    <x-filament::section icon="heroicon-o-shield-check">
        <x-slot name="heading">Où en est votre société</x-slot>

        @if ($societe->estValidee())
            <p>Votre société est validée depuis le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($societe->validee_le, 'd/m/Y') }}.</p>
        @elseif ($societe->estClose())
            <p>L'inscription de votre société est close ({{ $societe->motif_cloture?->getLabel() }}) : vos magasins restent consultables, en lecture seule.</p>
        @else
            <p>Société en attente du code de son courrier recommandé : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu'à sa validation. Configurez-la et essayez {{ config('app.name') }} dans le magasin de démonstration, depuis le tableau de bord.</p>
        @endif
    </x-filament::section>

    @if ($societe->attendSonCode())
        <x-filament::section icon="heroicon-o-envelope">
            <x-slot name="heading">Le courrier recommandé</x-slot>

            @if ($courrier !== null && $destinataire !== null)
                <p>Le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($courrier->envoye_le, 'd/m/Y') }}, un courrier recommandé avec avis de réception, à remettre au destinataire en personne, est parti à :</p>
                <p>@foreach ($destinataire->lignes() as $ligne){{ $ligne }}<br>@endforeach</p>
                @if ($courrier->retourne_le !== null)
                    <p>Ce courrier est revenu sans avoir été retiré au bureau de poste : son code ne vaut plus. Sans nouveau courrier, l'inscription sera close le {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($this->cloturePrevueLe(), 'd/m/Y à H:i') }} et le SIREN libéré.</p>
                @else
                    <p>Son code est valable jusqu'au {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($courrier->expire_le, 'd/m/Y à H:i') }} et ne sert qu'une fois ; sans code saisi d'ici là, l'inscription sera close et le SIREN libéré. S'il n'est pas remis en mains propres, le destinataire le retire au bureau de poste.</p>
                @endif
            @endif

            <p>Saisissez le code sur <a href="{{ $this->adresseDeLaPageDuCode() }}" class="underline">la page du code</a>, que le QR code du courrier ouvre aussi.</p>

            @php($nouvelEnvoi = $this->nouvelEnvoiPossibleLe())
            @if ($nouvelEnvoi !== null && $nouvelEnvoi->isFuture())
                <p>Courrier pas reçu ? Un nouveau courrier pourra être demandé à partir du {{ \App\Domain\Calendrier\Calendrier::deParis()->afficher($nouvelEnvoi, 'd/m/Y à H:i') }}.</p>
            @elseif ($this->piecesPossibles())
                <p>Le nouveau courrier est parti. S'il ne vous parvient pas non plus, déposez des pièces pour que l'éditeur traite votre demande, ou contactez-le.</p>
            @endif
        </x-filament::section>
    @endif

    @if ($this->etablissementsAVerifier()->isNotEmpty())
        <x-filament::section icon="heroicon-o-building-office-2">
            <x-slot name="heading">Établissements à confirmer par l'INSEE</x-slot>

            @foreach ($this->etablissementsAVerifier() as $magasin)
                <p>{{ $magasin->nom }} (SIRET {{ $magasin->siret }}) : établissement pas encore confirmé par le répertoire Sirene ; il ne vendra qu'après sa confirmation.</p>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
