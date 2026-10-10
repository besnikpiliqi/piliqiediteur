<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $avoir->numero ?? 'Avoir (brouillon)' }}</title>
    @include('documents.pdf.styles')
</head>
<body>
    @php
        $facturesConcernees = $avoir->facturesConcernees();
    @endphp

    @include('documents.pdf.en-tete', [
        'magasin' => $avoir->magasin,
        'titre' => 'Avoir',
        'classeTitre' => 'avoir',
        'details' => array_filter([
            $avoir->numero ?? 'Brouillon (non émis)',
            $avoir->date_emission ? 'Émis le '.$avoir->date_emission->format('d/m/Y') : null,
            'Sur facture'.($facturesConcernees->count() > 1 ? 's' : '').' '.$facturesConcernees->pluck('numero')->implode(', '),
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
