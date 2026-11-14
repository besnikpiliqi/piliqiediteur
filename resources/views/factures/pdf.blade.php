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
        l'a réglée à son émission (voir ReglementsALEmission), à gauche des
        totaux, sur leur bande (voir documents/pdf/totaux).

        La facture transformée d'un devis accepté dit d'après quel devis elle
        est établie, sous ses dates (voir Facture::mentionDuDevis()).

        L'échéance s'imprime sur toute facture à un professionnel, même
        réglée à son émission (date à laquelle le règlement doit intervenir,
        art. L441-9 du Code de commerce) ; une vente à un particulier
        réglée en entier à son émission n'en imprime pas. Le bloc des
        conditions (modèle M6-b) le redit : le délai de paiement, de
        l'émission à l'échéance, et la date avant laquelle payer, ou que le
        document a été réglé à son émission.
    --}}
    @php
        $libelleDocument = $facture->libelleDocument();
        $solde = $reglements->soldentLeDocument();
        $avecEcheance = $facture->date_echeance !== null
            && ($facture->estPourUnProfessionnel() || ! $solde);
        $regle = $facture->estPourUnProfessionnel() ? 'Réglée à l\'émission' : 'Réglé à l\'émission';
        $calendrier = $facture->magasin->calendrier();
        $jours = $facture->date_echeance === null ? null : \App\Domain\Calendrier\Calendrier::joursEntre($facture->date_emission === null ? $calendrier->aujourdhui() : $calendrier->jourDe($facture->date_emission), $facture->date_echeance);
        $conditions = match (true) {
            $facture->date_echeance === null => ['Échéance fixée à l\'émission'],
            ! $avecEcheance => [$regle],
            default => [
                $jours > 0 ? "Paiement à {$jours} jours" : 'Paiement à réception',
                $solde ? $regle : 'Net à payer avant le '.\App\Domain\Calendrier\Calendrier::afficherJour($facture->date_echeance, 'd/m/Y'),
            ],
        };
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
        'classeTitre' => 'majuscules',
        'details' => array_filter([
            $facture->numero !== null ? 'N° '.$facture->numero : 'Brouillon (non émise)',
            $facture->date_emission ? 'Émise le '.$facture->magasin->calendrier()->afficher($facture->date_emission, 'd/m/Y') : null,
            $avecEcheance ? 'Échéance le '.\App\Domain\Calendrier\Calendrier::afficherJour($facture->date_echeance, 'd/m/Y') : null,
            $facture->mentionDuDevis(),
        ]),
    ])

    @include('documents.pdf.blocs', [
        'client' => $facture->copieClient(),
        'professionnel' => $facture->estPourUnProfessionnel(),
        'titre' => $facture->estPourUnProfessionnel() ? 'Facturé à' : 'Client',
        'titreDesConditions' => 'Conditions',
        'conditions' => $conditions,
    ])

    @include('documents.pdf.livraison', ['lignes' => $facture->lignesDeLivraison()])

    @include('documents.pdf.lignes', ['document' => $facture, 'tableau' => $tableau])

    @include('documents.pdf.totaux', ['document' => $facture, 'titreDuMontant' => 'Montant à régler', 'libelleDuTotal' => 'Net à payer', 'reglements' => $reglements, 'dateEmission' => $facture->date_emission, 'calendrier' => $calendrier])
</body>
</html>
