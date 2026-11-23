{{--
    La lecture de « Essayer le barème » (voir ParametresPrixDeVente et
    EssaiDuBareme) : le prix de vente avant et après l'arrondi, le détail du
    calcul et, case cochée, le tableau d'exemples de la formule choisie.
    $formule : la formule choisie, ou null case décochée ; $lecture : ce que
    EssaiDuBareme a lu.
--}}
@php
    use App\Domain\Catalogue\Services\ExemplesDArrondi;
@endphp

<div class="piliqi-arrondi">
    <div class="piliqi-arrondi__resultats" data-arrondi="resultats">
        @if ($formule)
            <div class="piliqi-arrondi__resultat">
                <span class="piliqi-arrondi__etiquette" id="essai-avant-arrondi">Prix de vente TTC avant arrondi</span>
                <span class="piliqi-arrondi__valeur" role="status" aria-labelledby="essai-avant-arrondi" data-arrondi="avant">{{ $lecture['avant'] }}</span>
            </div>
        @endif

        <div class="piliqi-arrondi__resultat">
            <span class="piliqi-arrondi__etiquette" id="essai-apres-arrondi">{{ $formule ? 'Prix de vente TTC arrondi' : 'Prix de vente TTC (sans arrondi)' }}</span>
            <span @class(['piliqi-arrondi__valeur', 'piliqi-arrondi__valeur--arrondi' => $formule]) role="status" aria-labelledby="essai-apres-arrondi" data-arrondi="apres">{{ $lecture['apres'] }}</span>
        </div>
    </div>

    <div class="piliqi-arrondi__detail" aria-live="polite" data-arrondi="detail">
        @foreach ($lecture['lignes'] as $ligne)
            <p @class(['piliqi-arrondi__gris' => $ligne['grise']])>{{ $ligne['texte'] }}</p>
        @endforeach
    </div>

    @if ($formule)
        <div class="piliqi-arrondi__exemples" data-arrondi="exemples">
            <div>
                <h3 class="piliqi-arrondi__titre">Exemples de la formule choisie</h3>
                <p class="piliqi-arrondi__gris">{{ ExemplesDArrondi::legende($formule) }}</p>
            </div>

            <div class="piliqi-arrondi__cadre" tabindex="0" role="region" aria-label="Exemples de la formule choisie, tableau défilant">
                <table class="piliqi-arrondi__tableau">
                    <caption class="sr-only">Prix de vente TTC avant et après l'arrondi de la formule choisie</caption>
                    <thead>
                        <tr>
                            <th scope="col">Prix de vente TTC avant</th>
                            <th scope="col">Prix de vente TTC après</th>
                            <th scope="col">Écart</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (ExemplesDArrondi::tableau($formule) as $exemple)
                            <tr @class(['piliqi-arrondi__ligne--inchangee' => $exemple['inchange']]) data-arrondi="ligne">
                                <td>{{ $exemple['avant'] }}</td>
                                <td class="piliqi-arrondi__apres">{{ $exemple['apres'] }}</td>
                                <td>{{ $exemple['ecart'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="piliqi-arrondi__gris" data-arrondi="regles">{{ ExemplesDArrondi::regles() }}</p>
        </div>
    @else
        <p class="piliqi-arrondi__gris" data-arrondi="sans-arrondi">Case décochée : aucun arrondi, le prix de vente est le prix calculé par le barème, au centime.</p>
    @endif
</div>
