{{--
    Récapitulatif par taux -- la base HT et la TVA de chaque taux, mention
    obligatoire (voir VentileLaTvaParTaux) --, avec, à côté d'un taux de
    0 %, le motif qui l'explique : exonération, opération hors du champ de
    la TVA française ou franchise en base (voir
    MentionsFiscales::motifDuTauxZero()), une colonne qui n'apparaît que
    pour lui. Puis les totaux du document, précédés d'un signe moins sur un
    avoir.

    Les totaux s'impriment sur une bande d'une seule rangée de tableau, que
    dompdf ne coupe jamais entre deux pages : le tableau des totaux à
    droite, toujours à la même place, et, à sa gauche, ce qui a réglé un
    ticket ou une facture à son émission (voir documents/pdf/reglements,
    constat C3), quand on le donne ; la case de gauche d'un avoir, d'un
    devis ou d'un document sans règlement reste vide.

    @param \App\Domain\Facturation\Models\Facture|\App\Domain\Facturation\Models\Avoir|\App\Domain\Facturation\Models\Devis $document
    @param string $signe
    @param \App\Domain\Facturation\Services\ReglementsALEmission|null $reglements ce qui a réglé un ticket ou une facture à son émission
    @param \Carbon\CarbonInterface|null $dateEmission avec $reglements, la date d'émission du document
    @param \App\Domain\Calendrier\Calendrier|null $calendrier avec $reglements, celui du magasin du document
--}}
@php
    $signe ??= '';
    $ventilation = $document->ventilation_tva;
    $calculateur = app(\App\Domain\Facturation\Services\CalculateurLigne::class);
    $euros = fn (string $montant): string => $calculateur->enEuros($calculateur->enCentiemes($montant));
    $estUnTauxZero = fn (array $taux): bool => $calculateur->enCentiemes($taux['taux_tva']) === 0;
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
                <td>{{ \App\Domain\Facturation\Services\CalculateurLigne::tauxEnTexte($calculateur->enCentiemes($taux['taux_tva'])) }}</td>
                @if($avecMotif)
                    <td class="motif">{{ $estUnTauxZero($taux) ? $motif : '' }}</td>
                @endif
                <td class="montant">{{ $euros($taux['montant_ht']) }}</td>
                <td class="montant">{{ $euros($taux['montant_tva']) }}</td>
                <td class="montant">{{ $euros($taux['montant_ttc']) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="bande-des-totaux">
    <tr>
        <td class="bande-gauche">
            @isset($reglements)
                @include('documents.pdf.reglements', ['reglements' => $reglements, 'dateEmission' => $dateEmission, 'calendrier' => $calendrier])
            @endisset
        </td>
        <td class="bande-droite">
            <table class="totaux">
                <tr>
                    <td class="libelle">Total HT</td>
                    <td class="montant">{{ $signe }}{{ $euros($document->total_ht) }}</td>
                </tr>
                <tr>
                    <td class="libelle">Total TVA</td>
                    <td class="montant">{{ $signe }}{{ $euros($document->total_tva) }}</td>
                </tr>
                <tr class="total-ttc">
                    <td class="libelle">Total TTC</td>
                    <td class="montant">{{ $signe }}{{ $euros($document->total_ttc) }}</td>
                </tr>
            </table>
        </td>
    </tr>
</table>
