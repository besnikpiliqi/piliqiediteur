{{--
    La demande d'accord d'un dirigeant (voir AccordDirigeantController) :
    l'ouverture du lien ne change rien ; seuls les boutons approuvent, après
    la vérification d'identité, ou refusent.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Demande d'accord · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 34rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        button { border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; margin-top: .5rem; }
        .approuver { background: #4f46e5; color: #fff; }
        .refuser { background: #e5e7eb; color: #111827; }
        .message { color: #1f2937; font-weight: 600; }
        .petit { font-size: .875rem; color: #4b5563; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    @if ($message !== null)
        <p class="message">{{ $message }}</p>
    @endif

    @if ($demande !== null)
        @php($representant = $demande->representant())
        <p>{{ $demande->demandeur->name }} vous demande l'autorisation de gérer le compte {{ config('app.name') }} de {{ $demande->societe->getFilamentName() }} (SIREN {{ $demande->societe->siren }}), dont vous êtes {{ mb_strtolower($representant->role) }} au registre national des entreprises.</p>

        <p>Pour l'approuver, vous vérifiez votre identité en ligne auprès d'un prestataire certifié (votre pièce d'identité et votre visage) : il confirme que vous êtes {{ trim(($representant->prenoms[0] ?? '').' '.$representant->nom) }}. Votre consentement à ce traitement est demandé par le prestataire ; vous pouvez aussi refuser la demande.</p>

        <form method="POST" action="{{ route('accord-dirigeant.demarrer', ['jeton' => $jeton]) }}">
            @csrf
            <button type="submit" class="approuver">Vérifier mon identité et approuver</button>
        </form>
        <form method="POST" action="{{ route('accord-dirigeant.refuser', ['jeton' => $jeton]) }}">
            @csrf
            <button type="submit" class="refuser">Refuser</button>
        </form>

        <p class="petit">Cette demande est valable jusqu'au {{ $demande->expire_le->setTimezone(config('app.timezone'))->format('d/m/Y') }}.</p>
    @elseif ($message === null)
        <p class="message">{{ \App\Domain\Tenancy\Actions\ApprouverMandatAction::MESSAGE_LIEN_INVALIDE }}</p>
    @endif
</main>
</body>
</html>
