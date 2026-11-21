<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Le reçu de paiement d'un encaissement fait après l'émission d'une
        facture ou d'un ticket, ou d'une contre-passation (voir
        RecuPaiementPdfGenerator) : le magasin qui encaisse en tête, tel que
        le reçu l'a figé le jour de l'encaissement, avec le titre, la
        référence du reçu et ce jour ; le client du premier document réglé,
        tel que ce document l'a figé ; chaque part et chaque crédit d'avoir
        (voir TableauDesReglements), le total encaissé ; chaque document
        réglé, ce qui lui est imputé et ce qui en restait dû ensuite ; la
        mention qu'il n'est pas une facture ; l'identité de la société dans
        le pied de chaque page (voir PiedDePage::pourUnRecu()).

        @param \App\Domain\Facturation\Models\RecuPaiement $recu
        @param \App\Domain\Facturation\Services\Emetteur $emetteur
        @param \App\Domain\Facturation\Services\PiedDePage $pied
        @param string $date le jour de l'encaissement, « JJ/MM/AAAA »
        @param \App\Domain\Facturation\Services\TableauDesReglements $reglements
        @param list<array{libelle: string, montant: string}> $totaux
        @param list<array{numero: string, libelle: string, impute: string, reste_du: string}> $documents
    --}}
    <meta charset="utf-8">
    <title>{{ $recu->numero }}</title>
    @include('documents.pdf.styles', ['pied' => $pied])
</head>
<body>
    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => $recu->libelleDocument(),
        'details' => [
            $recu->numero,
            ($recu->nature === \App\Domain\Facturation\Enums\NatureRecuPaiement::ContrePassation ? 'Contre-passation du ' : 'Encaissement du ').$date,
        ],
    ])

    @include('documents.pdf.client', [
        'client' => $recu->copieClient(),
        'professionnel' => $recu->pourUnProfessionnel(),
        'titre' => 'Client',
    ])

    <div class="reglement-du-recu">
        @include('documents.pdf.tableau-des-reglements', [
            'titre' => $recu->nature === \App\Domain\Facturation\Enums\NatureRecuPaiement::ContrePassation ? 'Paiement contre-passé' : 'Règlement',
            'tableau' => $reglements,
            'totaux' => $totaux,
        ])
    </div>

    <table class="documents-regles">
        <thead>
            <tr>
                <th>Document réglé</th>
                <th class="montant">Montant imputé</th>
                <th class="montant">Restant dû après cet encaissement</th>
            </tr>
        </thead>
        <tbody>
            @foreach($documents as $document)
                <tr>
                    <td>{{ $document['libelle'] }} {{ $document['numero'] }}</td>
                    <td class="montant">{{ $document['impute'] }}</td>
                    <td class="montant">{{ $document['reste_du'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mention-du-recu">{{ \App\Domain\Facturation\Models\RecuPaiement::MENTION }}</div>
</body>
</html>
