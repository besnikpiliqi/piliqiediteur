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

    @include('documents.pdf.client', ['client' => $devis->client, 'titre' => 'Établi pour'])

    @include('documents.pdf.lignes', ['lignes' => $devis->lignes])

    @include('documents.pdf.totaux', ['document' => $devis])

    @if($devis->conditions_paiement)
        <div class="pied">{{ $devis->conditions_paiement }}</div>
    @endif

    @if($devis->notes)
        <div class="pied">{{ $devis->notes }}</div>
    @endif

    <div class="accord">Date et signature du client, précédées de la mention « Bon pour accord »</div>
</body>
</html>
