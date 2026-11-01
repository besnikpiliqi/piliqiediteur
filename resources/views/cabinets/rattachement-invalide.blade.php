{{--
    Le lien d'une demande de rattachement d'un cabinet d'expertise
    comptable qui ne vaut plus, ou ouvert par un autre compte que le
    propriétaire de l'entreprise visée (voir RattachementAuCabinetController) :
    un seul message, qui ne nomme pas la société.

    @param string $message
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>{{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 40rem; margin: 4rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name') }}</h1>
    <p>{{ $message }}</p>
</main>
</body>
</html>
