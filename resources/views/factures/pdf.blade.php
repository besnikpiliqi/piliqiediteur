<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Une vente à un particulier porte le libellé usuel "Ticket de caisse"
        plutôt que "Facture" (voir Facture::libelleDocument()), d'après le
        type du client figé sur le document.
    --}}
    @php
        $libelleDocument = $facture->libelleDocument();
    @endphp
    <meta charset="utf-8">
    <title>{{ $facture->numero ?? "{$libelleDocument} (brouillon)" }}</title>
    @include('documents.pdf.styles')
</head>
<body>
    @include('documents.pdf.en-tete', [
        'magasin' => $facture->magasin,
        'titre' => $libelleDocument,
        'details' => array_filter([
            $facture->numero ?? 'Brouillon (non émise)',
            $facture->date_emission ? 'Émise le '.$facture->date_emission->format('d/m/Y') : null,
            $facture->date_echeance ? 'Échéance le '.$facture->date_echeance->format('d/m/Y') : null,
        ]),
    ])

    @include('documents.pdf.client', [
        'client' => $facture->copieClient(),
        'professionnel' => $facture->estPourUnProfessionnel(),
        'titre' => $facture->estPourUnProfessionnel() ? 'Facturé à' : 'Client',
    ])

    @include('documents.pdf.lignes', [
        'lignes' => $facture->lignes,
        'noteDeCalcul' => app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($facture),
    ])

    @include('documents.pdf.totaux', ['document' => $facture])

    {{--
        Un montant déjà réglé (encaissement et/ou crédit d'avoir) doit
        apparaître sur le document remis au client, pas seulement dans le
        panneau d'administration -- sans ça, rien ne prouve sur le PDF
        qu'une vente comptoir entièrement couverte par un avoir a bien été
        soldée (voir AppliquerAvoirsDisponiblesAction).
    --}}
    @php
        $montantPaye = $facture->montantPaye();
        $montantCredite = $facture->montantCrediteParAvoir();
        $montantRestant = $facture->montantRestant();
    @endphp
    @if($montantPaye > 0 || $montantCredite > 0)
        <table class="totaux" style="margin-top: 8px;">
            @if($montantPaye > 0)
                <tr>
                    <td class="libelle">Payé</td>
                    <td class="montant">{{ number_format($montantPaye, 2, ',', ' ') }} €</td>
                </tr>
            @endif
            @if($montantCredite > 0)
                <tr>
                    <td class="libelle">
                        Crédité par avoir
                        @php
                            $numerosAvoirs = $facture->allocationsAvoir->pluck('avoir.numero')->filter()->implode(', ');
                        @endphp
                        @if($numerosAvoirs)
                            ({{ $numerosAvoirs }})
                        @endif
                    </td>
                    <td class="montant">{{ number_format($montantCredite, 2, ',', ' ') }} €</td>
                </tr>
            @endif
            @if($montantRestant > 0)
                <tr>
                    <td class="libelle">Restant dû</td>
                    <td class="montant">{{ number_format($montantRestant, 2, ',', ' ') }} €</td>
                </tr>
            @endif
        </table>
    @endif

    @if($facture->conditions_paiement)
        <div class="pied">{{ $facture->conditions_paiement }}</div>
    @endif

    {{-- Entre professionnels : pénalités de retard, indemnité forfaitaire
         pour frais de recouvrement et escompte, réglés par le magasin (art.
         L441-9 du Code de commerce, voir Magasin::mentionsEntreProfessionnels()) ;
         rien sur un ticket de caisse. --}}
    @if($facture->estPourUnProfessionnel())
        @foreach($facture->magasin->mentionsEntreProfessionnels() as $mention)
            <div class="pied">{{ $mention }}</div>
        @endforeach
    @endif

    {{-- Catégorie de l'opération, franchise de TVA, option pour les débits :
         voir MentionsFiscales. --}}
    @foreach(app(\App\Domain\Facturation\Services\MentionsFiscales::class)->pour($facture) as $mention)
        <div class="pied">{{ $mention }}</div>
    @endforeach
</body>
</html>
