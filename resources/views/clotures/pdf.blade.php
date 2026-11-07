<!DOCTYPE html>
<html lang="fr">
<head>
    {{--
        Le ticket Z d'une clôture du jour (voir ClotureCaissePdfGenerator) :
        le magasin en tête, son numéro, sa journée, l'instant de la clôture
        et qui l'a déclenchée ; son récapitulatif tel qu'écrit à la clôture,
        rubrique par rubrique avec le détail de chaque taux, ses
        encaissements par mode, le crédit d'avoir utilisé à part et ses
        grands totaux ; l'identité de la société dans le pied de chaque page.

        @param \App\Domain\Facturation\Models\ClotureCaisse $cloture
        @param \App\Domain\Facturation\Services\Emetteur $emetteur
        @param \App\Domain\Facturation\Services\PiedDePage $pied
        @param list<string> $details
        @param list<array{rubrique: string, nombre: int|null, ht: string, tva: string, ttc: string, detail: bool}> $rubriques
        @param list<array{libelle: string, paiements: int, contre_passations: int, montant: string}> $encaissements
        @param string $totalEncaisse
        @param string $creditAvoir
        @param string $grandTotalReel
        @param string $grandTotalAbsolu
    --}}
    <meta charset="utf-8">
    <title>{{ $cloture->numero }}</title>
    @include('documents.pdf.styles', ['pied' => $pied])
</head>
<body>
    @include('documents.pdf.pied', ['pied' => $pied])

    @include('documents.pdf.en-tete', [
        'emetteur' => $emetteur,
        'titre' => 'Clôture du jour (ticket Z)',
        'details' => $details,
    ])

    <table class="documents-regles">
        <thead>
            <tr>
                <th>Ventes et avoirs</th>
                <th class="montant">Nombre</th>
                <th class="montant">Total HT</th>
                <th class="montant">TVA</th>
                <th class="montant">Total TTC</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rubriques as $rubrique)
                <tr>
                    <td @if($rubrique['detail']) style="padding-left: 16px; color: #52606d;" @endif>{{ $rubrique['rubrique'] }}</td>
                    <td class="montant">{{ $rubrique['nombre'] ?? '' }}</td>
                    <td class="montant">{{ $rubrique['ht'] }}</td>
                    <td class="montant">{{ $rubrique['tva'] }}</td>
                    <td class="montant">{{ $rubrique['ttc'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="documents-regles">
        <thead>
            <tr>
                <th>Encaissements par mode</th>
                <th class="montant">Paiements</th>
                <th class="montant">Contre-passations</th>
                <th class="montant">Montant encaissé</th>
            </tr>
        </thead>
        <tbody>
            @foreach($encaissements as $mode)
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
                <td class="montant"><strong>{{ $totalEncaisse }}</strong></td>
            </tr>
        </tbody>
    </table>

    <table class="documents-regles">
        <tbody>
            <tr>
                <td>Crédit d'avoir utilisé (n'entre pas dans les encaissements)</td>
                <td class="montant">{{ $creditAvoir }}</td>
            </tr>
            <tr>
                <td>Grand total perpétuel réel</td>
                <td class="montant">{{ $grandTotalReel }}</td>
            </tr>
            <tr>
                <td>Grand total perpétuel absolu</td>
                <td class="montant">{{ $grandTotalAbsolu }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
