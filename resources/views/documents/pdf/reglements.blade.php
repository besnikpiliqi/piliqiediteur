{{--
    Le bloc « Règlements » d'un ticket ou d'une facture (points 3 et 23,
    Q5) : ce qui l'a réglé à son émission, tel que son contenu signé l'a
    retenu (voir ReglementsALEmission) -- chaque paiement avec sa date, son
    mode, sa référence et son montant, puis chaque crédit d'avoir avec le
    numéro de l'avoir, puis ce qui restait dû. Un paiement reçu ensuite
    reste sur la fiche du document : le PDF reproduit le document tel
    qu'émis. Rien sur un document qui n'a rien reçu à son émission.

    @param \App\Domain\Facturation\Services\ReglementsALEmission $reglements
    @param \Carbon\CarbonInterface|null $dateEmission
    @param \App\Domain\Calendrier\Calendrier $calendrier celui du magasin du document
--}}
@php
    $calculateur = app(\App\Domain\Facturation\Services\CalculateurLigne::class);
@endphp
@if($reglements->existent())
    <table class="reglements">
        <tr>
            <td class="titre" colspan="2">Règlements</td>
        </tr>
        @foreach($reglements->paiements as $paiement)
            <tr>
                <td class="libelle">{{ implode(' · ', array_filter([$dateEmission === null ? null : $calendrier->afficher($dateEmission, 'd/m/Y'), $paiement['mode']->getLabel(), $paiement['reference']], filled(...))) }}</td>
                <td class="montant">{{ $calculateur->enEuros($paiement['montant']) }}</td>
            </tr>
        @endforeach
        @foreach($reglements->creditsDAvoir as $credit)
            <tr>
                <td class="libelle">Avoir {{ $credit['numero'] }}</td>
                <td class="montant">{{ $calculateur->enEuros($credit['montant']) }}</td>
            </tr>
        @endforeach
        <tr class="restant-du">
            <td class="libelle">Restant dû</td>
            <td class="montant">{{ $calculateur->enEuros($reglements->restantDu) }}</td>
        </tr>
    </table>
@endif
