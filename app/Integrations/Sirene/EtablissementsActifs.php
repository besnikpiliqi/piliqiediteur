<?php

namespace App\Integrations\Sirene;

/**
 * Les établissements actifs d'une entreprise, tels que le répertoire
 * Sirene les liste (voir RepertoireSirene::etablissementsActifs()) : le
 * siège d'abord, puis par NIC. Une entreprise qui en compte plus que le
 * répertoire n'en rend en une fois n'est listée qu'en partie
 * ($complete faux) : les autres se saisissent par leur NIC.
 */
final readonly class EtablissementsActifs
{
    /**
     * @param  list<EtablissementSirene>  $etablissements
     */
    public function __construct(
        public array $etablissements,
        public bool $complete,
    ) {}
}
