{{--
    La proposition de rattachement reçue par une personne (voir
    RattachementController) : l'ouverture du lien ne change rien ; accepter
    redemande l'adresse et le mot de passe du compte, refuser ne demande
    rien.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Proposition de rattachement · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 34rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        label { display: block; margin-top: .75rem; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: .5rem; border: 1px solid #d1d5db; border-radius: .5rem; font-size: 1rem; }
        button { border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; margin-top: 1rem; }
        .accepter { background: #4f46e5; color: #fff; }
        .refuser { background: #e5e7eb; color: #111827; }
        .message { color: #1f2937; font-weight: 600; }
        .erreur { color: #b91c1c; }
        .petit { font-size: .875rem; color: #4b5563; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    @if ($message !== null)
        <p class="message @if ($erreur) erreur @endif">{{ $message }}</p>
    @endif

    @if ($demande !== null)
        <p>{{ $demande->demande_par_nom }} vous propose de rejoindre {{ $demande->societe->getFilamentName() }} avec votre compte actuel : {{ $role }}.</p>

        <form method="POST" action="{{ route('rattachement.accepter', ['jeton' => $jeton]) }}">
            @csrf
            <label for="email">Adresse e-mail de votre compte</label>
            <input id="email" name="email" type="email" autocomplete="username" required>
            <label for="password">Mot de passe de votre compte</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button type="submit" class="accepter">Accepter et rejoindre l'équipe</button>
        </form>
        <form method="POST" action="{{ route('rattachement.refuser', ['jeton' => $jeton]) }}">
            @csrf
            <button type="submit" class="refuser">Refuser</button>
        </form>

        <p class="petit">Cette proposition est valable jusqu'au {{ $demande->expire_le->setTimezone(config('app.timezone'))->format('d/m/Y') }}. Votre compte n'appartient qu'à une seule société à la fois.</p>
    @elseif ($message === null)
        <p class="message">{{ \App\Domain\Tenancy\Actions\AccepterUnRattachementAction::MESSAGE_LIEN_INVALIDE }}</p>
    @endif
</main>
</body>
</html>
