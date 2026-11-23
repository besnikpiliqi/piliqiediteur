{{--
    Le lien d'une invitation d'un cabinet, ouvert dans la copie de ce
    cabinet sur le site de démonstration (lot DEMO-COPIE, choix 27 et 30,
    voir AccepterLInvitationDuCabinetController) : ce que l'entreprise
    invitée en ferait, sans formulaire ni aucun type d'activité ; aucune
    société ne se crée, personne ne se connecte, l'invitation reste ouverte.

    @param string $cabinet la dénomination du cabinet qui invite
    @param string $siren le SIREN invité
    @param string $choix l'adresse de « Choisissez qui vous êtes »
--}}
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Le lien de l'invitation · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
</head>
<body class="piliqi-demonstration">
<main class="piliqi-demonstration__carte">
    <h1 class="piliqi-demonstration__titre">L'entreprise invitée recevrait ce lien</h1>
    <p>Avec PILIQI, l'entreprise de SIREN {{ $siren }} ouvre ce lien pour accepter l'invitation de {{ $cabinet }} : elle choisit son mot de passe, sa société est créée et validée sans courrier, et votre cabinet la suit selon les autorisations qu'elle lui accorde.</p>
    <p class="piliqi-demonstration__texte-gris">Dans votre démonstration, ce lien ne crée aucune société et ne connecte personne : l'invitation reste ouverte dans l'espace de votre cabinet, où vous pouvez la retirer.</p>
    <p><a href="{{ $choix }}">Revenir à la démonstration</a></p>
</main>
@include('pied-de-page-du-site')
</body>
</html>
