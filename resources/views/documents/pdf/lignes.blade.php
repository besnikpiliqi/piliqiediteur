{{--
    Les lignes d'un document de vente : pour chaque article son PU HT, son
    PU TTC et son taux, puis le HT, la TVA et le TTC de la ligne. La remise
    n'apparaît que si une ligne en a une : elle explique alors pourquoi
    PU x quantité diffère du total de la ligne.

    Le HT de chaque taux se calcule sur son TTC et se répartit sur les
    lignes : PU HT x quantité peut différer du HT de la ligne (1 000 x
    0,83 € pour 833,33 €), ce que la note de calcul sous les lignes
    explique (voir MentionsFiscales::calcul()).

    Une vente sans TVA française n'imprime pas de PU TTC : le client paie
    le PU HT, sans la TVA française que comprend le prix du catalogue (voir
    ContexteDeVente::documentRetireLaTvaDuPrix()). Elle imprime à la place
    le prix TTC du catalogue de chaque produit, et de chaque service à son
    prix de référence, et la TVA qui en est retirée, à côté de son taux de
    0 % : les deux données que cite la note de calcul (3 x 7,00 € ÷ 1,20 =
    17,50 € HT, quand 3 x 5,83 € font 17,49 €). Un
    autre service, saisi sans TVA, n'a ni l'un ni l'autre.

    @param \Illuminate\Support\Collection $lignes
    @param bool $avecFactureDOrigine un avoir indique la facture de chaque ligne
    @param bool $sansTvaFrancaise vrai pour une vente sans TVA française
    @param string|null $noteDeCalcul
--}}
@php
    $avecRemise = $lignes->contains(fn ($ligne) => (float) $ligne->remise_pourcentage != 0);
    $avecFactureDOrigine ??= false;
    $sansTvaFrancaise ??= false;
    $noteDeCalcul ??= null;
@endphp

<table class="lignes">
    <thead>
        <tr>
            <th>Description</th>
            @if($avecFactureDOrigine)
                <th>Facture d'origine</th>
            @endif
            <th class="montant">Qté</th>
            <th class="montant">PU HT</th>
            @if($sansTvaFrancaise)
                <th class="montant">Prix catalogue TTC</th>
                <th class="montant">TVA retirée</th>
            @else
                <th class="montant">PU TTC</th>
            @endif
            <th class="montant">Taux TVA</th>
            @if($avecRemise)
                <th class="montant">Remise</th>
            @endif
            <th class="montant">Total HT</th>
            <th class="montant">TVA</th>
            <th class="montant">Total TTC</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lignes as $ligne)
            <tr>
                <td>{{ $ligne->description }}</td>
                @if($avecFactureDOrigine)
                    <td>{{ $ligne->ligneFacture?->facture?->numero ?? '—' }}</td>
                @endif
                <td class="montant">{{ \Illuminate\Support\Number::format($ligne->quantite, locale: 'fr') }}</td>
                <td class="montant">{{ number_format((float) $ligne->prix_unitaire_ht, 2, ',', ' ') }} €</td>
                @if(! $sansTvaFrancaise)
                    <td class="montant">{{ number_format((float) $ligne->prix_unitaire_ttc, 2, ',', ' ') }} €</td>
                @elseif($ligne->nature === \App\Domain\Facturation\Enums\NatureLigne::Bien || (float) $ligne->taux_tva_du_prix > 0)
                    <td class="montant">{{ number_format((float) $ligne->prix_unitaire_ttc, 2, ',', ' ') }} €</td>
                    <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->taux_tva_du_prix, maxPrecision: 2, locale: 'fr') }} %</td>
                @else
                    <td class="montant">—</td>
                    <td class="montant">—</td>
                @endif
                <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->taux_tva, maxPrecision: 2, locale: 'fr') }} %</td>
                @if($avecRemise)
                    <td class="montant">{{ \Illuminate\Support\Number::format((float) $ligne->remise_pourcentage, maxPrecision: 2, locale: 'fr') }} %</td>
                @endif
                <td class="montant">{{ number_format((float) $ligne->montant_ht, 2, ',', ' ') }} €</td>
                <td class="montant">{{ number_format((float) $ligne->montant_tva, 2, ',', ' ') }} €</td>
                <td class="montant">{{ number_format((float) $ligne->montant_ttc, 2, ',', ' ') }} €</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if($noteDeCalcul)
    <div class="note-calcul">{{ $noteDeCalcul }}</div>
@endif
