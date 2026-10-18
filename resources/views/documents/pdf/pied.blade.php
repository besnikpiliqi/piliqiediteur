{{--
    Le pied fixé en bas de chaque page (voir PiedDePage) : l'identité de la
    société, puis les conditions de paiement et les mentions, une par
    ligne. Il se place avant le contenu du document, pour que chaque page
    le reprenne ; « Page x sur y » s'imprime sous lui (voir MiseEnPageA4).

    @param \App\Domain\Facturation\Services\PiedDePage $pied
--}}
<div id="pied-de-page">
    <div class="identite">{{ $pied->identite }}</div>
    @foreach($pied->lignes as $ligne)
        <div class="pied">{{ $ligne }}</div>
    @endforeach
</div>
