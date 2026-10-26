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
 * Une réservation se fait à la mise en activité d'un magasin d'une
 * société validée (voir MiseEnActiviteDesMagasins : la validation de la
 * société pour ses magasins actifs, la création d'un magasin dans une
 * société validée, une réouverture) et à un changement de SIRET (voir
 * remplacer()) ; elle ne prend fin que par la procédure de l'éditeur qui
 * la libère (voir liberer()). Le message de refus ne dit rien du magasin
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

    /**
     * Libère le SIRET du magasin au registre, avec son motif, par la
     * procédure de l'éditeur (voir LibererLeSiretAction), sous les verrous
     * de son registre et de sa ligne, pris par l'appelant : il peut alors
     * se réserver pour un autre magasin. Le magasin d'une société qui n'a
     * jamais été validée, ou qui était fermé à sa validation, n'a rien de
     * réservé : rien ne change ici.
     */
    public function liberer(Magasin $magasin, string $motif): void
    {
        SiretMagasin::query()
            ->where('magasin_id', $magasin->getKey())
            ->where('siret', $magasin->siret)
            ->whereNull('libere_le')
            ->lockForUpdate()
            ->get()
            ->each(fn (SiretMagasin $reservation): bool => $reservation->forceFill(['libere_le' => now(), 'motif_liberation' => $motif])->save());
    }

    /**
     * Remplace le SIRET du magasin d'une société validée par un nouveau
     * (voir ChangerSiretAction), dans un point de sauvegarde : son SIRET
     * actuel au registre, s'il en a un, cesse de l'être mais reste réservé
     * (retire_le) ; le nouveau se réserve ; puis il s'écrit sur le magasin,
     * avec ces autres attributs, et s'inscrit à son journal des événements
     * (voir JournaliseSesParametresDeConformite). Le magasin est relu
     * verrouillé par l'appelant, sous le verrou de son registre. Un SIRET
     * déjà réservé, ou pris entre-temps par un autre magasin de la
     * société, est refusé sans rien changer.
     *
     * @param  array<string, mixed>  $attributs  ce qui change avec lui : l'adresse reprise du répertoire Sirene...
     *
     * @throws SiretDejaReserveException
     */
    public function remplacer(Magasin $magasin, string $siret, array $attributs = []): SiretMagasin
    {
        try {
            return DB::transaction(function () use ($magasin, $siret, $attributs): SiretMagasin {
                SiretMagasin::query()
                    ->where('magasin_id', $magasin->getKey())
                    ->whereNull('retire_le')
                    ->whereNull('libere_le')
                    ->lockForUpdate()
                    ->get()
                    ->each(fn (SiretMagasin $actuel): bool => $actuel->forceFill(['retire_le' => now()])->save());

                $nouveau = tap((new SiretMagasin)->forceFill([
                    'societe_id' => $magasin->societe_id,
                    'magasin_id' => $magasin->getKey(),
                    'siret' => $siret,
                    'reserve_le' => now(),
                ]))->save();

                $magasin->forceFill([...$attributs, 'siret' => $siret])->save();

                return $nouveau;
            });
        } catch (UniqueConstraintViolationException) {
            throw new SiretDejaReserveException;
        }
    }
}
