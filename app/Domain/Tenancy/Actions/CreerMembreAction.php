<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * La société crée directement le compte d'un membre, avec un mot de passe
 * provisoire remis de la main à la main (voir CreationDesComptes), et ses
 * affectations, chacune avec son rôle de magasin :
 * - un vendeur ou un observateur, par le propriétaire ou un responsable du
 *   magasin, avec les cases que son rôle précoche ou celles cochées pour
 *   lui ;
 * - un responsable, par le propriétaire, ou par un administrateur autorisé
 *   à nommer les responsables dans un magasin de sa portée : la nomination
 *   prend effet aussitôt, et le propriétaire en est prévenu ;
 * - plusieurs magasins, par le propriétaire seul.
 *
 * Tout se contrôle sur l'auteur relu sous les verrous des registres des
 * magasins visés et de sa ligne (voir JournalDesDroits) : un responsable
 * retiré de son magasin, ou un administrateur privé de son autorisation,
 * pendant que son formulaire restait ouvert, ne crée plus rien. Les droits
 * donnés s'inscrivent au JET 130 de chaque magasin, puis au journal
 * d'audit, jamais avec le mot de passe. L'adresse du compte n'est pas
 * confirmée : la personne peut refuser ce compte (voir
 * RefuserSonCompteAction).
 */
class CreerMembreAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly AffectationsDesMembres $affectations,
    ) {}

    /**
     * @param  array{name?: mixed, email?: mixed}  $identite
     * @param  array<int, array{role: RoleMagasin|string, autorisations?: list<string>|null}>  $affectations  par identifiant de magasin
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, array $identite, #[SensitiveParameter] string $motDePasseProvisoire, array $affectations): User
    {
        return $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $societe, $identite, $motDePasseProvisoire, $affectations): User {
            $demandees = $this->affectations->lire($societe, $affectations);
            $this->affectations->verifierPourUnNouveauCompte($auteur, $demandees);
            $identiteValide = $this->creation->valider($auteur, $societe, $identite, $motDePasseProvisoire);
            $nommeUnResponsable = collect($demandees)->contains(fn (?array $affectation): bool => $affectation !== null && $affectation['role'] === RoleMagasin::Responsable);
            $ecrites = [];

            $compte = $this->journalDesDroits->modifier(
                $auteur,
                $nommeUnResponsable ? ActionAuditee::ResponsableNomme : ActionAuditee::MembreCree,
                [],
                array_map(fn (array $affectation) => $affectation['magasin'], array_filter($demandees)),
                function (User $auteurRelu) use ($societe, $identiteValide, $motDePasseProvisoire, $demandees, &$ecrites): User {
                    if ((int) $auteurRelu->societe_id !== $societe->getKey()) {
                        throw new AuthorizationException(PorteeDesDroits::REFUS);
                    }

                    $verifiees = $this->affectations->verifierPourUnNouveauCompte($auteurRelu, $demandees);
                    $compte = $this->creation->creer($auteurRelu, $societe, $identiteValide, $motDePasseProvisoire, RoleSociete::Membre);

                    foreach ($verifiees as $affectation) {
                        $ecrites[] = $this->affectations->ecrire($auteurRelu, $compte, $affectation['magasin'], $affectation['role'], $affectation['autorisations']);
                    }

                    return $compte;
                },
            );

            $this->affectations->prevenirDesNominations($compte, $ecrites);

            return $compte;
        });
    }
}
