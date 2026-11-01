{{--
    Le courrier recommandé de la vérification d'une société qui attend son
    code : son destinataire, son envoi, la validité de son code ou son
    retour, la page où le saisir et la suite si le courrier n'arrive pas.
    Partagé par la page de la vérification d'une société (voir
    VerificationSociete) et celle d'un cabinet d'expertise comptable (voir
    VerificationDuCabinet), dont il lit les méthodes.
--}}
@php($societe = $this->societe())
@php($courrier = $this->dernierCourrier())
@php($destinataire = $this->destinataire())

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
