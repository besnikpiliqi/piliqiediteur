{{--
    L'émetteur à gauche : le nom du magasin, puis l'identité de la société
    et de l'établissement, sans ce qui n'est pas renseigné (voir
    Emetteur::lignesDIdentite()) ; nature et références du document à
    droite. L'émetteur d'un document émis est celui qu'il a signé (voir
    FigeSonEmetteur), jamais la société ni le magasin d'aujourd'hui.

    Un document du magasin de démonstration porte « DÉMONSTRATION — SANS
    VALEUR » en travers de chaque page, et son bandeau en tête, celui d'un
    ticket disant de ne pas le remettre à un client (voir
    Emetteur::filigrane() et Emetteur::bandeauDeDemonstration()).

    @param \App\Domain\Facturation\Services\Emetteur $emetteur
    @param string $titre
    @param string $classeTitre classe CSS en plus de titre-document
    @param list<string> $details une ligne chacun : numéro, dates...
    @param bool $estUnTicket un ticket de caisse, que la démonstration interdit de remettre
--}}
@if ($emetteur->filigrane() !== null)
    <div class="filigrane">{{ $emetteur->filigrane() }}</div>
    <div class="bandeau-demonstration">{{ $emetteur->bandeauDeDemonstration($estUnTicket ?? false) }}</div>
@endif
<table class="en-tete">
    <tr>
        <td>
            <div class="magasin-nom">{{ $emetteur->nom }}</div>
            {!! collect($emetteur->lignesDIdentite())->map(fn (string $ligne): string => e($ligne))->implode('<br>') !!}
        </td>
        <td>
            <div class="titre-document {{ $classeTitre ?? '' }}">{{ $titre }}</div>
            <div class="details-document">
                {!! collect($details)->map(fn (string $detail): string => e($detail))->implode('<br>') !!}
            </div>
        </td>
    </tr>
</table>
