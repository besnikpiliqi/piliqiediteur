<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Models\PreferencesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\ReglagesDuTableauDeBord;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Models\User;
use JsonException;

/**
 * Lit et retient les préférences d'un compte sur un écran du tableau de
 * bord (voir PreferencesDuTableauDeBord) : en base, jamais dans le
 * navigateur, une ligne par compte et par écran, dans la société du
 * compte, qu'elle soit ou non celle de l'écran (un compte d'un cabinet
 * garde ses réglages dans son cabinet, et les retrouve chez chaque client).
 * Chaque lecture et chaque écriture filtrent explicitement par la société
 * et le compte : le réglage d'un compte ne touche jamais celui d'un autre.
 */
final class PreferencesDesGraphiques
{
    /**
     * Les réglages du compte sur l'écran de ce périmètre, vérifiés (voir
     * ReglagesDuTableauDeBord::lire()), ou ceux par défaut s'il n'a encore
     * rien choisi.
     */
    public function lire(User $compte, Perimetre $perimetre): ReglagesDuTableauDeBord
    {
        $preferences = PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $compte->societe_id)
            ->where('user_id', $compte->getKey())
            ->where('ecran', $perimetre->ecran->value)
            ->first(['id', 'reglages']);

        return $preferences === null
            ? ReglagesDuTableauDeBord::parDefaut($perimetre)
            : ReglagesDuTableauDeBord::lire($preferences->reglages, $perimetre);
    }

    /**
     * Retient ces réglages du compte sur cet écran : la ligne naît ou se
     * remplace d'une seule écriture, même quand deux onglets enregistrent
     * en même temps.
     *
     * @throws JsonException
     */
    public function enregistrer(User $compte, EcranDuTableauDeBord $ecran, ReglagesDuTableauDeBord $reglages): void
    {
        PreferencesDuTableauDeBord::query()->withoutGlobalScope(SocieteScope::class)->upsert(
            [[
                'societe_id' => $compte->societe_id,
                'user_id' => $compte->getKey(),
                'ecran' => $ecran->value,
                'reglages' => json_encode($reglages->versTableau(), JSON_THROW_ON_ERROR),
            ]],
            ['societe_id', 'user_id', 'ecran'],
            ['reglages', 'updated_at'],
        );
    }
}
