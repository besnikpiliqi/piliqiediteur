<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Le déroulé commun des gestes sur le crédit de TVA déclaré d'une société
 * (lot TVA27, voir les actions DeclarerUnCreditDeTvaAction,
 * ModifierUnCreditDeTvaAction, ConfirmerUnCreditDeTvaAction et
 * CorrigerUnCreditDeTvaAction), comme ceux des charges (voir
 * GestesSurLesCharges) :
 * - le geste se fait dans la société du crédit, posée comme société
 *   active (voir TenantContext::runAsSociete()) : un compte d'un cabinet
 *   lié agit chez son client depuis l'espace de son cabinet ;
 * - dans une seule transaction, sous les verrous de l'application, dans
 *   leur ordre : la ligne de la société (comme toute entrée de son journal
 *   d'audit), puis celle de l'auteur, puis la déclaration elle-même, que le
 *   geste relit sous son verrou : deux gestes simultanés sur une même
 *   déclaration passent l'un après l'autre, et le second voit ce qu'a fait
 *   le premier ;
 * - l'auteur et la société sont relus en base, et ses droits reposés
 *   (voir CreditsDeTva::peutDeclarer() et peutVerifier()) : retiré,
 *   suspendu ou privé de l'autorisation pendant qu'un onglet restait
 *   ouvert, il est refusé ;
 * - une société révoquée ou close ne fait plus que consulter son crédit ;
 *   suspendue ou résiliée, elle le déclare encore, ses déclarations de TVA
 *   continuant jusqu'au bout (comme ses charges, décision de Claude, D76) ;
 *   hors du réel normal (régime à choisir, franchise en base), ou membre
 *   d'un assujetti unique, dont le groupe déclare la TVA (voir
 *   CreditsDeTva::aLieuDansLa()), rien ne se déclare ni ne se change ;
 * - chaque geste porte son jeton de demande répétée (voir
 *   ActionsUniques::executerDansLaSociete()) et s'inscrit au journal
 *   d'audit de la société, catégorie « opérations », avec les anciennes et
 *   les nouvelles valeurs (voir valeurs()).
 */
final class GestesSurLeCreditDeTva
{
    public const REFUS = 'Seuls le propriétaire de la société et son comptable indiquent son crédit de TVA.';

    public const REFUS_SOCIETE = 'Cette société ne fait plus que consulter son crédit de TVA.';

    public const CREDIT_INTROUVABLE = 'Ce crédit de TVA n\'existe pas, ou plus.';

    public const REFUS_REGIME = 'Le crédit de TVA ne se déclare qu\'au réel normal : choisissez d\'abord le régime de TVA de la société. En franchise en base, il n\'y a ni TVA à payer ni crédit.';

    public const REFUS_ASSUJETTI_UNIQUE = 'Une société membre d\'un assujetti unique ne déclare pas elle-même sa TVA : le représentant du groupe la déclare, avec le crédit de TVA du groupe.';

    public function __construct(
        private readonly ActionsUniques $actionsUniques,
        private readonly CreditsDeTva $credits,
        private readonly JournalAudit $journalAudit,
        private readonly TenantContext $contexte,
    ) {}

    /**
     * Exécute le geste, une seule fois pour ce jeton, avec l'auteur et la
     * société relus, et la façon dont l'auteur agit (voir commeQui()), et
     * rend la déclaration qu'il laisse.
     *
     * @param  array<array-key, mixed>  $parametres  le contenu de la demande, ce qui la distingue d'une autre
     * @param  Closure(User, Societe, ValideePar): CreditDeTva  $geste
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function executer(User $auteur, Societe $societe, string $action, ?string $jeton, array $parametres, Closure $geste): CreditDeTva
    {
        $resultat = $this->contexte->runAsSociete($societe, fn (): ?Model => $this->actionsUniques->executerDansLaSociete(
            $jeton,
            $action,
            CreditDeTva::class,
            $auteur,
            [...$parametres, 'societe' => $societe->uuid],
            function () use ($auteur, $societe): void {
                Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
                User::query()->whereKey($auteur->getKey())->lock('for no key update')->first();
            },
            function () use ($auteur, $societe, $geste): CreditDeTva {
                $relu = User::query()->with('societe')->whereKey($auteur->getKey())->first() ?? throw new AuthorizationException(self::REFUS);
                $relue = Societe::query()->whereKey($societe->getKey())->firstOrFail();

                $comme = $this->commeQui($relu, $relue);

                if (GestesSurLesCharges::touchePlusASesCharges($relue)) {
                    throw new LogicException(self::REFUS_SOCIETE);
                }

                if ($relue->membre_assujetti_unique) {
                    throw new LogicException(self::REFUS_ASSUJETTI_UNIQUE);
                }

                if (! CreditsDeTva::aLieuDansLa($relue)) {
                    throw new LogicException(self::REFUS_REGIME);
                }

                return $geste($relu, $relue, $comme);
            },
        ));

        return $resultat instanceof CreditDeTva ? $resultat : throw new LogicException(self::CREDIT_INTROUVABLE);
    }

    /**
     * La déclaration de cet uuid de cette société, relue sous son verrou.
     *
     * @throws LogicException une déclaration d'une autre société, ou qui n'existe pas
     */
    public function creditVerrouille(Societe $societe, string $uuid): CreditDeTva
    {
        return CreditDeTva::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe->getKey())
            ->where('uuid', $uuid)
            ->lockForUpdate()
            ->first() ?? throw new LogicException(self::CREDIT_INTROUVABLE);
    }

    /**
     * Comment l'auteur agit sur le crédit : le propriétaire, ou le
     * comptable, le cabinet lié ou l'expert-comptable déclaré ; refusé à
     * tout autre compte.
     *
     * @throws AuthorizationException
     */
    public function commeQui(User $auteur, Societe $societe): ValideePar
    {
        if ($this->credits->estLeProprietaire($auteur, $societe)) {
            return ValideePar::Proprietaire;
        }

        if ($this->credits->peutVerifier($auteur, $societe)) {
            return $auteur->estExpertComptable() ? ValideePar::ExpertComptable : ValideePar::Cabinet;
        }

        throw new AuthorizationException(self::REFUS);
    }

    /**
     * Inscrit le geste au journal d'audit de la société, avec ce que la
     * déclaration retient avant lui et après lui.
     *
     * @param  array<string, mixed>|null  $avant
     */
    public function auditer(ActionAuditee $action, User $auteur, ?array $avant, CreditDeTva $credit): void
    {
        $this->journalAudit->inscrire($action, $auteur, null, $avant, self::valeurs($credit), $credit);
    }

    /**
     * Ce que le journal d'audit retient d'une déclaration : son uuid, sa
     * période et sa cadence, son solde en centimes, si elle est vérifiée,
     * par quel comptable (le cabinet ou l'expert-comptable déclaré, jamais
     * le nom d'une personne, que l'entrée garde déjà comme son auteur), et
     * le motif de sa dernière correction.
     *
     * @return array<string, mixed>
     */
    public static function valeurs(CreditDeTva $credit): array
    {
        return [
            'credit' => $credit->uuid,
            'periode' => $credit->periode,
            'cadence' => $credit->cadence->value,
            'solde' => $credit->solde,
            'verifie' => $credit->estVerifie(),
            'verifie_par' => $credit->verifie_comme?->value,
            'motif' => $credit->motif,
        ];
    }
}
