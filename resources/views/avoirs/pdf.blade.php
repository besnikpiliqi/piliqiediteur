<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $avoir->numero ?? 'Avoir (brouillon)' }}</title>
    @include('documents.pdf.styles', ['pied' => $pied, 'tableau' => $tableau])
</head>
<body>
    {{-- Un avoir cite le numéro et la date d'émission de chaque document
         qu'il rectifie, une ligne chacun ; une date effacée en base, que
         signale la vérification de l'intégrité, n'empêche pas d'imprimer
         l'avoir. $emetteur : celui que l'avoir a signé (voir
         AvoirPdfGenerator et FigeSonEmetteur) ; $pied : l'identité de la
         société, les mentions de la facture qu'il rectifie et, pour le
         client comptoir, la mention de son bon d'avoir (voir PiedDePage) ;
         $tableau : ses lignes, mesurées pour tenir dans la largeur de la
         page (voir TableauDesLignes). --}}
    @php
        $documentsCorriges = $avoir->facturesConcernees()->map(
            fn (\App\Domain\Facturation\Models\Facture $facture): string => 'Sur '.mb_strtolower($facture->libelleDocument()).' '.$facture->numero
                .($facture->date_emission ? ' du '.$avoir->magasin->calendrier()->afficher($facture->date_emission, 'd/m/Y') : ''),
        );
    @endphp

    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => 'Avoir',
        'classeTitre' => 'avoir',
        'details' => array_filter([
            $avoir->numero ?? 'Brouillon (non émis)',
            $avoir->date_emission ? 'Émis le '.$avoir->magasin->calendrier()->afficher($avoir->date_emission, 'd/m/Y') : null,
            ...$documentsCorriges,
        ]),
    ])

    @include('documents.pdf.client', [
        'client' => $avoir->copieClient(),
        'professionnel' => $avoir->estPourUnProfessionnel(),
        'titre' => 'Émis à',
    ])

    @include('documents.pdf.lignes', ['document' => $avoir, 'tableau' => $tableau])

    @include('documents.pdf.totaux', ['document' => $avoir, 'signe' => '-'])

    @if($avoir->motif)
        <div class="remarque">Motif : {{ $avoir->motif }}</div>
    @endif
</body>
</html>
