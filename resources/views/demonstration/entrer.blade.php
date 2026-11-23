{{--
    La page du lien d'une démonstration (/d, voir
    EntrerDansLaDemonstrationController) : le secret est après « # », que le
    navigateur n'envoie jamais au serveur ; le seul fichier
    /js/demonstration/entrer-dans-la-demonstration.js le lit, l'envoie par
    POST /entrer, puis efface l'adresse. Aucun script ni style écrit dans la
    page : sa politique de contenu ne permet que les fichiers du site.
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>Votre démonstration · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
    <script src="{{ asset('js/demonstration/entrer-dans-la-demonstration.js') }}" defer></script>
</head>
<body class="piliqi-demonstration">
<main class="piliqi-demonstration__carte">
    <h1 class="piliqi-demonstration__titre">Votre démonstration</h1>
    <p id="etat" role="status">Ouverture de votre démonstration…</p>
    <div id="question" hidden>
        <p id="texte-de-la-question"></p>
        <div class="piliqi-demonstration__boutons">
            <button type="button" id="ouvrir-celle-ci" class="piliqi-demonstration__bouton">Ouvrir celle de ce lien</button>
            <button type="button" id="garder-l-autre" class="piliqi-demonstration__bouton piliqi-demonstration__bouton--secondaire">Rester dans l'autre</button>
        </div>
    </div>
    <noscript><p>Cette page a besoin de JavaScript pour ouvrir votre démonstration.</p></noscript>
</main>
@include('pied-de-page-du-site')
</body>
</html>
