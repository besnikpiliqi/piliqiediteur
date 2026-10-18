{{--
    Les lignes d'un document de vente (points 3 et 11), chacune avec la
    référence de son produit, entière sur une seule ligne, et sa
    désignation, qui passe à la ligne si la place manque :

    - une facture, un avoir sur facture ou un devis à un professionnel
      montrent la quantité, le PU HT, la remise s'il y en a, le taux et le
      total HT : les mentions qu'une facture doit porter pour chaque ligne ;
    - un ticket, un avoir sur ticket ou un devis à un particulier, qui paie
      le prix affiché TTC, montrent le PU TTC et le total TTC ;
    - une vente sans TVA française, qui se paie hors taxe (voir
      ContexteDeVente::documentRetireLaTvaDuPrix()), montre toujours le PU
      HT et le total HT, même sur un ticket : le PU TTC de ses lignes est
      celui du catalogue, TVA française comprise, que le client ne paie
      pas.

    La TVA et le TTC de chaque taux sont dans le tableau qui suit (voir
    totaux.blade.php). La remise n'apparaît que si une ligne en a une. Le
    PU HT s'imprime avec ses décimales utiles, tel qu'il est enregistré
    (voir CalculateurLigne::prixUnitaireHtExact()) : la quantité x le PU
    HT, remise déduite, retombe sur le total HT de la ligne à moins de deux
    centimes. Un avoir indique la facture de chaque ligne, son numéro
    entier sur une seule ligne. Rien ne se recalcule : chaque montant est
    celui de la ligne enregistrée.

    @param \App\Domain\Facturation\Models\Facture|\App\Domain\Facturation\Models\Avoir|\App\Domain\Facturation\Models\Devis $document
--}}
@php
    $calculateur = app(\App\Domain\Facturation\Services\CalculateurLigne::class);
    $lignes = $document->lignes;
    $enTtc = ! $document->estPourUnProfessionnel() && ! \App\Domain\Facturation\Services\ContexteDeVente::documentRetireLaTvaDuPrix($document);
    $avecFactureDOrigine = $document instanceof \App\Domain\Facturation\Models\Avoir;
    $avecRemise = $lignes->contains(fn ($ligne): bool => $calculateur->enCentiemes($ligne->remise_pourcentage) !== 0);
    $noteDeCalcul = app(\App\Domain\Facturation\Services\MentionsFiscales::class)->calcul($document);
@endphp

<table class="lignes">
    <thead>
        <tr>
            <th>Référence</th>
            <th>Désignation</th>
            @if($avecFactureDOrigine)
                <th>Facture d'origine</th>
            @endif
            <th class="montant">Qté</th>
            <th class="montant">{{ $enTtc ? 'PU TTC' : 'PU HT' }}</th>
            @if($avecRemise)
                <th class="montant">Remise</th>
            @endif
            <th class="montant">Taux</th>
            <th class="montant">{{ $enTtc ? 'Total TTC' : 'Total HT' }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lignes as $ligne)
            <tr>
                <td class="reference">{{ $ligne->reference ?? '' }}</td>
                <td>{{ $ligne->description }}</td>
                @if($avecFactureDOrigine)
                    <td class="numero">{{ $ligne->ligneFacture?->facture?->numero ?? '—' }}</td>
                @endif
                <td class="montant">{{ \Illuminate\Support\Number::format($ligne->quantite, locale: 'fr') }}</td>
                <td class="montant">{{ $enTtc ? $calculateur->enEuros($calculateur->enCentiemes($ligne->prix_unitaire_ttc)) : $calculateur->prixUnitaireEnEuros($calculateur->enMillioniemes($ligne->prix_unitaire_ht)) }}</td>
                @if($avecRemise)
                    <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->remise_pourcentage, maxPrecision: 2, locale: 'fr') }} %</td>
                @endif
                <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->taux_tva, maxPrecision: 2, locale: 'fr') }} %</td>
                <td class="montant">{{ $calculateur->enEuros($calculateur->enCentiemes($enTtc ? $ligne->montant_ttc : $ligne->montant_ht)) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if($noteDeCalcul)
    <div class="note-calcul">{{ $noteDeCalcul }}</div>
@endif
