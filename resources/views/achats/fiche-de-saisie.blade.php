<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        La fiche de saisie d'une facture d'achat ou d'un avoir fournisseur
        sans pièce du fournisseur (voir FicheDeSaisiePdfGenerator) : sa
        marque « Saisie PILIQI », le magasin en tête, le fournisseur, chaque
        ligne, la TVA de chaque taux et les totaux tels qu'enregistrés.

        @param \App\Domain\Facturation\Services\Emetteur $emetteur
        @param \App\Domain\Facturation\Services\PiedDePage $pied
        @param string $marque
        @param string $titre
        @param list<string> $details
        @param list<string> $fournisseur
        @param list<array{reference: string|null, description: string, quantite: int, prix_unitaire_ht: string, taux: string, montant_ht: string}> $lignes
        @param list<array{taux: string, base_ht: string, montant_tva: string}> $taux
        @param list<array{libelle: string, montant: string}> $totaux
        @param bool $tvaAVerifier
    --}}
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    @include('documents.pdf.styles', ['pied' => $pied])
</head>
<body>
    @include('documents.pdf.pied', ['pied' => $pied])

    <div class="bandeau-demonstration">{{ $marque }}</div>

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => $titre,
        'details' => $details,
    ])

    <div class="bloc-client">
        <div class="titre">Fournisseur</div>
        {!! collect($fournisseur)->map(fn (string $ligne): string => e($ligne))->implode('<br>') !!}
    </div>

    <table class="documents-regles">
        <thead>
            <tr>
                <th>Référence</th>
                <th>Désignation</th>
                <th class="montant">Quantité</th>
                <th class="montant">PU HT</th>
                <th class="montant">TVA</th>
                <th class="montant">Total HT</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lignes as $ligne)
                <tr>
                    <td class="insecable">{{ $ligne['reference'] ?? '' }}</td>
                    <td>{{ $ligne['description'] }}</td>
                    <td class="montant">{{ $ligne['quantite'] }}</td>
                    <td class="montant">{{ $ligne['prix_unitaire_ht'] }}</td>
                    <td class="montant">{{ $ligne['taux'] }}</td>
                    <td class="montant">{{ $ligne['montant_ht'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="tva">
        <thead>
            <tr>
                <th>Taux</th>
                <th class="montant">Base HT</th>
                <th class="montant">TVA</th>
            </tr>
        </thead>
        <tbody>
            @foreach($taux as $unTaux)
                <tr>
                    <td>{{ $unTaux['taux'] }}</td>
                    <td class="montant">{{ $unTaux['base_ht'] }}</td>
                    <td class="montant">{{ $unTaux['montant_tva'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totaux" style="width: 260px; margin-left: auto;">
        @foreach($totaux as $total)
            <tr>
                <td class="libelle">{{ $total['libelle'] }}</td>
                <td class="montant">{{ $total['montant'] }}</td>
            </tr>
        @endforeach
    </table>

    @if($tvaAVerifier)
        <div class="remarque">TVA à vérifier : un montant saisi s'écarte de ce que PILIQI calcule au-delà de la tolérance, et l'écart a été confirmé.</div>
    @endif
</body>
</html>
