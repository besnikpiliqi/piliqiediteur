<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;

/**
 * Met en activité un magasin réel d'une société validée : à la validation
 * de la société pour chacun de ses magasins actifs (voir
 * ValiderLaSocieteAction), puis à la création d'un magasin dans une
 * société déjà validée (voir CreerEtablissementAction). Son SIRET se
 * réserve dans tout le logiciel (voir SiretsDesMagasins), sa période
 * d'activité s'ouvre (voir PeriodesDActivite), et les droits implicites du
 * propriétaire de la société et de ses administrateurs de tous les
 * magasins dans ce magasin s'inscrivent à son journal des événements (JET
 * 130, avant nul, après relu en base).
 *
 * Le magasin doit être le magasin actif, dans la transaction de
 * l'appelant, qui décide de ce que devient un SIRET déjà réservé.
 */
class MiseEnActiviteDesMagasins
{
    public function __construct(
        private readonly SiretsDesMagasins $siretsDesMagasins,
        private readonly PeriodesDActivite $periodesDActivite,
        private readonly JournalDesDroits $journalDesDroits,
    ) {}

    public function mettreEnActivite(Magasin $magasin, ?User $auteur): void
    {
        $this->siretsDesMagasins->reserver($magasin);
        $this->periodesDActivite->ouvrir($magasin, $auteur);
        $this->journalDesDroits->inscrireLesDroitsImplicites($magasin);
    }
}
