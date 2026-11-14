{{--
    La mise en page des pages d'erreur en français (voir errors/404 et
    errors/419), dans le style des pages publiques : un titre, ce qui s'est
    passé et ce qu'il faut faire, et un lien pour continuer.

    @section titre le titre de la page
    @section texte ce qui s'est passé et ce qu'il faut faire
    @section lien le lien pour continuer
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('titre') · {{ config('app.name') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f9fafb; color: #111827; margin: 0; }
        main { max-width: 32rem; margin: 2rem auto; padding: 2rem; background: #fff; border-radius: .75rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .1); }
        h1 { font-size: 1.25rem; margin-top: 0; }
        a.bouton { display: inline-block; margin-top: 1rem; background: #4f46e5; color: #fff; border-radius: .5rem; padding: .75rem 1.25rem; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <h1>@yield('titre')</h1>

    <p>@yield('texte')</p>

    @yield('lien')
</main>
</body>
</html>
