<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Un particulier relève de l'e-reporting agrégé (clôture de caisse) :
        son document porte le libellé usuel "Ticket de caisse" plutôt que
        "Facture" (voir Facture::libelleDocument()).
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
        'client' => $facture->client,
        'titre' => $facture->client->estProfessionnel() ? 'Facturé à' : 'Client',
    ])

    @include('documents.pdf.lignes', ['lignes' => $facture->lignes])

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

    {{-- Mention obligatoire (art. 242 nonies A de l'annexe II au CGI) :
         tirée de la nature des lignes, voir NatureLigne. --}}
    <div class="pied">Catégorie de l'opération : {{ \App\Domain\Facturation\Enums\CategorieOperation::depuisNatures($facture->lignes->pluck('nature'))->getLabel() }}</div>

    @if($facture->conditions_paiement)
        <div class="pied">{{ $facture->conditions_paiement }}</div>
    @endif

    {{-- Mention obligatoire quand le magasin a opté pour la TVA d'après les
         débits (art. 242 nonies A de l'annexe II au CGI, voir
         ParametresFiscaux). --}}
    @if($facture->magasin->option_debits_tva)
        <div class="pied">Option pour le paiement de la taxe d'après les débits</div>
    @endif
</body>
</html>
