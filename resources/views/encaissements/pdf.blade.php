<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Le relevé des encaissements d'un magasin pour un mois (voir
        ReleveDesEncaissementsPdfGenerator) : le magasin en tête, le mois et
        les jours qu'il couvre ; les encaissements par mode,
        contre-passations déduites, et leur total ; le crédit d'avoir utilisé,
        à part ; chaque paiement ; l'identité de la société dans le pied de
        chaque page.

        @param \App\Domain\Facturation\Services\Emetteur $emetteur
        @param \App\Domain\Facturation\Services\PiedDePage $pied
        @param string $titre
        @param list<string> $details
        @param list<array{libelle: string, paiements: int, contre_passations: int, montant: string}> $modes
        @param string $total
        @param string $credit
        @param list<array{jour: string, mode: string, reference: string|null, documents: string, montant: string}> $paiements
    --}}
    <meta charset="utf-8">
    <title>{{ $titre }}</title>
    @include('documents.pdf.styles', ['pied' => $pied])
</head>
<body>
    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => $titre,
        'details' => $details,
    ])

    <table class="documents-regles">
        <thead>
            <tr>
                <th>Mode de paiement</th>
                <th class="montant">Paiements</th>
                <th class="montant">Contre-passations</th>
                <th class="montant">Montant encaissé</th>
            </tr>
        </thead>
        <tbody>
            @foreach($modes as $mode)
                <tr>
                    <td>{{ $mode['libelle'] }}</td>
                    <td class="montant">{{ $mode['paiements'] }}</td>
                    <td class="montant">{{ $mode['contre_passations'] }}</td>
                    <td class="montant">{{ $mode['montant'] }}</td>
                </tr>
            @endforeach
            <tr>
                <td><strong>Total encaissé</strong></td>
                <td></td>
                <td></td>
                <td class="montant"><strong>{{ $total }}</strong></td>
            </tr>
            <tr>
                <td>Crédit d'avoir utilisé (n'entre pas dans les encaissements)</td>
                <td></td>
                <td></td>
                <td class="montant">{{ $credit }}</td>
            </tr>
        </tbody>
    </table>

    <table class="documents-regles">
        <thead>
            <tr>
                <th>Jour</th>
                <th>Mode</th>
                <th>Référence</th>
                <th>Documents réglés</th>
                <th class="montant">Montant</th>
            </tr>
        </thead>
        <tbody>
            @foreach($paiements as $paiement)
                <tr>
                    <td class="insecable">{{ $paiement['jour'] }}</td>
                    <td>{{ $paiement['mode'] }}</td>
                    <td>{{ $paiement['reference'] ?? '' }}</td>
                    <td>{{ $paiement['documents'] }}</td>
                    <td class="montant">{{ $paiement['montant'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
