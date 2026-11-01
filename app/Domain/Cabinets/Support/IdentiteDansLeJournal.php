<?php

namespace App\Domain\Cabinets\Support;

use App\Domain\Tenancy\Models\Societe;

/**
 * Ce que le journal d'audit retient d'une société dans les entrées d'un
 * lien entre un cabinet et un client : son identifiant, son SIREN et sa
 * dénomination ; au journal d'un cabinet, d'une société qui n'a pas
 * accepté sa demande, seulement son identifiant et le SIREN que le cabinet
 * a saisi.
 */
final class IdentiteDansLeJournal
{
    /**
     * @return array{id: int, siren: string, raison_sociale: string|null}
     */
    public static function de(Societe $societe): array
    {
        return ['id' => (int) $societe->getKey(), 'siren' => (string) $societe->siren, 'raison_sociale' => $societe->raison_sociale];
    }

    /**
     * @return array{id: int, siren: string}
     */
    public static function sirenSeul(Societe $societe): array
    {
        return ['id' => (int) $societe->getKey(), 'siren' => (string) $societe->siren];
    }
}
