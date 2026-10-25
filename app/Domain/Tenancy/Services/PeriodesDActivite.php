<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Models\User;
use LogicException;

/**
 * Les périodes d'activité des magasins (voir PeriodeActivite), leur seul
 * écrivain : une période s'ouvre à la validation de la société, à la
 * création ou à la réouverture d'un magasin dans une société validée (voir
 * MiseEnActiviteDesMagasins) ou à l'ouverture du magasin de
 * démonstration, et se ferme à sa fermeture, avec son motif, qu'une
 * cession faite ensuite ne change pas (voir FermerMagasinAction) ; une seule
 * est ouverte à la fois (index unique partiel
 * periodes_activite_une_ouverte). Les tickets Z ne se produisent que pour
 * les jours d'une période (voir CloturerLesJourneesAction).
 *
 * Toute lecture filtre explicitement par magasin : la table n'a pas de
 * scope global.
 */
class PeriodesDActivite
{
    /**
     * @throws LogicException quand le magasin a déjà une période ouverte
     */
    public function ouvrir(Magasin $magasin, ?User $par = null): PeriodeActivite
    {
        if (PeriodeActivite::query()->where('magasin_id', $magasin->getKey())->whereNull('fin')->exists()) {
            throw new LogicException('Ce magasin a déjà une période d\'activité ouverte.');
        }

        return tap((new PeriodeActivite)->forceFill([
            'magasin_id' => $magasin->getKey(),
            'debut' => now(),
            'ouverte_par' => $par?->getKey(),
        ]))->save();
    }

    /**
     * Ferme la période ouverte du magasin, pour ce motif.
     *
     * @throws LogicException quand le magasin n'a aucune période ouverte
     */
    public function fermer(Magasin $magasin, MotifFermeture $motif, ?User $par = null): PeriodeActivite
    {
        $periode = PeriodeActivite::query()->where('magasin_id', $magasin->getKey())->whereNull('fin')->lockForUpdate()->first()
            ?? throw new LogicException('Ce magasin n\'a aucune période d\'activité ouverte.');

        return tap($periode->forceFill(['fin' => now(), 'motif_fin' => $motif, 'fermee_par' => $par?->getKey()]))->save();
    }

    /**
     * Les jours du calendrier des périodes du magasin : pour chacune,
     * le jour de son début et celui de sa fin, sa fin étant nulle tant
     * qu'elle est ouverte. Un jour est dans une période s'il tombe entre
     * ces deux jours, compris.
     *
     * @return list<array{0: string, 1: string|null}> AAAA-MM-JJ
     */
    public function jours(Magasin $magasin): array
    {
        return PeriodeActivite::query()
            ->where('magasin_id', $magasin->getKey())
            ->orderBy('debut')
            ->get(['debut', 'fin'])
            ->map(fn (PeriodeActivite $periode): array => [Calendrier::deParis()->jourDe($periode->debut), $periode->fin === null ? null : Calendrier::deParis()->jourDe($periode->fin)])
            ->all();
    }
}
