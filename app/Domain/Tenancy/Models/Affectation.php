<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * L'affectation d'un compte de la société à un de ses magasins, ou à tous
 * (magasin_id nul : tous les magasins, présents et futurs) :
 * - celle d'un membre a un rôle de magasin (voir RoleMagasin) : un
 *   responsable n'a aucune case, car il a toutes les autorisations de son
 *   magasin ; un vendeur ou un observateur a les cases cochées pour lui
 *   (voir cocher()) ;
 * - celle d'un administrateur n'a pas de rôle : elle délimite sa portée et
 *   porte les cases que le propriétaire lui a cochées.
 * Les droits d'un compte dans un magasin se lisent par
 * User::autorisationsDans(), et ne s'écrivent que par les actions de
 * l'équipe, qui les inscrivent au journal des événements de chaque magasin
 * touché (JET 130, voir JournalDesDroits).
 *
 * Gardes, quel que soit l'écran ou la commande qui l'enregistre, ici avec
 * un message clair, puis dans la base (voir GardesDesDroits) :
 * - le propriétaire et un expert-comptable n'en ont aucune ;
 * - un membre a un rôle de magasin dans chaque affectation, jamais une
 *   affectation à tous les magasins ;
 * - un administrateur n'a pas de rôle de magasin, et a une affectation à
 *   tous les magasins ou des affectations par magasin, jamais les deux ;
 * - le compte et le magasin sont de la même société (clés étrangères
 *   composites de la table affectations).
 *
 * Un responsable nommé par un administrateur garde la trace de cette
 * nomination (nomme_par, et avant_la_nomination pour une promotion) tant
 * qu'elle peut s'annuler (voir AnnulerUneNominationAction).
 *
 * Volontairement sans SocieteScope, comme User et Magasin : les droits se
 * lisent avant tout contexte (connexion, choix du magasin). Toute lecture
 * passe par le compte (User::affectations()), ou filtre explicitement par
 * société ou par magasin (voir EquipeDuMagasinResource).
 */
class Affectation extends Model
{
    protected $table = 'affectations';

    protected $fillable = [];

    protected $casts = [
        'role' => RoleMagasin::class,
        'avant_la_nomination' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Affectation $affectation): void {
            $roleDuCompte = User::query()->find($affectation->user_id)?->role_societe;
            $ailleurs = static::query()->where('user_id', $affectation->user_id)->whereKeyNot($affectation->getKey() ?? 0);

            if (in_array($roleDuCompte, [RoleSociete::Proprietaire, RoleSociete::ExpertComptable], true)) {
                throw new LogicException('Le propriétaire et un expert-comptable n\'ont aucune affectation : leurs droits valent dans chaque magasin sans case.');
            }

            if ($roleDuCompte === RoleSociete::Administrateur) {
                if ($affectation->role !== null) {
                    throw new LogicException('Un administrateur n\'a aucun rôle de magasin.');
                }

                if ($affectation->magasin_id === null ? $ailleurs->whereNotNull('magasin_id')->exists() : $ailleurs->whereNull('magasin_id')->exists()) {
                    throw new LogicException('Un administrateur a accès à tous les magasins ou à une sélection, jamais aux deux.');
                }

                return;
            }

            if ($affectation->role === null || $affectation->magasin_id === null) {
                throw new LogicException('Un membre est affecté à un magasin, avec un rôle de magasin.');
            }
        });
    }

    /**
     * Est-ce l'affectation d'un administrateur (sans rôle de magasin) ?
     */
    public function estDAdministrateur(): bool
    {
        return $this->role === null;
    }

    public function estDeResponsable(): bool
    {
        return $this->role === RoleMagasin::Responsable;
    }

    public function estATousLesMagasins(): bool
    {
        return $this->magasin_id === null;
    }

    /**
     * Les autorisations cochées pour cette affectation, telles que la base
     * les donne, dans l'ordre alphabétique.
     *
     * @return list<string>
     */
    public function autorisations(): array
    {
        return DB::table('autorisations_affectations')
            ->where('affectation_id', $this->getKey())
            ->orderBy('autorisation')
            ->pluck('autorisation')
            ->all();
    }

    /**
     * Remplace les autorisations cochées pour cette affectation. Une
     * autorisation inconnue est refusée avant que rien ne soit effacé (voir
     * PermissionRegistry::verifierConnues()), puis par la clé étrangère du
     * référentiel ; une affectation de responsable n'a aucune case. Ce que
     * l'auteur a le droit de cocher se contrôle avant, par l'action (voir
     * PorteeDesDroits::verifierCochables()).
     *
     * @param  array<array-key, mixed>  $autorisations
     *
     * @throws LogicException quand l'affectation est celle d'un responsable
     */
    public function cocher(array $autorisations): void
    {
        $connues = PermissionRegistry::verifierConnues($autorisations);

        if ($this->estDeResponsable()) {
            throw new LogicException('Un responsable n\'a aucune case : il a toutes les autorisations de son magasin.');
        }

        DB::table('autorisations_affectations')->where('affectation_id', $this->getKey())->delete();
        DB::table('autorisations_affectations')->insert(array_map(
            fn (string $autorisation): array => ['affectation_id' => $this->getKey(), 'autorisation' => $autorisation],
            $connues,
        ));
    }

    /**
     * Efface toutes ses cases : avant qu'elle devienne celle d'un
     * responsable, qui n'en a aucune.
     */
    public function effacerLesCases(): void
    {
        DB::table('autorisations_affectations')->where('affectation_id', $this->getKey())->delete();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }
}
