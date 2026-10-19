<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La portée et les autorisations d'un administrateur, que le propriétaire
 * choisit en une fois (voir CreerAdministrateurAction,
 * ChangerLeRoleDeSocieteAction, ModifierAdministrateurAction et
 * ChangerStatutCompteAction::reintegrerLAdministrateur()), lues et
 * écrites une seule fois :
 * - tous les magasins, présents et futurs (une affectation sans magasin),
 *   ou une sélection de magasins de la société (une affectation par
 *   magasin), jamais les deux, et jamais une portée vide ;
 * - ses cases de magasin, les mêmes partout ou propres à chaque magasin de
 *   sa sélection ;
 * - ses autorisations de société ; celles qui touchent toute la société
 *   (les paramètres) seulement avec tous les magasins.
 * Toute autorisation inconnue est refusée avant toute écriture, puis par
 * les clés étrangères des référentiels.
 */
final class PorteeDesAdministrateurs
{
    public const MESSAGE_SANS_PORTEE = 'Choisissez tous les magasins ou au moins un magasin.';

    public const MESSAGE_TOUS_LES_MAGASINS_EXIGES = 'Les paramètres de la société ne se confient qu\'à un administrateur de tous les magasins.';

    /**
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin  une liste, la même pour chaque magasin, ou une liste par identifiant de magasin de la sélection
     * @param  array<array-key, mixed>  $autorisationsSociete
     * @return array{tous: bool, magasins: Collection<int, Magasin>, cases: array<int, list<string>>, societe: list<string>}
     *
     * @throws AuthorizationException quand un magasin n'est pas de la société
     * @throws LogicException
     */
    public function lire(Societe $societe, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete): array
    {
        $deSociete = AutorisationsSociete::verifierConnues($autorisationsSociete);
        $tous = $magasins === null;

        if (! $tous && array_intersect($deSociete, AutorisationsSociete::exigentTousLesMagasins()) !== []) {
            throw new LogicException(self::MESSAGE_TOUS_LES_MAGASINS_EXIGES);
        }

        $selection = $tous ? new Collection : Magasin::query()->where('societe_id', $societe->getKey())->whereKey($magasins)->orderBy('id')->get();

        if (! $tous && ($magasins === [] || $selection->count() !== count(array_unique($magasins)))) {
            throw $magasins === [] ? new LogicException(self::MESSAGE_SANS_PORTEE) : new AuthorizationException(PorteeDesDroits::REFUS);
        }

        $memesPartout = array_is_list($autorisationsMagasin);
        $cases = [];

        foreach ($tous ? [0] : $selection->modelKeys() as $magasinId) {
            $cases[(int) $magasinId] = PermissionRegistry::verifierConnues($memesPartout ? $autorisationsMagasin : ($autorisationsMagasin[$magasinId] ?? []));
        }

        return ['tous' => $tous, 'magasins' => $selection, 'cases' => $cases, 'societe' => $deSociete];
    }

    /**
     * Les magasins que touche cette portée : tous ceux de la société pour
     * tous les magasins, sinon ceux de la sélection.
     *
     * @param  array{tous: bool, magasins: Collection<int, Magasin>, cases: array<int, list<string>>, societe: list<string>}  $portee
     * @return Collection<int, Magasin>
     */
    public function magasinsTouches(Societe $societe, array $portee): Collection
    {
        return $portee['tous'] ? Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get() : $portee['magasins'];
    }

    /**
     * Retire à un administrateur sa portée et ses autorisations de société.
     */
    public function effacer(User $administrateur): void
    {
        Affectation::query()->where('user_id', $administrateur->getKey())->delete();
        DB::table('autorisations_societe')->where('user_id', $administrateur->getKey())->delete();
    }

    /**
     * Écrit sa portée et ses autorisations : le compte doit déjà être
     * administrateur, sans aucune autre affectation (voir GardesDesDroits).
     *
     * @param  array{tous: bool, magasins: Collection<int, Magasin>, cases: array<int, list<string>>, societe: list<string>}  $portee
     */
    public function ecrire(User $administrateur, array $portee): void
    {
        foreach ($portee['cases'] as $magasinId => $cases) {
            $affectation = (new Affectation)->forceFill([
                'societe_id' => $administrateur->societe_id,
                'user_id' => $administrateur->getKey(),
                'magasin_id' => $portee['tous'] ? null : $magasinId,
                'role' => null,
            ]);
            $affectation->save();
            $affectation->cocher($cases);
        }

        DB::table('autorisations_societe')->insert(array_map(
            fn (string $autorisation): array => ['societe_id' => $administrateur->societe_id, 'user_id' => $administrateur->getKey(), 'autorisation' => $autorisation],
            $portee['societe'],
        ));
    }
}
