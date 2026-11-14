<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Events\MagasinOuvert;
use App\Domain\Tenancy\Events\MagasinRouvert;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\SiretMagasin;
use App\Models\User;

/**
 * Met en activité un magasin réel d'une société validée : à la validation
 * de la société pour chacun de ses magasins actifs (voir
 * ValiderLaSocieteAction), à la création d'un magasin dans une société
 * déjà validée (voir CreerEtablissementAction), et à la réouverture d'un
 * magasin dans une société validée (voir RouvrirMagasinAction). Son SIRET
 * se réserve dans tout le
 * logiciel s'il ne l'est pas déjà comme son SIRET actuel (voir
 * SiretsDesMagasins), une période d'activité s'ouvre (voir
 * PeriodesDActivite) et, pour sa première période seulement, les droits
 * implicites du propriétaire de la société et de ses administrateurs de
 * tous les magasins dans ce magasin s'inscrivent à son journal des
 * événements (JET 130, avant nul, après relu en base) : à une réouverture,
 * les droits n'ont pas changé. La période qui s'ouvre se signale, une fois
 * la transaction validée, par MagasinOuvert pour la première, par
 * MagasinRouvert ensuite (voir l'étape 12 : l'abonnement d'un magasin suit
 * ses périodes).
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

    /**
     * @return PeriodeActivite la période qui s'ouvre
     */
    public function mettreEnActivite(Magasin $magasin, ?User $auteur): PeriodeActivite
    {
        $premiere = PeriodeActivite::query()->where('magasin_id', $magasin->getKey())->doesntExist();

        if (! SiretMagasin::query()->where('magasin_id', $magasin->getKey())->whereNull('retire_le')->whereNull('libere_le')->exists()) {
            $this->siretsDesMagasins->reserver($magasin);
        }

        $periode = $this->periodesDActivite->ouvrir($magasin, $auteur);

        if ($premiere) {
            $this->journalDesDroits->inscrireLesDroitsImplicites($magasin);
            MagasinOuvert::dispatch($magasin, $periode);
        } else {
            MagasinRouvert::dispatch($magasin, $periode);
        }

        return $periode;
    }
}
