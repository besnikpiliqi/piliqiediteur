{{--
    La livraison d'une vente hors de France (export, livraison vers un DOM,
    livraison intracommunautaire) : adresse, pays, date et transport (voir
    NoteSaNatureDOperation::lignesDeLivraison()) ; rien pour une autre
    nature.

    @param list<string> $lignes
--}}
@if($lignes !== [])
    <div class="bloc-client">
        <div class="titre">Livraison</div>
        @foreach($lignes as $ligne)
            {{ $ligne }}<br>
        @endforeach
    </div>
@endif
