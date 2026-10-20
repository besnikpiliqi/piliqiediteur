{{--
    Récapitulatif par taux -- la base HT et la TVA de chaque taux, mention
    obligatoire (voir VentileLaTvaParTaux) --, avec, à côté d'un taux de
    0 %, le motif qui l'explique : exonération, opération hors du champ de
    la TVA française ou franchise en base (voir
    MentionsFiscales::motifDuTauxZero()), une colonne qui n'apparaît que
    pour lui. Puis les totaux du document, précédés d'un signe moins sur un
    avoir.

    @param \App\Domain\Facturation\Models\Facture|\App\Domain\Facturation\Models\Avoir|\App\Domain\Facturation\Models\Devis $document
    @param string $signe
--}}
@php
    $signe ??= '';
    $ventilation = $document->ventilation_tva;
    $estUnTauxZero = fn (array $taux): bool => app(\App\Domain\Tva\Services\CalculateurLigne::class)->enCentiemes($taux['taux_tva']) === 0;
    $motif = app(\App\Domain\Facturation\Services\MentionsFiscales::class)->motifDuTauxZero($document);
    $avecMotif = $motif !== null && collect($ventilation)->contains($estUnTauxZero);
@endphp

<table class="tva">
    <thead>
        <tr>
            <th>Taux TVA</th>
            @if($avecMotif)
                <th>Motif</th>
            @endif
            <th class="montant">Base HT</th>
            <th class="montant">TVA</th>
            <th class="montant">Total TTC</th>
        </tr>
    </thead>
    <tbody>
        @foreach($ventilation as $taux)
            <tr>
                <td>{{ \Illuminate\Support\Number::format((float) $taux['taux_tva'], maxPrecision: 2, locale: 'fr') }} %</td>
                @if($avecMotif)
                    <td class="motif">{{ $estUnTauxZero($taux) ? $motif : '' }}</td>
                @endif
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
