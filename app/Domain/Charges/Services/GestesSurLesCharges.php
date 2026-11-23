<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le déroulé commun des gestes sur les charges d'une société (voir les
 * actions de App\Domain\Charges\Actions) :
 * - le geste se fait dans la société de la charge, posée comme société
 *   active (voir TenantContext::runAsSociete()) : un compte d'un cabinet
 *   lié agit chez son client depuis l'espace de son cabinet ;
 * - dans une seule transaction, sous les verrous de l'application, dans
 *   leur ordre : la ligne de la société (comme toute entrée de son journal
 *   d'audit), puis celle de l'auteur (FOR NO KEY UPDATE, comme toute
 *   modification de ses droits, voir LecteurDesDroits), puis la charge
 *   elle-même, que le geste relit sous son verrou (voir
 *   chargeVerrouillee()) : deux gestes simultanés sur une même charge
 *   passent l'un après l'autre, et le second voit ce qu'a fait le premier ;
 * - l'auteur est relu en base, sans la mémoire de ses droits, et son accès
 *   aux charges reposé (voir DroitsSurLesCharges) : retiré, suspendu ou
 *   privé de l'autorisation pendant qu'un onglet restait ouvert, il est
 *   refusé ;
 * - une société révoquée ou close ne touche plus à ses charges : elles se
 *   consultent seulement ; suspendue ou résiliée, elle les dépose et son
 *   comptable les valide encore, car ses déclarations de TVA continuent
 *   jusqu'au bout (décision de Claude, D76) ;
 * - chaque geste porte son jeton de demande répétée (voir
 *   ActionsUniques::executerDansLaSociete()).
 */
final class GestesSurLesCharges
{
    public const REFUS = 'Vous n\'êtes pas, ou plus, autorisé à faire cela sur les charges de cette société.';

    public const REFUS_SOCIETE = 'Cette société ne touche plus à ses charges : elles se consultent seulement.';

    public const CHARGE_INTROUVABLE = 'Cette charge n\'existe pas, ou plus.';

    public function __construct(
        private readonly ActionsUniques $actionsUniques,
        private readonly DroitsSurLesCharges $droits,
        private readonly TenantContext $contexte,
    ) {}

    /**
     * Exécute le geste, une seule fois pour ce jeton, et rend la charge
     * qu'il laisse (aucune pour une suppression).
     *
     * @param  array<array-key, mixed>  $parametres  le contenu de la demande, ce qui la distingue d'une autre
     * @param  Closure(AccesAuxCharges): (Charge|null)  $geste  le geste, avec l'accès de l'auteur relu
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function executer(User $auteur, Societe $societe, string $action, ?string $jeton, array $parametres, Closure $geste): ?Charge
    {
        $resultat = $this->contexte->runAsSociete($societe, fn (): ?Model => $this->actionsUniques->executerDansLaSociete(
            $jeton,
            $action,
            Charge::class,
            $auteur,
            [...$parametres, 'societe' => $societe->uuid],
            function () use ($auteur, $societe): void {
                Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
                User::query()->whereKey($auteur->getKey())->lock('for no key update')->first();
            },
            fn (): ?Charge => $geste($this->accesDeLAuteurRelu($auteur, $societe)),
        ));

        return $resultat instanceof Charge ? $resultat : null;
    }

    /**
     * Exécute un geste sur les types de charge de la société, sans jeton :
     * il est protégé par l'index unique de leur nom ou par leur état, sous
     * les mêmes verrous et avec l'accès de l'auteur relu.
     *
     * @template TResultat
     *
     * @param  Closure(AccesAuxCharges): TResultat  $geste
     * @return TResultat
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function executerSansJeton(User $auteur, Societe $societe, Closure $geste): mixed
    {
        return $this->contexte->runAsSociete($societe, fn (): mixed => DB::transaction(function () use ($auteur, $societe, $geste): mixed {
            Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
            User::query()->whereKey($auteur->getKey())->lock('for no key update')->first();

            return $geste($this->accesDeLAuteurRelu($auteur, $societe));
        }));
    }

    /**
     * La charge de cet uuid, relue sous son verrou parmi celles que l'accès
     * voit.
     *
     * @throws LogicException une charge qu'il ne voit pas, ou qui n'existe plus
     */
    public function chargeVerrouillee(AccesAuxCharges $acces, string $uuid): Charge
    {
        return $acces->filtrer(Charge::query())->parUuid($uuid)->lockForUpdate()->first()
            ?? throw new LogicException(self::CHARGE_INTROUVABLE);
    }

    /**
     * Une société touche-t-elle encore à ses charges ? Ni révoquée, ni close.
     */
    public static function touchePlusASesCharges(Societe $societe): bool
    {
        return $societe->statut === StatutSociete::Revoquee || $societe->estClose();
    }

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function accesDeLAuteurRelu(User $auteur, Societe $societe): AccesAuxCharges
    {
        $relu = User::query()->with('societe')->whereKey($auteur->getKey())->first();
        $societe = Societe::query()->whereKey($societe->getKey())->firstOrFail();
        $acces = $relu === null ? null : $this->droits->pour($relu, $societe);

        if ($acces === null) {
            throw new AuthorizationException(self::REFUS);
        }

        if (self::touchePlusASesCharges($societe)) {
            throw new LogicException(self::REFUS_SOCIETE);
        }

        return $acces;
    }
}
