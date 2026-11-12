{{--
    Un lien de réponse à un devis qui ne vaut pas, ou plus (voir
    ReponseAuDevisController) : un seul message, qui ne dit rien d'autre,
    sans aucun lien vers le reste de PILIQI.

    @param string $message
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>Devis</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
    </style>
</head>
<body>
<main>
    <p>{{ $message }}</p>
</main>
</body>
</html>
