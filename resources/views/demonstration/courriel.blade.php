{{--
    La fiche d'un e-mail qui serait parti (lot DEMO-COPIE, C.9) : le HTML
    du message dans un cadre isolé (srcdoc, sandbox sans allow-scripts ni
    allow-same-origin, liens ouverts dans un nouvel onglet), son texte et
    ses pièces jointes, qui se téléchargent, jamais affichées.

    @param \App\Domain\Demonstration\Visites\BandeauDeLaDemonstration $bandeau
    @param \App\Domain\Demonstration\Models\CourrielDeLaDemonstration $courriel
    @param string $cadre les permissions du cadre isolé
    @param string|null $htmlDuCadre le HTML du message, avec <base target="_blank">
--}}
@php
    $paris = \App\Domain\Calendrier\Calendrier::deParis();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $courriel->objet }} · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
</head>
<body class="piliqi-demonstration">
@include('demonstration.bandeau', ['bandeau' => $bandeau])
<main class="piliqi-demonstration__carte piliqi-demonstration__carte--large">
    <p><a href="{{ route('demonstration.courriels') }}">Tous les e-mails qui seraient partis</a></p>
    <h1 class="piliqi-demonstration__titre">{{ $courriel->objet }}</h1>
    <dl class="piliqi-demonstration__details">
        <dt>Envoyé le</dt><dd>{{ $paris->afficher($courriel->envoye_le, 'd/m/Y à H:i') }}</dd>
        <dt>Par</dt><dd>{{ $courriel->expediteur }}</dd>
        <dt>À</dt><dd>{{ $courriel->destinataires }}</dd>
        @if ($courriel->copies !== null)
            <dt>Copies</dt><dd>{{ $courriel->copies }}</dd>
        @endif
        @if ($courriel->compte !== null)
            <dt>Demandé par</dt><dd>{{ $courriel->compte->name }}</dd>
        @endif
    </dl>
    @if ($htmlDuCadre !== null)
        <iframe class="piliqi-demonstration__cadre" title="Le message" sandbox="{{ $cadre }}" srcdoc="{{ $htmlDuCadre }}" data-cadre-du-courriel></iframe>
    @elseif ($courriel->texte !== null)
        <pre class="piliqi-demonstration__texte">{{ $courriel->texte }}</pre>
    @endif
    @if ($courriel->pieces_jointes !== [])
        <h2 class="piliqi-demonstration__groupe">Pièces jointes</h2>
        <ul class="piliqi-demonstration__comptes">
            @foreach ($courriel->pieces_jointes as $rang => $piece)
                <li>
                    @if ($piece['contenu'] !== null)
                        <a href="{{ route('demonstration.courriel.piece', ['courriel' => $courriel->id, 'rang' => $rang]) }}" data-piece-jointe="{{ $rang }}">{{ $piece['nom'] }}</a>
                    @else
                        {{ $piece['nom'] }} — {{ \App\Http\Controllers\Demonstration\CourrielsDeLaDemonstrationController::PIECE_SANS_CONTENU }}
                    @endif
                    <span class="piliqi-demonstration__texte-gris">({{ $piece['type'] }}, {{ number_format($piece['taille'] / 1024, 0, ',', ' ') }} Ko)</span>
                </li>
            @endforeach
        </ul>
    @endif
</main>
@include('pied-de-page-du-site')
</body>
</html>
