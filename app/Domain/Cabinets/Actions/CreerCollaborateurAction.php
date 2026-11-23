<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Le propriétaire d'un cabinet d'expertise comptable, et lui seul, crée le
 * compte d'un collaborateur du cabinet (voir RoleSociete::Collaborateur),
 * selon les règles de création des comptes (voir CreationDesComptes) : son
 * nom, sans adresse e-mail, et son identifiant de connexion de 8 chiffres,
 * aussi son premier mot de passe, sans affectation, sans case et sans
 * client confié (voir ConfierDesClientsAction). Un collaborateur n'ouvre
 * jamais son compte avec son identifiant, faute de poste reconnu (voir
 * PostesReconnus::ouvreLeCompte()) : il choisit son mot de passe en sa
 * présence, sur l'écran du propriétaire (voir
 * GereLesComptes::fenetreDuCompteCree()), ou plus tard, quand celui-ci lui
 * redonne l'accès en sa présence. Jamais hors d'un cabinet.
 *
 * Contrôlé sur l'auteur relu sous son verrou (un cabinet n'a aucun magasin,
 * donc aucun registre), puis le jeton de l'écran et la limite du jour ; ses
 * droits s'inscrivent au journal d'audit du cabinet (voir JournalDesDroits).
 */
class CreerCollaborateurAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL = 'Seul le propriétaire du cabinet crée, suspend, réactive, retire et réintègre un collaborateur et lui redonne l\'accès.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
    ) {}

    /**
     * @param  array{name?: mixed}  $identite
     * @param  string|null  $jeton  le jeton de l'écran de création, tiré à son ouverture
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $cabinet, array $identite, ?string $jeton = null): User
    {
        return $this->dansLaSociete($cabinet, self::MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL, function () use ($auteur, $cabinet, $identite, $jeton): User {
            self::exigerLeProprietaireDuCabinet($auteur, $cabinet);
            $identiteValide = $this->creation->valider($identite);
            $empreinte = CreationDesComptes::empreinte(['role_societe' => RoleSociete::Collaborateur->value, 'name' => $identiteValide['name']]);

            try {
                $resultat = $this->journalDesDroits->modifier($auteur, ActionAuditee::CollaborateurCree, [], [], function (User $auteurRelu) use ($cabinet, $identiteValide, $jeton, $empreinte): array {
                    self::exigerLeProprietaireDuCabinet($auteurRelu, $cabinet);
                    $existant = $this->creation->compteDejaCree($auteurRelu, $cabinet, $jeton, $empreinte);

                    if ($existant !== null) {
                        return ['existant' => $existant];
                    }

                    $this->creation->verifierLaLimiteDuJour($auteurRelu, $cabinet);

                    return ['compte' => $this->creation->creer($auteurRelu, $cabinet, $identiteValide, RoleSociete::Collaborateur, null, $jeton, $empreinte)];
                });
            } catch (UniqueConstraintViolationException $doublon) {
                if (! CreationDesComptes::estUnJetonDejaServi($doublon)) {
                    throw $doublon;
                }

                return $this->creation->compteDejaCree($auteur->refresh(), $cabinet, $jeton, $empreinte) ?? throw $doublon;
            }

            return $resultat['existant'] ?? $resultat['compte'];
        });
    }

    /**
     * Le propriétaire actif de ce cabinet, qui gère son équipe.
     *
     * @throws AuthorizationException
     */
    public static function exigerLeProprietaireDuCabinet(User $auteur, Societe $cabinet): void
    {
        if (! $cabinet->estUnCabinet() || ! app(PorteeDesDroits::class)->gereSonEquipe($auteur) || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $cabinet->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL);
        }
    }
}
