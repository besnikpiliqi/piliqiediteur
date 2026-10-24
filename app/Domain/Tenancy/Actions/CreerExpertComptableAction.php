<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
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
 * Le propriétaire, et lui seul, crée le compte d'un expert-comptable de la
 * société, un par collaborateur du cabinet et par société suivie : son
 * nom, sans adresse e-mail, et son identifiant de connexion, qui est aussi
 * son premier mot de passe (voir CreationDesComptes), sans affectation ni
 * case. Il lit chaque magasin réel de la société, présent ou futur, et
 * n'écrit rien (voir ControleDesDroits) ; chacun signe ses propres
 * consultations.
 *
 * Contrôlé sur l'auteur relu sous les verrous des registres de tous les
 * magasins de la société et de sa ligne, puis le jeton de l'écran et la
 * limite du jour ; ses droits s'inscrivent au JET 130 de chaque magasin
 * réel (avant nul, après relu), puis au journal d'audit (voir
 * JournalDesDroits).
 */
class CreerExpertComptableAction
{
    use AgitDansLaSociete;

    public const MESSAGE_PROPRIETAIRE_SEUL = 'Seul le propriétaire crée, suspend, réactive, retire et réintègre un expert-comptable.';

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
    public function handle(User $auteur, Societe $societe, array $identite, ?string $jeton = null): User
    {
        return $this->dansLaSociete($societe, self::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $societe, $identite, $jeton): User {
            self::exigerLeProprietaire($auteur, $societe);
            $identiteValide = $this->creation->valider($identite);
            $empreinte = CreationDesComptes::empreinte(['role_societe' => RoleSociete::ExpertComptable->value, 'name' => $identiteValide['name']]);

            try {
                $resultat = $this->journalDesDroits->modifier($auteur, ActionAuditee::ExpertComptableCree, [], Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get(), function (User $auteurRelu) use ($societe, $identiteValide, $jeton, $empreinte): array {
                    self::exigerLeProprietaire($auteurRelu, $societe);
                    $existant = $this->creation->compteDejaCree($auteurRelu, $societe, $jeton, $empreinte);

                    if ($existant !== null) {
                        return ['existant' => $existant];
                    }

                    $this->creation->verifierLaLimiteDuJour($auteurRelu, $societe);

                    return ['compte' => $this->creation->creer($auteurRelu, $societe, $identiteValide, RoleSociete::ExpertComptable, null, $jeton, $empreinte)];
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
     * @throws AuthorizationException
     */
    public static function exigerLeProprietaire(User $auteur, Societe $societe): void
    {
        if (! app(PorteeDesDroits::class)->gereSonEquipe($auteur) || ! $auteur->estProprietaire() || (int) $auteur->societe_id !== $societe->getKey()) {
            throw new AuthorizationException(self::MESSAGE_PROPRIETAIRE_SEUL);
        }
    }
}
