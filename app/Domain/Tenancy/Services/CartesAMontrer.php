<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Casts\Instant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Les notifications de la cloche à montrer en carte à un compte (modèle A
 * choisi par l'éditeur le 14/11/2026, voir CartesDesNotifications) : les
 * notifications non lues de la cloche du panneau, par la même requête
 * qu'elle, arrivées depuis la dernière carte montrée à ce compte, de la
 * plus récente à la plus ancienne ; au plus trois à la fois, et au plus
 * une, la plus récente, à l'ouverture d'une session, pour que les
 * notifications déjà là ne déferlent pas. Les autres restent dans la
 * cloche, sans carte.
 *
 * Ce qui a été montré s'enregistre pour le compte seulement, jamais sur
 * les notifications : l'arrivée de la plus récente notification prise
 * (users.cartes_montrees_jusqu_au), après laquelle seules les suivantes
 * se montrent, une seule fois, même après un changement de page, dans un
 * autre onglet ou après une nouvelle connexion. Seules les secondes déjà
 * finies se lisent : une notification arrive à la seconde (Instant), et
 * une autre arrivée dans la même seconde, après ce passage, ne passerait
 * jamais derrière l'arrivée enregistrée ; elle attend le passage suivant.
 * L'arrivée ne s'enregistre que si elle n'a pas changé depuis la lecture
 * du compte : de deux onglets qui regardent en même temps, un seul montre
 * les cartes.
 */
final class CartesAMontrer
{
    /**
     * Le plus de cartes montrées à la fois.
     */
    public const AU_PLUS = 3;

    /**
     * Prend, pour ce compte tel qu'il a été lu, les notifications à montrer
     * en carte parmi ses notifications non lues de la cloche, et enregistre
     * l'arrivée de la plus récente ; rend une liste vide si un autre
     * passage les a déjà prises.
     *
     * @param  Builder<DatabaseNotification>|Relation<DatabaseNotification, *, *>  $nonLuesDeLaCloche
     * @return Collection<int, DatabaseNotification> de la plus récente à la plus ancienne
     */
    public function prendre(User $compte, Builder|Relation $nonLuesDeLaCloche, bool $ouvertureDeSession, CarbonImmutable $maintenant): Collection
    {
        $dejaMontrees = $compte->cartes_montrees_jusqu_au;
        $requete = (clone $nonLuesDeLaCloche)->where('created_at', '<', Instant::enBase($maintenant));
        $prise = User::query()->whereKey($compte->getKey())->where('societe_id', $compte->societe_id);

        if ($dejaMontrees === null) {
            $prise->whereNull('cartes_montrees_jusqu_au');
        } else {
            $requete->where('created_at', '>', Instant::enBase($dejaMontrees));
            $prise->where('cartes_montrees_jusqu_au', Instant::enBase($dejaMontrees));
        }

        $notifications = $requete
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($ouvertureDeSession ? 1 : self::AU_PLUS)
            ->get();
        $plusRecente = $notifications->first()?->created_at;

        if ($plusRecente === null) {
            return new Collection;
        }

        $prises = $prise->toBase()->update(['cartes_montrees_jusqu_au' => Instant::enBase($plusRecente)]);

        if ($prises !== 1) {
            return new Collection;
        }

        $compte->cartes_montrees_jusqu_au = CarbonImmutable::instance($plusRecente);
        $compte->syncOriginalAttribute('cartes_montrees_jusqu_au');

        return $notifications;
    }
}
