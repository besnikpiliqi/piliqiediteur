{{--
    La confirmation d'une inscription (voir ConfirmerInscriptionController) :
    l'ouverture du lien ne consomme rien, seul le bouton confirme. Un lien
    expiré, déjà servi ou inconnu dit seulement qu'il n'est plus valable.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Confirmer l'inscription · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 32rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        button { background: #4f46e5; color: #fff; border: 0; border-radius: .5rem; padding: .75rem 1.25rem; font-size: 1rem; cursor: pointer; }
        .refus { color: #b91c1c; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>

    @if ($valable)
        <p>Confirmez l'inscription de votre société : votre société et votre compte seront créés, et vous serez connecté à l'espace de votre société, où vous créerez vos magasins.</p>

        @if ($refus !== null)
            <p class="refus">{{ $refus }}</p>
        @endif

        <form method="POST" action="{{ $action }}">
            @csrf
            <button type="submit">Confirmer l'inscription</button>
        </form>
    @else
        <p class="refus">{{ \App\Http\Controllers\ConfirmerInscriptionController::MESSAGE_LIEN_INVALIDE }}</p>
    @endif
</main>
</body>
</html>
