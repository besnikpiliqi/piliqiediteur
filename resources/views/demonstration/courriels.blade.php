{{--
    « E-mails qui seraient partis » (lot DEMO-COPIE, C.9) : les e-mails
    que la société de la copie aurait envoyés, du plus récent au plus
    ancien, 50 par page.

    @param \App\Domain\Demonstration\Visites\BandeauDeLaDemonstration $bandeau
    @param \Illuminate\Pagination\LengthAwarePaginator<int, \App\Domain\Demonstration\Models\CourrielDeLaDemonstration> $courriels
--}}
@php
    $paris = \App\Domain\Calendrier\Calendrier::deParis();
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-mails qui seraient partis · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app/demonstration.css') }}">
</head>
<body class="piliqi-demonstration">
@include('demonstration.bandeau', ['bandeau' => $bandeau])
<main class="piliqi-demonstration__carte piliqi-demonstration__carte--large">
    <h1 class="piliqi-demonstration__titre">E-mails qui seraient partis</h1>
    <p class="piliqi-demonstration__texte-gris">Aucun e-mail ne part de la démonstration : voici ceux que {{ $bandeau->societe }} aurait envoyés, après toutes les règles d'envoi du vrai logiciel.</p>
    @if ($courriels->isEmpty())
        <p>Aucun e-mail pour l'instant.</p>
    @else
        <div class="piliqi-demonstration__tableau">
            <table>
                <thead>
                    <tr><th scope="col">Envoyé le</th><th scope="col">Par</th><th scope="col">À</th><th scope="col">Objet</th></tr>
                </thead>
                <tbody>
                    @foreach ($courriels as $courriel)
                        <tr>
                            <td>{{ $paris->afficher($courriel->envoye_le, 'd/m/Y H:i') }}</td>
                            <td>{{ $courriel->expediteur }}</td>
                            <td>{{ $courriel->destinataires }}</td>
                            <td><a href="{{ route('demonstration.courriel', ['courriel' => $courriel->id]) }}" data-courriel="{{ $courriel->id }}">{{ $courriel->objet }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($courriels->hasPages())
            <nav class="piliqi-demonstration__boutons" aria-label="Pages">
                @if (! $courriels->onFirstPage())
                    <a class="piliqi-demonstration__bouton piliqi-demonstration__bouton--secondaire" href="{{ $courriels->previousPageUrl() }}">Page précédente</a>
                @endif
                <span>Page {{ $courriels->currentPage() }} sur {{ $courriels->lastPage() }}</span>
                @if ($courriels->hasMorePages())
                    <a class="piliqi-demonstration__bouton piliqi-demonstration__bouton--secondaire" href="{{ $courriels->nextPageUrl() }}">Page suivante</a>
                @endif
            </nav>
        @endif
    @endif
</main>
@include('pied-de-page-du-site')
</body>
</html>
