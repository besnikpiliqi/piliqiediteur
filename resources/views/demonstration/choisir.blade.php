{{--
    « Choisissez qui vous êtes » (lot DEMO-COPIE, C.8) : la première page
    d'une visite, et celle qui suit une réfection de la copie. Les comptes
    de la seule société de la copie, groupés, le propriétaire en premier ;
    « Commencer en tant que propriétaire » ouvre le propriétaire actuel.

    @param \App\Domain\Demonstration\Visites\BandeauDeLaDemonstration $bandeau
    @param string|null $erreur le refus du dernier changement de compte
--}}
@php
    $proprietaire = $bandeau->proprietaire();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choisissez qui vous êtes · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
</head>
<body class="piliqi-demonstration">
@include('demonstration.bandeau', ['bandeau' => $bandeau])
<main class="piliqi-demonstration__carte">
    <h1 class="piliqi-demonstration__titre">Choisissez qui vous êtes</h1>
    <p>Démonstration de <strong>{{ $bandeau->societe }}</strong>, repère {{ $bandeau->repere }}, créée le {{ $bandeau->creeeLe }}, jusqu'au {{ $bandeau->fin }}.</p>
    @if ($erreur !== null)
        <p class="piliqi-demonstration__erreur" role="alert">{{ $erreur }}</p>
    @endif
    @if ($proprietaire !== null)
        <form method="post" action="{{ route('demonstration.changer-de-compte') }}">
            @csrf
            <input type="hidden" name="compte" value="{{ $proprietaire['id'] }}">
            <button type="submit" class="piliqi-demonstration__bouton" data-commencer-en-tant-que-proprietaire>Commencer en tant que propriétaire</button>
        </form>
    @endif
    @foreach ($bandeau->groupes as $groupe => $comptes)
        <h2 class="piliqi-demonstration__groupe">{{ $groupe }}</h2>
        <ul class="piliqi-demonstration__comptes">
            @foreach ($comptes as $compte)
                <li>
                    <form method="post" action="{{ route('demonstration.changer-de-compte') }}">
                        @csrf
                        <input type="hidden" name="compte" value="{{ $compte['id'] }}">
                        <button type="submit" @class(['piliqi-demonstration__compte', 'piliqi-demonstration__compte--suspendu' => $compte['suspendu']]) data-compte-du-choix="{{ $compte['id'] }}">
                            <strong>{{ $compte['nom'] }}</strong> — {{ $compte['role'] }}{{ $compte['suspendu'] ? ' (compte suspendu)' : '' }}
                        </button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endforeach
    <p class="piliqi-demonstration__texte-gris">Mot de passe de démonstration, que redemandent les gestes qui le redemandent : <code>{{ $bandeau->motDePasse }}</code>.</p>
    <p class="piliqi-demonstration__texte-gris">Ne saisissez pas de vraies données de vos clients : cette démonstration est supprimée le {{ $bandeau->fin }} ; seule la caisse en est archivée, comme la loi le demande.</p>
    @unless ($bandeau->estUnCabinet)
        <p class="piliqi-demonstration__texte-gris">Pour voir la franchise en base ou une déclaration trimestrielle, changez le régime de TVA dans les paramètres fiscaux de la société.</p>
    @endunless
</main>
@include('pied-de-page-du-site')
</body>
</html>
