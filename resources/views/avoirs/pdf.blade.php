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

    @include('documents.pdf.client', ['client' => $avoir->client, 'titre' => 'Émis à'])

    @include('documents.pdf.lignes', ['lignes' => $avoir->lignes, 'avecFactureDOrigine' => true])

    @include('documents.pdf.totaux', ['document' => $avoir, 'signe' => '-'])

    @if($avoir->motif)
        <div class="pied">Motif : {{ $avoir->motif }}</div>
    @endif

    <div class="pied">Catégorie de l'opération : {{ \App\Domain\Facturation\Enums\CategorieOperation::depuisNatures($avoir->lignes->pluck('nature'))->getLabel() }}</div>

    {{-- Un avoir rectifie une facture : il en reprend les mentions
         obligatoires, dont celle-ci (voir factures/pdf.blade.php). --}}
    @if($avoir->magasin->option_debits_tva)
        <div class="pied">Option pour le paiement de la taxe d'après les débits</div>
    @endif
</body>
</html>
