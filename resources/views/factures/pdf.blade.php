<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Une vente à un particulier porte le libellé usuel "Ticket de caisse"
        plutôt que "Facture" (voir Facture::libelleDocument()), d'après le
        type du client figé sur le document. $emetteur : celui que la
        facture a signé une fois émise, la société et le magasin
        d'aujourd'hui pour un brouillon (voir FacturePdfGenerator et
        FigeSonEmetteur) ; $pied : l'identité de la société, les conditions
        de paiement et les mentions, en bas de chaque page (voir
        PiedDePage) ; $tableau : ses lignes, mesurées pour tenir dans la
        largeur de la page (voir TableauDesLignes) ; $reglements : ce qui
        l'a réglée à son émission (voir ReglementsALEmission).

        L'échéance s'imprime sur toute facture à un professionnel, même
        réglée à son émission (date à laquelle le règlement doit intervenir,
        art. L441-9 du Code de commerce) ; une vente à un particulier
        réglée en entier à son émission n'en imprime pas.
    --}}
    @php
        $libelleDocument = $facture->libelleDocument();
        $avecEcheance = $facture->date_echeance !== null
            && ($facture->estPourUnProfessionnel() || ! $reglements->soldentLeDocument());
    @endphp
    <meta charset="utf-8">
    <title>{{ $facture->numero ?? "{$libelleDocument} (brouillon)" }}</title>
    @include('documents.pdf.styles', ['pied' => $pied, 'tableau' => $tableau])
</head>
<body>
    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'estUnTicket' => ! $facture->estPourUnProfessionnel(),
        'titre' => $libelleDocument,
        'details' => array_filter([
            $facture->numero ?? 'Brouillon (non émise)',
            $facture->date_emission ? 'Émise le '.$facture->magasin->calendrier()->afficher($facture->date_emission, 'd/m/Y') : null,
            $avecEcheance ? 'Échéance le '.\App\Domain\Calendrier\Calendrier::afficherJour($facture->date_echeance, 'd/m/Y') : null,
        ]),
    ])

    @include('documents.pdf.client', [
        'client' => $facture->copieClient(),
        'professionnel' => $facture->estPourUnProfessionnel(),
        'titre' => $facture->estPourUnProfessionnel() ? 'Facturé à' : 'Client',
    ])

    @include('documents.pdf.livraison', ['lignes' => $facture->lignesDeLivraison()])

    @include('documents.pdf.lignes', ['document' => $facture, 'tableau' => $tableau])

    @include('documents.pdf.totaux', ['document' => $facture])

    @include('documents.pdf.reglements', ['reglements' => $reglements, 'dateEmission' => $facture->date_emission, 'calendrier' => $facture->magasin->calendrier()])
</body>
</html>
