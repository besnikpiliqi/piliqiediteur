<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * Le propriétaire, et lui seul, crée un administrateur en une fois : son
 * identité, son mot de passe provisoire remis de la main à la main (voir
 * CreationDesComptes), sa portée (tous les magasins, présents et futurs,
 * ou une sélection, sans rien de présélectionné) et ses autorisations,
 * de société et de magasin (voir PorteeDesAdministrateurs).
 *
 * Contrôlé sur l'auteur relu sous les verrous des registres des magasins
 * de sa portée (tous les magasins de la société pour une portée globale)
 * et de sa ligne ; un seul JET 130 par magasin touché, puis l'entrée
 * d'audit (voir JournalDesDroits).
 */
class CreerAdministrateurAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire crée, nomme et modifie les administrateurs.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
    ) {}

    /**
     * @param  array{name?: mixed, email?: mixed}  $identite
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin  une liste pour chaque magasin, ou une liste par magasin de la sélection
     * @param  array<array-key, mixed>  $autorisationsSociete
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, array $identite, #[SensitiveParameter] string $motDePasseProvisoire, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete): User
    {
        return $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $societe, $identite, $motDePasseProvisoire, $magasins, $autorisationsMagasin, $autorisationsSociete): User {
            self::exigerLeProprietaire($auteur, $societe);
            $portee = $this->porteeDesAdministrateurs->lire($societe, $magasins, $autorisationsMagasin, $autorisationsSociete);
            $identiteValide = $this->creation->valider($auteur, $societe, $identite, $motDePasseProvisoire);

            return $this->journalDesDroits->modifier($auteur, ActionAuditee::AdministrateurCree, [], $this->porteeDesAdministrateurs->magasinsTouches($societe, $portee), function (User $auteurRelu) use ($societe, $identiteValide, $motDePasseProvisoire, $portee): User {
                self::exigerLeProprietaire($auteurRelu, $societe);
                $administrateur = $this->creation->creer($auteurRelu, $societe, $identiteValide, $motDePasseProvisoire, RoleSociete::Administrateur);
                $this->porteeDesAdministrateurs->ecrire($administrateur, $portee);

                return $administrateur;
            });
        });
    }

    /**
     * Le propriétaire de cette société, avec un compte qui peut agir.
     *
     * @throws AuthorizationException
     */
    public static function exigerLeProprietaire(User $auteur, Societe $societe): void
    {
        if (! $auteur->peutAgir() || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }
}
