{{--
    La page du billet d'entrée du site réel (voir
    OuvrirMaDemonstrationController) : le formulaire du billet signé part
    seul, par le fichier /js/demonstration/envoyer-le-billet.js, vers le
    site de démonstration ; sans script, le bouton l'envoie. Un refus ne
    montre que son message.

    @param string|null $action
    @param array{message: string, signature: string}|null $billet
    @param string|null $message
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Ma démonstration · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        button { font: inherit; padding: .5rem 1rem; border-radius: .5rem; border: 0; background: #1d4ed8; color: #fff; cursor: pointer; }
        @media (prefers-color-scheme: dark) {
            body { background: #111827; color: #f9fafb; }
            main { background: #1f2937; }
        }
    </style>
    @if ($billet !== null)
        <script src="{{ asset('js/demonstration/envoyer-le-billet.js') }}" defer></script>
    @endif
</head>
<body>
<main>
    <h1>Ma démonstration</h1>
    @if ($billet !== null)
        <p>Ouverture de votre démonstration…</p>
        <form id="billet-d-entree" method="POST" action="{{ $action }}">
            <input type="hidden" name="message" value="{{ $billet['message'] }}">
            <input type="hidden" name="signature" value="{{ $billet['signature'] }}">
            <button type="submit">Ouvrir ma démonstration</button>
        </form>
    @else
        <p>{{ $message }}</p>
    @endif
</main>
    @include('pied-de-page-du-site')
</body>
</html>
