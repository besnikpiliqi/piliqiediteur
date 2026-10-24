<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Le propriétaire, et lui seul, crée un administrateur en une fois : son
 * nom, sans adresse e-mail, son identifiant de connexion, qui est aussi son
 * premier mot de passe (voir CreationDesComptes), sa portée (tous les
 * magasins, présents et futurs, ou une sélection, sans rien de
 * présélectionné) et ses autorisations, de société et de magasin (voir
 * PorteeDesAdministrateurs), celles de la vente jamais précochées.
 *
 * Contrôlé sur l'auteur relu sous les verrous des registres des magasins
 * de sa portée (tous les magasins de la société pour une portée globale)
 * et de sa ligne, puis le jeton de l'écran (une demande répétée rend le
 * compte déjà créé) et la limite du jour ; un seul JET 130 par magasin
 * touché, puis l'entrée d'audit (voir JournalDesDroits).
 */
class CreerAdministrateurAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire crée et modifie les administrateurs.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
    ) {}

    /**
     * @param  array{name?: mixed}  $identite
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin  une liste pour chaque magasin, ou une liste par magasin de la sélection
     * @param  array<array-key, mixed>  $autorisationsSociete
     * @param  string|null  $jeton  le jeton de l'écran de création, tiré à son ouverture
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $societe, array $identite, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete, ?string $jeton = null): User
    {
        return $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $societe, $identite, $magasins, $autorisationsMagasin, $autorisationsSociete, $jeton): User {
            self::exigerLeProprietaire($auteur, $societe);
            $portee = $this->porteeDesAdministrateurs->lire($societe, $magasins, $autorisationsMagasin, $autorisationsSociete);
            $identiteValide = $this->creation->valider($identite);
            $empreinte = CreationDesComptes::empreinte([
                'role_societe' => RoleSociete::Administrateur->value,
                'name' => $identiteValide['name'],
                'magasins' => $portee['tous'] ? null : $portee['magasins']->modelKeys(),
                'cases' => $portee['cases'],
                'societe' => $portee['societe'],
            ]);

            try {
                $resultat = $this->journalDesDroits->modifier($auteur, ActionAuditee::AdministrateurCree, [], $this->porteeDesAdministrateurs->magasinsTouches($societe, $portee), function (User $auteurRelu) use ($societe, $identiteValide, $portee, $jeton, $empreinte): array {
                    self::exigerLeProprietaire($auteurRelu, $societe);
                    $existant = $this->creation->compteDejaCree($auteurRelu, $societe, $jeton, $empreinte);

                    if ($existant !== null) {
                        return ['existant' => $existant];
                    }

                    $this->creation->verifierLaLimiteDuJour($auteurRelu, $societe);
                    $administrateur = $this->creation->creer($auteurRelu, $societe, $identiteValide, RoleSociete::Administrateur, null, $jeton, $empreinte);
                    $this->porteeDesAdministrateurs->ecrire($administrateur, $portee);

                    return ['compte' => $administrateur];
                });
            } catch (UniqueConstraintViolationException $doublon) {
                if (! CreationDesComptes::estUnJetonDejaServi($doublon)) {
                    throw $doublon;
                }

                return $this->creation->compteDejaCree($auteur->refresh(), $societe, $jeton, $empreinte) ?? throw $doublon;
            }

            return $resultat['existant'] ?? $resultat['compte'];
        });
    }

    /**
     * Le propriétaire de cette société, qui gère son équipe (voir
     * PorteeDesDroits::gereSonEquipe()).
     *
     * @throws AuthorizationException
     */
    public static function exigerLeProprietaire(User $auteur, Societe $societe): void
    {
        if (! app(PorteeDesDroits::class)->gereSonEquipe($auteur) || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }
}
