{{--
    La réponse du client à un devis, enregistrée par son lien (voir
    ReponseAuDevisController) : ce qui a été enregistré et quand, à l'heure
    du magasin ; le lien ne vaut plus. Aucun lien vers le reste de PILIQI.

    @param string $numero
    @param bool $acceptation
    @param string $quand
    @param string $heure
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Devis {{ $numero }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
    </style>
</head>
<body>
<main>
    @if ($acceptation)
        <h1>Merci : votre acceptation est enregistrée</h1>
        <p>Votre acceptation du devis {{ $numero }} a été enregistrée le {{ $quand }} ({{ $heure }}). Le magasin en est prévenu, et une confirmation vous est envoyée par e-mail avec le devis accepté.</p>
    @else
        <h1>Votre refus est enregistré</h1>
        <p>Votre refus du devis {{ $numero }} a été enregistré le {{ $quand }} ({{ $heure }}). Le magasin en est prévenu, et une confirmation vous est envoyée par e-mail.</p>
    @endif
    <p>Ce lien ne vaut plus : pour toute question, répondez à l'e-mail du magasin.</p>
</main>
</body>
</html>
