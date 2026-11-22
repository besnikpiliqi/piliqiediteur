<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\ListeDeDocuments;
use App\Domain\Tenancy\Models\Magasin;

/**
 * Ce que le clic sur un jour d'un graphique par période ouvre dans un
 * magasin (voir ListesDesJours) : les listes de documents de ce magasin,
 * filtrées sur ce jour « AAAA-MM-JJ » de son calendrier. Dans l'espace de
 * la société avec « Tous mes magasins », il y en a une par magasin compté.
 */
final readonly class ListeDUnJour
{
    /**
     * @param  list<ListeDeDocuments>  $listes  celles que la personne voit en entier dans ce magasin, et que le graphique compte
     */
    public function __construct(
        public Magasin $magasin,
        public string $jour,
        public array $listes,
    ) {}
}
