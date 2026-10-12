<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Le propriétaire, et lui seul, nomme administrateur un membre de sa
 * société, avec sa portée et ses autorisations (voir
 * PorteeDesAdministrateurs) : ses affectations de membre disparaissent,
 * puis il devient administrateur, puis reçoit ses affectations
 * d'administrateur et ses autorisations de société, dans cet ordre (voir
 * GardesDesDroits). Un membre retiré par la société est ainsi réintégré
 * comme administrateur ; un compte retiré par son propre refus, jamais. Un
 * expert-comptable ne devient jamais administrateur sans avoir été retiré.
 * Un compte au mot de passe provisoire n'est nommé que par la personne qui
 * l'a créé.
 *
 * Contrôlé sur l'auteur et le compte relus sous les verrous des registres
 * des magasins touchés et de leurs lignes ; JET 130 dans chacun, puis
 * l'entrée d'audit (voir JournalDesDroits).
 */
class NommerAdministrateurAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
    ) {}

    /**
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin
     * @param  array<array-key, mixed>  $autorisationsSociete
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $compte, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete): User
    {
        $societe = $compte->societe()->firstOrFail();

        $this->dansLaSociete($societe, CreerAdministrateurAction::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $compte, $societe, $magasins, $autorisationsMagasin, $autorisationsSociete): void {
            CreerAdministrateurAction::exigerLeProprietaire($auteur, $societe);
            $portee = $this->porteeDesAdministrateurs->lire($societe, $magasins, $autorisationsMagasin, $autorisationsSociete);

            $this->journalDesDroits->modifier($auteur, ActionAuditee::AdministrateurNomme, [$compte], $this->porteeDesAdministrateurs->magasinsTouches($societe, $portee), function (User $auteurRelu, array $relus) use ($societe, $portee): void {
                [$compteRelu] = $relus;
                CreerAdministrateurAction::exigerLeProprietaire($auteurRelu, $societe);

                if (! self::peutEtreNomme($auteurRelu, $compteRelu)) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                Affectation::query()->where('user_id', $compteRelu->getKey())->delete();
                $compteRelu->forceFill([
                    'role_societe' => RoleSociete::Administrateur,
                    ...($compteRelu->estRetire() ? ['statut' => StatutCompte::Actif, 'statut_change_par' => $auteurRelu->getKey()] : []),
                ])->save();
                $this->porteeDesAdministrateurs->ecrire($compteRelu, $portee);
            });
        });

        return $compte->refresh();
    }

    /**
     * Un membre de la société, autre que le propriétaire lui-même : actif,
     * suspendu, ou retiré par la société (pas par son propre refus) ; au
     * mot de passe provisoire, seulement par la personne qui l'a créé.
     */
    private static function peutEtreNomme(User $proprietaire, User $compte): bool
    {
        return $compte->estMembre()
            && (int) $compte->societe_id === (int) $proprietaire->societe_id
            && ! $compte->is($proprietaire)
            && ! ($compte->estRetire() && (int) $compte->statut_change_par === (int) $compte->getKey())
            && (! $compte->doitChangerSonMotDePasse() || (int) $compte->cree_par === (int) $proprietaire->getKey());
    }
}
