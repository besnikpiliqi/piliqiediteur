{{--
    Le bloc « Règlements » d'un ticket ou d'une facture (points 3 et 23,
    Q5, constat C3), à gauche des totaux sur leur bande (voir
    documents/pdf/totaux) : ce qui l'a réglé à son émission, tel que son
    contenu signé l'a retenu (voir ReglementsALEmission), en colonnes Date,
    Mode, Référence et Montant -- chaque paiement avec son mode et sa
    référence, puis chaque crédit d'avoir avec le numéro de l'avoir --, puis
    ce qui restait dû à l'émission. Un paiement reçu ensuite reste sur la
    fiche du document, et a son reçu de paiement (voir RecusDePaiement) : le
    PDF reproduit le document tel qu'émis. Rien sur un document qui n'a rien
    reçu à son émission. Une référence passe à la ligne entre deux mots,
    jamais au milieu d'un mot (voir TableauDesReglements).

    @param \App\Domain\Facturation\Services\ReglementsALEmission $reglements
    @param \Carbon\CarbonInterface|null $dateEmission
    @param \App\Domain\Calendrier\Calendrier $calendrier celui du magasin du document
--}}
@if($reglements->existent())
    @php
        $date = $dateEmission === null ? '' : $calendrier->afficher($dateEmission, 'd/m/Y');
    @endphp
    @include('documents.pdf.tableau-des-reglements', [
        'titre' => 'Règlements',
        'tableau' => \App\Domain\Facturation\Services\TableauDesReglements::surLaBande([
            ...array_map(fn (array $paiement): array => ['date' => $date, 'mode' => $paiement['mode']->getLabel(), 'reference' => $paiement['reference'], 'montant' => $paiement['montant']], $reglements->paiements),
            ...array_map(fn (array $credit): array => ['date' => $date, 'mode' => 'Avoir', 'reference' => $credit['numero'], 'montant' => $credit['montant']], $reglements->creditsDAvoir),
        ]),
        'totaux' => [['libelle' => 'Restant dû à l\'émission', 'montant' => app(\App\Domain\Facturation\Services\CalculateurLigne::class)->enEuros($reglements->restantDu)]],
    ])
@endif
