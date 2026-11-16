{{--
    Les pages d'attente d'une copie du site de démonstration (voir
    OuvrirLaCopie) : jamais une erreur technique. Une copie qui se prépare,
    qui attend une place ou qui se met à jour se relit seule ; une copie
    terminée le dit.

    @param string $cas se_prepare, en_attente_d_une_place, mise_a_jour ou terminee
--}}
@php
    $textes = [
        'se_prepare' => ['Votre démonstration se prépare', 'Cette page se relit seule dans quelques secondes.'],
        'en_attente_d_une_place' => ['Votre démonstration sera prête dès qu\'une place se libère', 'Cette page se relit seule ; vous pouvez aussi revenir plus tard par le même lien.'],
        'mise_a_jour' => ['Mise à jour en cours, une minute', 'Cette page se relit seule.'],
        'terminee' => ['Cette démonstration est terminée', 'Le propriétaire de la société peut en créer une nouvelle depuis son compte.'],
    ];
    [$titre, $phrase] = $textes[$cas];
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @if ($cas !== 'terminee')
        <meta http-equiv="refresh" content="15">
    @endif
    <title>{{ $titre }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
</head>
<body class="piliqi-demonstration">
<main class="piliqi-demonstration__carte">
    <h1 class="piliqi-demonstration__titre">{{ $titre }}</h1>
    <p class="piliqi-demonstration__texte-gris">{{ $phrase }}</p>
</main>
@include('pied-de-page-du-site')
</body>
</html>
