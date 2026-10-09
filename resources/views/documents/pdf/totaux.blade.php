{{--
    Récapitulatif par taux -- mention obligatoire dès qu'un document
    mélange plusieurs taux de TVA (voir VentileLaTvaParTaux) --, puis les
    totaux du document, précédés d'un signe moins sur un avoir.

    @param \Illuminate\Database\Eloquent\Model $document
    @param string $signe
--}}
@php
    $signe ??= '';
@endphp

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
        @foreach($document->ventilation_tva as $taux)
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
        <td class="montant">{{ $signe }}{{ number_format((float) $document->total_ht, 2, ',', ' ') }} €</td>
    </tr>
    <tr>
        <td class="libelle">Total TVA</td>
        <td class="montant">{{ $signe }}{{ number_format((float) $document->total_tva, 2, ',', ' ') }} €</td>
    </tr>
    <tr class="total-ttc">
        <td class="libelle">Total TTC</td>
        <td class="montant">{{ $signe }}{{ number_format((float) $document->total_ttc, 2, ',', ' ') }} €</td>
    </tr>
</table>
