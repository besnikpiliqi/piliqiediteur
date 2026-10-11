<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Exceptions\SiretDejaReserveException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\SiretMagasin;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Le registre des SIRET réservés (voir SiretMagasin), son seul écrivain :
 * un SIRET n'appartient qu'à un seul magasin de tout le logiciel dès que
 * sa société est validée, et ne sert jamais à un autre magasin, même
 * après un changement de SIRET. L'index unique partiel
 * sirets_magasins_siret_reserve tranche deux réservations simultanées :
 * la seconde est refusée.
 *
 * Une réservation se fait à la validation de la société pour ses magasins
 * actifs, et à la création d'un magasin dans une société validée (voir
 * CreerEtablissementAction). Le message de refus ne dit rien du magasin
 * ni de la société qui a réservé ce SIRET.
 */
class SiretsDesMagasins
{
    public const MESSAGE_SIRET_RESERVE = 'Ce SIRET ne peut pas être utilisé.';

    /**
     * Ce SIRET est-il réservé, par un magasin actuel ou passé ? Seule
     * lecture du registre hors de la société, limitée à l'existence de la
     * réservation, et seulement pour une société validée (voir
     * CreerEtablissementAction) : une société en cours de vérification
     * n'apprend jamais qu'un SIRET est réservé par une autre.
     */
    public static function estReserve(string $siret): bool
    {
        return SiretMagasin::query()
            ->where('siret', $siret)
            ->whereNull('libere_le')
            ->exists();
    }

    /**
     * Réserve le SIRET actuel du magasin, dans un point de sauvegarde : un
     * SIRET déjà réservé est refusé sans annuler ce qui précède dans la
     * transaction de l'appelant, qui décide.
     *
     * @throws SiretDejaReserveException quand le SIRET est déjà réservé, ou que le magasin a déjà son SIRET actuel au registre
     */
    public function reserver(Magasin $magasin): SiretMagasin
    {
        try {
            return DB::transaction(fn (): SiretMagasin => tap((new SiretMagasin)->forceFill([
                'societe_id' => $magasin->societe_id,
                'magasin_id' => $magasin->getKey(),
                'siret' => $magasin->siret,
                'reserve_le' => now(),
            ]))->save());
        } catch (UniqueConstraintViolationException) {
            throw new SiretDejaReserveException;
        }
    }
}
