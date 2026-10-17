<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La société indique l'un de ses magasins réels comme son établissement
 * principal, ou retire cette indication : une simple information, au plus
 * un par société (index unique partiel magasins_un_principal_par_societe),
 * sans aucun effet sur ses réglages, ses documents ni ses autres magasins,
 * dont aucun n'a de privilège sur les autres.
 *
 * Qui : le propriétaire, ou un administrateur de tous les magasins
 * autorisé à les gérer (l'indication déplacée touche aussi le magasin qui
 * la portait), tant que la société n'est pas en lecture seule (voir
 * MagasinDeLaSocietePolicy::choisirCommePrincipal()) ; jamais pour un
 * magasin cédé.
 *
 * En une transaction, sous le verrou de la ligne de la société (deux
 * choix simultanés passent l'un après l'autre, le dernier l'emportant) :
 * le magasin qui portait l'indication la perd, celui-ci la prend, et le
 * choix s'inscrit au journal d'audit, avec l'établissement principal
 * avant et après. Un choix qui ne change rien n'écrit rien.
 */
class ChoisirEtablissementPrincipalAction
{
    public const MESSAGE_CEDE = 'Un magasin cédé n\'est plus indiqué comme établissement principal.';

    public function __construct(private readonly JournalAudit $journalAudit) {}

    /**
     * @param  bool  $principal  l'indiquer comme établissement principal, ou retirer cette indication
     *
     * @throws AuthorizationException quand l'auteur ne peut pas faire ce choix
     * @throws LogicException quand le magasin a été cédé
     */
    public function handle(User $auteur, Magasin $magasin, bool $principal): void
    {
        $societe = $magasin->societe;
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new AuthorizationException(LecteurDesDroits::REFUS_DANS_LA_SOCIETE);
        }

        $contexte->runAsSociete($societe, function () use ($auteur, $magasin, $societe, $principal): void {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, 'choisirCommePrincipal', $societe, $magasin);

            DB::transaction(function () use ($auteur, $magasin, $societe, $principal): void {
                Societe::query()->whereKey($societe->getKey())->lock('for no key update')->firstOrFail();
                $choisi = Magasin::query()->whereKey($magasin->getKey())->firstOrFail();

                if ($principal && $choisi->aEteCede()) {
                    throw new LogicException(self::MESSAGE_CEDE);
                }

                $avant = Magasin::query()->where('societe_id', $societe->getKey())->where('est_etablissement_principal', true)->first();
                $apres = $principal ? $choisi : ($avant?->is($choisi) ? null : $avant);

                if ($avant?->getKey() === $apres?->getKey()) {
                    return;
                }

                $avant?->forceFill(['est_etablissement_principal' => false])->save();
                $apres?->forceFill(['est_etablissement_principal' => true])->save();
                $this->journalAudit->inscrire(
                    ActionAuditee::EtablissementPrincipalChoisi,
                    $auteur,
                    $choisi,
                    ['etablissement_principal' => self::designation($avant)],
                    ['etablissement_principal' => self::designation($apres)],
                    $choisi,
                );
            });
        });
    }

    /**
     * L'établissement principal tel que le journal d'audit le retient.
     *
     * @return array{id: int, nom: string, siret: string|null}|null
     */
    private static function designation(?Magasin $magasin): ?array
    {
        return $magasin === null ? null : ['id' => $magasin->getKey(), 'nom' => $magasin->nom, 'siret' => $magasin->siret];
    }
}
