<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{{ $avoir->numero ?? 'Avoir (brouillon)' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2933; }
        .en-tete { width: 100%; margin-bottom: 24px; }
        .en-tete td { vertical-align: top; }
        .magasin-nom { font-size: 16px; font-weight: bold; }
        .titre-avoir { font-size: 20px; font-weight: bold; text-align: right; color: #b91c1c; }
        .numero-avoir { text-align: right; color: #52606d; }
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
                <div class="magasin-nom">{{ $avoir->magasin->nom }}</div>
                @if($avoir->magasin->adresse)
                    {{ $avoir->magasin->adresse }}<br>
                @endif
                @if($avoir->magasin->code_postal || $avoir->magasin->ville)
                    {{ $avoir->magasin->code_postal }} {{ $avoir->magasin->ville }}<br>
                @endif
                @if($avoir->magasin->siret)
                    SIRET : {{ $avoir->magasin->siret }}<br>
                @endif
                @if($avoir->magasin->numero_tva)
                    TVA intracommunautaire : {{ $avoir->magasin->numero_tva }}
                @endif
            </td>
            <td>
                <div class="titre-avoir">Avoir</div>
                <div class="numero-avoir">
                    {{ $avoir->numero ?? 'Brouillon (non émis)' }}<br>
                    @if($avoir->date_emission)
                        Émis le {{ $avoir->date_emission->format('d/m/Y') }}<br>
                    @endif
                    Sur facture{{ $avoir->facturesConcernees()->count() > 1 ? 's' : '' }} {{ $avoir->facturesConcernees()->pluck('numero')->implode(', ') }}
                </div>
            </td>
        </tr>
    </table>

    <div class="bloc-client">
        <div class="titre">Émis à</div>
        <strong>{{ $avoir->client->nom }}</strong><br>
        @if($avoir->client->adresse)
            {{ $avoir->client->adresse }}<br>
        @endif
        @if($avoir->client->code_postal || $avoir->client->ville)
            {{ $avoir->client->code_postal }} {{ $avoir->client->ville }}<br>
        @endif
        @if($avoir->client->siret)
            SIRET : {{ $avoir->client->siret }}
        @endif
    </div>

    <table class="lignes">
        <thead>
            <tr>
                <th>Description</th>
                <th>Facture d'origine</th>
                <th class="montant">Qté</th>
                <th class="montant">PU HT</th>
                <th class="montant">PU TTC</th>
                <th class="montant">TVA</th>
                <th class="montant">Total HT</th>
                <th class="montant">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach($avoir->lignes as $ligne)
                <tr>
                    <td>{{ $ligne->description }}</td>
                    <td>{{ $ligne->ligneFacture?->facture?->numero ?? '—' }}</td>
                    <td class="montant">{{ rtrim(rtrim($ligne->quantite, '0'), '.') }}</td>
                    <td class="montant">{{ number_format((float) $ligne->prix_unitaire_ht, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ number_format((float) $ligne->prix_unitaire_ttc, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ rtrim(rtrim($ligne->taux_tva, '0'), '.') }} %</td>
                    <td class="montant">{{ number_format((float) $ligne->montant_ht, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ number_format((float) $ligne->montant_ttc, 2, ',', ' ') }} €</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux">
        <tr>
            <td class="libelle">Total HT</td>
            <td class="montant">-{{ number_format((float) $avoir->total_ht, 2, ',', ' ') }} €</td>
        </tr>
        <tr>
            <td class="libelle">Total TVA</td>
            <td class="montant">-{{ number_format((float) $avoir->total_tva, 2, ',', ' ') }} €</td>
        </tr>
        <tr class="total-ttc">
            <td class="libelle">Total TTC</td>
            <td class="montant">-{{ number_format((float) $avoir->total_ttc, 2, ',', ' ') }} €</td>
        </tr>
    </table>

    @if($avoir->motif)
        <div class="pied">Motif : {{ $avoir->motif }}</div>
    @endif
</body>
</html>
