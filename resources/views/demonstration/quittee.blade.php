{{--
    La page qui suit « Quitter » (lot DEMO-COPIE, C.8) : le témoin de ce
    navigateur est retiré, la session fermée ; le lien de la démonstration
    marche toujours jusqu'à sa fin.

    @param string $fin la date de fin de la copie
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vous avez quitté la démonstration · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
</head>
<body class="piliqi-demonstration">
<main class="piliqi-demonstration__carte">
    <h1 class="piliqi-demonstration__titre">Vous avez quitté la démonstration</h1>
    <p>Votre lien marche toujours jusqu'au {{ $fin }}.</p>
</main>
@include('pied-de-page-du-site')
</body>
</html>
