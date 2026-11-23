{{--
    Un tableau de règlements (voir TableauDesReglements), sous son titre :
    une rangée par règlement, en colonnes Date, Mode, Référence et Montant,
    puis chaque total, en gras, son libellé sur les trois premières
    colonnes. Une référence s'imprime mot par mot : un mot qui tient dans sa
    colonne d'un seul tenant, la référence ne passant à la ligne qu'entre
    deux mots ; un mot plus long que la colonne peut seul se couper. Le titre
    est hors du tableau : en disposition fixe, dompdf prend les largeurs de
    sa première rangée, et un titre sur toutes les colonnes les rendrait
    égales.

    @param string $titre
    @param \App\Domain\Facturation\Services\TableauDesReglements $tableau
    @param list<array{libelle: string, montant: string}> $totaux
--}}
<div class="titre-reglements">{{ $titre }}</div>
<table class="reglements">
    <thead>
        <tr class="entetes">
            <th class="date">Date</th>
            <th class="mode">Mode</th>
            <th class="reference">Référence</th>
            <th class="montant">Montant</th>
        </tr>
    </thead>
    <tbody>
        @foreach($tableau->rangees as $rangee)
            <tr>
                <td class="date">{{ $rangee['date'] }}</td>
                <td class="mode">{{ $rangee['mode'] }}</td>
                <td class="reference">@foreach($rangee['mots'] as $mot)<span @class(['insecable' => $mot['insecable']])>{{ $mot['texte'] }}</span>{{ $loop->last ? '' : ' ' }}@endforeach</td>
                <td class="montant">{{ $rangee['montant'] }}</td>
            </tr>
        @endforeach
        @foreach($totaux as $total)
            <tr class="total">
                <td class="libelle" colspan="3">{{ $total['libelle'] }}</td>
                <td class="montant">{{ $total['montant'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
