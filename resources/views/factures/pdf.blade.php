<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Un particulier relève de l'e-reporting agrégé (clôture de caisse),
        pas d'une facture transmise individuellement (voir
        Facture::relevantDeLeInvoicing) : le document imprimé porte donc le
        libellé usuel "Ticket" plutôt que "Facture", même s'il s'agit du
        même enregistrement en base.
    --}}
    @php
        $libelleDocument = $facture->client->estProfessionnel() ? 'Facture' : 'Ticket de caisse';
    @endphp
    <meta charset="utf-8">
    <title>{{ $facture->numero ?? "{$libelleDocument} (brouillon)" }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        .en-tete { width: 100%; margin-bottom: 24px; }
        .en-tete td { vertical-align: top; }
        .magasin-nom { font-size: 16px; font-weight: bold; }
        .titre-facture { font-size: 20px; font-weight: bold; text-align: right; }
        .numero-facture { text-align: right; color: #52606d; }
        .bloc-client { margin-top: 16px; margin-bottom: 24px; }
        .bloc-client .titre { font-size: 9px; text-transform: uppercase; color: #7b8794; margin-bottom: 4px; }
        table.lignes { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.lignes th { background: #f5f7fa; text-align: left; padding: 6px 8px; font-size: 9px; text-transform: uppercase; color: #52606d; border-bottom: 1px solid #d9e2ec; }
        table.lignes td { padding: 6px 8px; border-bottom: 1px solid #e4e7eb; }
        table.lignes .montant { text-align: right; }
        table.totaux { width: 260px; margin-left: auto; border-collapse: collapse; }
        table.totaux td { padding: 4px 8px; }
        table.totaux .libelle { color: #52606d; }
        table.totaux .montant { text-align: right; }
        table.totaux .total-ttc { font-weight: bold; font-size: 13px; border-top: 1px solid #1f2933; }
        .pied { margin-top: 32px; font-size: 9px; color: #7b8794; }
    </style>
</head>
<body>
    <table class="en-tete">
        <tr>
            <td>
                <div class="magasin-nom">{{ $facture->magasin->nom }}</div>
                @if($facture->magasin->adresse)
                    {{ $facture->magasin->adresse }}<br>
                @endif
                @if($facture->magasin->code_postal || $facture->magasin->ville)
                    {{ $facture->magasin->code_postal }} {{ $facture->magasin->ville }}<br>
                @endif
                @if($facture->magasin->siret)
                    SIRET : {{ $facture->magasin->siret }}<br>
                @endif
                @if($facture->magasin->numero_tva)
                    TVA intracommunautaire : {{ $facture->magasin->numero_tva }}
                @endif
            </td>
            <td>
                <div class="titre-facture">{{ $libelleDocument }}</div>
                <div class="numero-facture">
                    {{ $facture->numero ?? 'Brouillon (non émise)' }}<br>
                    @if($facture->date_emission)
                        Émise le {{ $facture->date_emission->format('d/m/Y') }}<br>
                    @endif
                    @if($facture->date_echeance)
                        Échéance le {{ $facture->date_echeance->format('d/m/Y') }}
                    @endif
                </div>
            </td>
        </tr>
    </table>

    <div class="bloc-client">
        <div class="titre">{{ $facture->client->estProfessionnel() ? 'Facturé à' : 'Client' }}</div>
        <strong>{{ $facture->client->nom }}</strong><br>
        @if($facture->client->adresse)
            {{ $facture->client->adresse }}<br>
        @endif
        @if($facture->client->code_postal || $facture->client->ville)
            {{ $facture->client->code_postal }} {{ $facture->client->ville }}<br>
        @endif
        @if($facture->client->siret)
            SIRET : {{ $facture->client->siret }}
        @endif
    </div>

    {{-- La remise n'apparaît que si une ligne en a une : elle explique
         alors pourquoi PU x quantité diffère du total de la ligne. Une
         ligne au prix HT n'a pas de PU TTC : sa TVA se calcule sur son
         total HT (voir CalculateurLigne). --}}
    @php
        $avecRemise = $facture->lignes->contains(fn ($ligne) => (float) $ligne->remise_pourcentage != 0);
    @endphp

    <table class="lignes">
        <thead>
            <tr>
                <th>Description</th>
                <th class="montant">Qté</th>
                <th class="montant">PU HT</th>
                <th class="montant">PU TTC</th>
                <th class="montant">Taux TVA</th>
                @if($avecRemise)
                    <th class="montant">Remise</th>
                @endif
                <th class="montant">Total HT</th>
                <th class="montant">TVA</th>
                <th class="montant">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach($facture->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->description }}</td>
                    <td class="montant">{{ \Illuminate\Support\Number::format($ligne->quantite, locale: 'fr') }}</td>
                    <td class="montant">{{ number_format((float) $ligne->prix_unitaire_ht, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ $ligne->prix_unitaire_ttc === null ? '—' : number_format((float) $ligne->prix_unitaire_ttc, 2, ',', ' ').' €' }}</td>
                    <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->taux_tva, maxPrecision: 2, locale: 'fr') }} %</td>
                    @if($avecRemise)
                        <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->remise_pourcentage, maxPrecision: 2, locale: 'fr') }} %</td>
                    @endif
                    <td class="montant">{{ number_format((float) $ligne->montant_ht, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ number_format((float) $ligne->montant_tva, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ number_format((float) $ligne->montant_ttc, 2, ',', ' ') }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Récapitulatif par taux : mention obligatoire dès qu'un document
         mélange plusieurs taux de TVA (voir VentileLaTvaParTaux). --}}
    <table class="lignes">
        <thead>
            <tr>
                <th>Taux TVA</th>
                <th class="montant">Base HT</th>
                <th class="montant">TVA</th>
                <th class="montant">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach($facture->ventilation_tva as $taux)
                <tr>
                    <td>{{ \Illuminate\Support\Number::format((float) $taux['taux_tva'], maxPrecision: 2, locale: 'fr') }} %</td>
                    <td class="montant">{{ number_format($taux['montant_ht'], 2, ',', ' ') }} €</td>
                    <td class="montant">{{ number_format($taux['montant_tva'], 2, ',', ' ') }} €</td>
                    <td class="montant">{{ number_format($taux['montant_ttc'], 2, ',', ' ') }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr>
            <td class="libelle">Total HT</td>
            <td class="montant">{{ number_format((float) $facture->total_ht, 2, ',', ' ') }} €</td>
        </tr>
        <tr>
            <td class="libelle">Total TVA</td>
            <td class="montant">{{ number_format((float) $facture->total_tva, 2, ',', ' ') }} €</td>
        </tr>
        <tr class="total-ttc">
            <td class="libelle">Total TTC</td>
            <td class="montant">{{ number_format((float) $facture->total_ttc, 2, ',', ' ') }} €</td>
        </tr>
    </table>

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
