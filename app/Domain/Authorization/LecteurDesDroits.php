<?php

namespace App\Domain\Authorization;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Throwable;

/**
 * Le contrôle des droits que fait chaque action métier, au moment même où
 * elle écrit, en plus de l'écran et de la Gate : l'auteur que l'action
 * reçoit est relu en base, sans la mémoire de ses droits, et c'est ce
 * compte relu qui répond à la question, par la Gate (ControleDesDroits,
 * puis la policy). Retiré du magasin, suspendu ou privé de l'autorisation
 * pendant qu'un onglet restait ouvert, il est refusé. L'action utilise
 * ensuite le compte relu : c'est lui qu'elle enregistre comme auteur, avec
 * son nom figé, et lui que le registre signe (voir OperateurCourant).
 *
 * Une action qui écrit au registre fiscal (une vente, un paiement, un
 * avoir, une contre-passation, une clôture), qui ferme la caisse d'une
 * autre personne, valide un achat ou émet un avoir fournisseur, relit son
 * auteur par exigerSousVerrou(), après avoir verrouillé les registres
 * qu'elle écrit et avant ses documents et ses produits : la ligne du
 * compte se verrouille (FOR NO KEY UPDATE), comme le fait toute
 * modification de ses droits, qui prend les mêmes registres puis la même
 * ligne (voir JournalDesDroits) ; l'une passe donc tout entière avant ou
 * après l'autre. Les ventes qui ne font que référencer cette ligne (clé
 * étrangère, FOR KEY SHARE) n'attendent pas.
 *
 * Le magasin ou la société de la question doit être celui ou celle du
 * contexte : sans contexte, ou dans un autre, l'action est refusée avant
 * toute lecture.
 */
final class LecteurDesDroits
{
    public const REFUS = 'Vous n\'êtes pas, ou plus, autorisé à faire cela dans ce magasin.';

    public const REFUS_DANS_LA_SOCIETE = 'Vous n\'êtes pas, ou plus, autorisé à faire cela dans cette société.';

    /**
     * Relit l'auteur en base et lui pose la question dans ce magasin, qui
     * doit être le magasin actif.
     *
     * @param  mixed  $cible  le modèle, ou sa classe, sur lequel porte la question ; une liste pour une question à plusieurs arguments
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function exiger(User $auteur, string $geste, Magasin $magasin, mixed $cible = null): User
    {
        $this->verifierLeMagasinActif($magasin);

        return $this->repondre(User::query()->whereKey($auteur->getKey())->first(), $geste, $cible, self::REFUS);
    }

    /**
     * Comme exiger(), en verrouillant d'abord la ligne de l'auteur ;
     * l'appelante a déjà pris les registres qu'elle écrit, dans la
     * transaction en cours.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function exigerSousVerrou(User $auteur, string $geste, Magasin $magasin, mixed $cible = null): User
    {
        $this->verifierLeMagasinActif($magasin);

        return $this->repondre(User::query()->whereKey($auteur->getKey())->lock('for no key update')->first(), $geste, $cible, self::REFUS);
    }

    /**
     * Pour un geste de la société (son espace d'administration, ses
     * actions), la société devant être celle du contexte. Une action qui
     * dit son refus par sa propre exception la passe en $refus.
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function exigerDansLaSociete(User $auteur, string $geste, Societe $societe, mixed $cible = null, ?Throwable $refus = null): User
    {
        $contexte = app(TenantContext::class);

        if (! $contexte->aUneSociete() || $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new LogicException('Un geste de la société ne se contrôle que dans le contexte de cette société.');
        }

        return $this->repondre(User::query()->whereKey($auteur->getKey())->first(), $geste, $cible, $refus ?? new AuthorizationException(self::REFUS_DANS_LA_SOCIETE));
    }

    /**
     * @throws Throwable
     */
    private function repondre(?User $compteRelu, string $geste, mixed $cible, string|Throwable $refus): User
    {
        if ($compteRelu === null || Gate::forUser($compteRelu)->denies($geste, match (true) {
            $cible === null => [],
            is_array($cible) => $cible,
            default => [$cible],
        })) {
            throw is_string($refus) ? new AuthorizationException($refus) : $refus;
        }

        return $compteRelu;
    }

    /**
     * @throws LogicException
     */
    private function verifierLeMagasinActif(Magasin $magasin): void
    {
        $contexte = app(TenantContext::class);

        if (! $contexte->has() || $contexte->id() !== (int) $magasin->getKey()) {
            throw new LogicException('Une action d\'un magasin ne se contrôle que dans le contexte de ce magasin.');
        }
    }
}
