{{--
    La confirmation d'une adresse de connexion en attente (voir
    ConfirmerNouvelleAdresseController) : l'ouverture du lien ne change
    rien, seul le bouton confirme. Un lien expiré, déjà servi, remplacé ou
    inconnu dit seulement qu'il n'est plus valable ; un lien qui se suit
    connecté au compte le dit, sans bouton, avec le lien de la connexion.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Confirmer votre adresse de connexion · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 32rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        button, .bouton { display: inline-block; text-decoration: none; background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        .message { font-weight: 600; }
        .erreur { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    @if ($message !== null)
        <p class="message @if ($erreur) erreur @endif">{{ $message }}</p>
    @endif

    @if ($connexion !== null)
        <p><a class="bouton" href="{{ $connexion }}">Me connecter</a></p>
    @endif

    @if ($nouvelleAdresse !== null)
        @if ($premiereAdresse)
            <p>Confirmez {{ $nouvelleAdresse }} comme adresse de connexion de votre compte : elle servira à vous connecter et à recevoir un lien « Mot de passe oublié ».</p>
        @else
            <p>Confirmez {{ $nouvelleAdresse }} comme nouvelle adresse de connexion de votre compte : l'ancienne ne servira plus à vous connecter, et elle en sera prévenue.</p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit">{{ $premiereAdresse ? 'Confirmer mon adresse' : 'Confirmer ma nouvelle adresse' }}</button>
        </form>
    @elseif ($message === null)
        <p class="message erreur">{{ \App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE }}</p>
    @endif
</main>
</body>
</html>
