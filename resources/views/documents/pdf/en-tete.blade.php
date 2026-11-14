{{--
    Une bande sarcelle en haut de la première page (modèle M6-b, choix de
    l'éditeur du 14/11/2026), puis le magasin qui vend à gauche, souligné
    d'un filet sarcelle : son nom, puis l'adresse, le SIRET et les
    coordonnées de l'établissement, sans ce qui n'est pas renseigné (voir
    Emetteur::lignesDeLEtablissement()) ; nature et références du document
    à droite, la première, son numéro, en gras. L'identité de la société
    est dans le pied de chaque page (voir PiedDePage). L'émetteur d'un document émis est celui qu'il a
    signé (voir FigeSonEmetteur), jamais la société ni le magasin
    d'aujourd'hui.

    Un document du magasin de démonstration porte « DÉMONSTRATION — SANS
    VALEUR » en travers de chaque page, et son bandeau en tête, celui d'un
    ticket disant de ne pas le remettre à un client (voir
    Emetteur::filigrane() et Emetteur::bandeauDeDemonstration()).

    @param \App\Domain\Facturation\Services\Emetteur $emetteur
    @param string $titre
    @param string $classeTitre classe CSS en plus de titre-document : « majuscules » pour un document de vente, « avoir »
    @param list<string> $details une ligne chacun : numéro, dates...
    @param bool $estUnTicket un ticket de caisse, que la démonstration interdit de remettre
--}}
@if ($emetteur->filigrane() !== null)
    <div class="filigrane">{{ $emetteur->filigrane() }}</div>
    <div class="bandeau-demonstration">{{ $emetteur->bandeauDeDemonstration($estUnTicket ?? false) }}</div>
@endif
<div class="bande-haut"></div>
<table class="en-tete">
    <tr>
        <td class="magasin">
            <div class="magasin-nom">{{ $emetteur->nom }}</div>
            {!! collect($emetteur->lignesDeLEtablissement())->map(fn (string $ligne): string => e($ligne))->implode('<br>') !!}
        </td>
        <td>
            <div class="titre-document {{ $classeTitre ?? '' }}">{{ $titre }}</div>
            <div class="details-document">
                {!! collect(array_values($details))->map(fn (string $detail, int $rang): string => $rang === 0 ? '<span class="numero-du-document">'.e($detail).'</span>' : e($detail))->implode('<br>') !!}
            </div>
        </td>
    </tr>
</table>
