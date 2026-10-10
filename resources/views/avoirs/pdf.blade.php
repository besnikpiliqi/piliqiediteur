<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $avoir->numero ?? 'Avoir (brouillon)' }}</title>
    @include('documents.pdf.styles')
</head>
<body>
    {{-- Un avoir cite le numéro et la date d'émission de chaque document
         qu'il rectifie, une ligne chacun ; une date effacée en base, que
         signale la vérification de l'intégrité, n'empêche pas d'imprimer
         l'avoir. --}}
    @php
        $documentsCorriges = $avoir->facturesConcernees()->map(
            fn (\App\Domain\Facturation\Models\Facture $facture): string => 'Sur '.mb_strtolower($facture->libelleDocument()).' '.$facture->numero
                .($facture->date_emission ? ' du '.$facture->date_emission->format('d/m/Y') : ''),
        );
    @endphp

    @include('documents.pdf.en-tete', [
        'magasin' => $avoir->magasin,
        'titre' => 'Avoir',
        'classeTitre' => 'avoir',
        'details' => array_filter([
            $avoir->numero ?? 'Brouillon (non émis)',
            $avoir->date_emission ? 'Émis le '.$avoir->date_emission->format('d/m/Y') : null,
            ...$documentsCorriges,
        ]),
    ])

    @include('documents.pdf.client', [
        'client' => $avoir->copieClient(),
        'professionnel' => $avoir->estPourUnProfessionnel(),
        'titre' => 'Émis à',
    ])

    @include('documents.pdf.lignes', [
        'lignes' => $avoir->lignes,
        'avecFactureDOrigine' => true,
        'noteDeCalcul' => app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($avoir),
    ])

    @include('documents.pdf.totaux', ['document' => $avoir, 'signe' => '-'])

    @if($avoir->motif)
        <div class="pied">Motif : {{ $avoir->motif }}</div>
    @endif

    {{-- Un avoir rectifie une facture : il en reprend les mentions (voir
         MentionsFiscales). --}}
    @foreach(app(\App\Domain\Facturation\Services\MentionsFiscales::class)->pour($avoir) as $mention)
        <div class="pied">{{ $mention }}</div>
    @endforeach
</body>
</html>
