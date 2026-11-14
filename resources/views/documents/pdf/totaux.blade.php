{{--
    Le bas d'un document de vente, au modèle M6-b (choix de l'éditeur du
    14/11/2026, le même pour un particulier, modèle P1), sur une bande d'une
    seule rangée de tableau, que dompdf ne coupe jamais entre deux pages :

    - à gauche, le récapitulatif par taux -- la base HT, la TVA et le TTC de
      chaque taux, mention obligatoire (voir VentileLaTvaParTaux) --, avec,
      à côté d'un taux de 0 %, le motif qui l'explique : exonération,
      opération hors du champ de la TVA française ou franchise en base (voir
      MentionsFiscales::motifDuTauxZero()), une colonne qui n'apparaît que
      pour lui ; puis, sous lui, ce qui a réglé un ticket ou une facture à
      son émission (voir documents/pdf/reglements, constat C3), quand on le
      donne ;
    - à droite, toujours à la même place, le montant du document : son total
      HT, sa TVA et son total TTC, le dernier en gras, précédés d'un signe
      moins sur un avoir.

    @param \App\Domain\Facturation\Models\Facture|\App\Domain\Facturation\Models\Avoir|\App\Domain\Facturation\Models\Devis $document
    @param string $signe
    @param string $titreDuMontant le titre du bloc de droite : « Montant à régler », « Montant de l'avoir », « Montant du devis »
    @param string $libelleDuTotal le libellé du total TTC : « Net à payer » sur une facture ou un ticket, « Total TTC » sinon
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

<table class="bande-des-totaux">
    <tr>
        <td class="bande-gauche">
            <div class="recapitulatif">
                <div class="titre-de-boite">Récapitulatif TVA</div>
                <table class="tva">
                    <thead>
                        <tr>
                            <th>Taux</th>
                            @if($avecMotif)
                                <th>Motif</th>
                            @endif
                            <th class="montant">Base HT</th>
                            <th class="montant">TVA</th>
                            <th class="montant">TTC</th>
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
            </div>
            @isset($reglements)
                @include('documents.pdf.reglements', ['reglements' => $reglements, 'dateEmission' => $dateEmission, 'calendrier' => $calendrier])
            @endisset
        </td>
        <td class="bande-droite">
            <div class="a-regler">
                <div class="titre-de-boite">{{ $titreDuMontant }}</div>
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
                        <td class="libelle">{{ $libelleDuTotal }}</td>
                        <td class="montant">{{ $signe }}{{ $euros($document->total_ttc) }}</td>
                    </tr>
                </table>
            </div>
        </td>
    </tr>
</table>
