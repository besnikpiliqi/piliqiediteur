<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $devis->numero ?? 'Devis (brouillon)' }}</title>
    @include('documents.pdf.styles')
</head>
<body>
    @include('documents.pdf.en-tete', [
        'magasin' => $devis->magasin,
        'titre' => 'Devis',
        'details' => array_filter([
            $devis->numero ?? 'Brouillon (non envoyé)',
            $devis->date_emission ? 'Émis le '.$devis->date_emission->format('d/m/Y') : null,
            $devis->date_validite ? 'Valable jusqu\'au '.$devis->date_validite->format('d/m/Y') : null,
        ]),
    ])

    @include('documents.pdf.client', [
        'client' => \App\Domain\Facturation\Services\CopieClient::depuisClient($devis->client),
        'professionnel' => $devis->client->estProfessionnel(),
        'titre' => 'Établi pour',
    ])

    @include('documents.pdf.lignes', [
        'lignes' => $devis->lignes,
        'noteDeCalcul' => app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($devis),
    ])

    @include('documents.pdf.totaux', ['document' => $devis])

    @if($devis->conditions_paiement)
        <div class="pied">{{ $devis->conditions_paiement }}</div>
    @endif

    @if($devis->notes)
        <div class="pied">{{ $devis->notes }}</div>
    @endif

    {{-- La franchise de TVA, s'il y a lieu : voir MentionsFiscales. --}}
    @foreach(app(\App\Domain\Facturation\Services\MentionsFiscales::class)->pour($devis) as $mention)
        <div class="pied">{{ $mention }}</div>
    @endforeach

    <div class="accord">Date et signature du client, précédées de la mention « Bon pour accord »</div>
</body>
</html>
