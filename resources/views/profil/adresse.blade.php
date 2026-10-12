{{--
    La confirmation d'une nouvelle adresse de connexion (voir
    ConfirmerNouvelleAdresseController) : l'ouverture du lien ne change
    rien, seul le bouton confirme. Un lien expiré, déjà servi, remplacé ou
    inconnu dit seulement qu'il n'est plus valable.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Confirmer votre nouvelle adresse · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 32rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        button { background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
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

    @if ($nouvelleAdresse !== null)
        <p>Confirmez {{ $nouvelleAdresse }} comme nouvelle adresse de connexion de votre compte : l'ancienne ne servira plus à vous connecter, et elle en sera prévenue.</p>

        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit">Confirmer ma nouvelle adresse</button>
        </form>
    @elseif ($message === null)
        <p class="message erreur">{{ \App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction::MESSAGE_LIEN_INVALIDE }}</p>
    @endif
</main>
</body>
</html>
