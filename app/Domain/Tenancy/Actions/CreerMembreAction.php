<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * La société crée directement le compte d'un membre pour un magasin, le
 * seul qu'il ouvrira jamais (voir CreationDesComptes) : son identifiant de
 * connexion, tiré au hasard, est aussi son premier mot de passe, remis en
 * main propre ; aucune adresse e-mail. Son rôle de magasin :
 * - un vendeur ou un observateur, par le propriétaire ou un responsable du
 *   magasin, avec les cases que son rôle précoche ou celles cochées pour
 *   lui ;
 * - un responsable, par le propriétaire, ou par un administrateur autorisé
 *   à nommer les responsables dans un magasin réel de sa portée : la
 *   nomination prend effet aussitôt, et le propriétaire est prévenu du
 *   compte créé, dont l'administrateur connaît le premier mot de passe.
 * Dans le magasin de démonstration, le propriétaire et un responsable de ce
 * magasin y créent des comptes d'entraînement.
 *
 * Tout se contrôle sur l'auteur relu sous les verrous du registre du
 * magasin et de sa ligne (voir JournalDesDroits) : ses droits d'abord, puis
 * le jeton de l'écran (une demande répétée rend le compte déjà créé, voir
 * CreationDesComptes::compteDejaCree()), puis sa limite du jour. Les droits
 * donnés s'inscrivent au JET 130 du magasin, puis au journal d'audit,
 * jamais avec l'identifiant.
 */
class CreerMembreAction
{
    use AgitDansLaSociete;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly CreationDesComptes $creation,
        private readonly AffectationsDesMembres $affectations,
        private readonly PorteeDesDroits $portee,
    ) {}

    /**
     * @param  array{name?: mixed}  $identite
     * @param  list<string>|null  $autorisations  les cases d'un vendeur ou d'un observateur ; null, le modèle de son rôle
     * @param  string|null  $jeton  le jeton de l'écran de création, tiré à son ouverture
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws LogicException
     */
    public function handle(User $auteur, Magasin $magasin, array $identite, RoleMagasin|string $role, ?array $autorisations = null, ?string $jeton = null): User
    {
        $societe = Societe::query()->findOrFail($magasin->societe_id);

        return $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $magasin, $societe, $identite, $role, $autorisations, $jeton): User {
            $demandee = $this->affectations->lire($role, $autorisations);
            $cases = $this->verifierLesDroits($auteur, $magasin, $demandee);
            $identiteValide = $this->creation->valider($identite);
            $empreinte = CreationDesComptes::empreinte([
                'role_societe' => RoleSociete::Membre->value,
                'magasin' => (int) $magasin->getKey(),
                'role' => $demandee['role']->value,
                'name' => $identiteValide['name'],
                'autorisations' => $cases,
            ]);

            try {
                $resultat = $this->journalDesDroits->modifier(
                    $auteur,
                    $demandee['role'] === RoleMagasin::Responsable ? ActionAuditee::ResponsableNomme : ActionAuditee::MembreCree,
                    [],
                    [$magasin],
                    function (User $auteurRelu) use ($societe, $magasin, $identiteValide, $demandee, $jeton, $empreinte): array {
                        $cases = $this->verifierLesDroits($auteurRelu, $magasin, $demandee);
                        $existant = $this->creation->compteDejaCree($auteurRelu, $societe, $jeton, $empreinte);

                        if ($existant !== null) {
                            return ['existant' => $existant];
                        }

                        $this->creation->verifierLaLimiteDuJour($auteurRelu, $societe);
                        $compte = $this->creation->creer($auteurRelu, $societe, $identiteValide, RoleSociete::Membre, $magasin, $jeton, $empreinte);

                        return ['compte' => $compte, 'affectation' => $this->affectations->ecrire($auteurRelu, $compte, $magasin, $demandee['role'], $cases)];
                    },
                );
            } catch (UniqueConstraintViolationException $doublon) {
                if (! CreationDesComptes::estUnJetonDejaServi($doublon)) {
                    throw $doublon;
                }

                return $this->creation->compteDejaCree($auteur->refresh(), $societe, $jeton, $empreinte) ?? throw $doublon;
            }

            if (isset($resultat['existant'])) {
                return $resultat['existant'];
            }

            /** @var Affectation $affectation */
            $affectation = $resultat['affectation'];
            $this->affectations->prevenirDUneNomination($resultat['compte'], $affectation, compteCree: true);

            return $resultat['compte'];
        });
    }

    /**
     * L'auteur peut-il donner ce rôle à un nouveau compte de ce magasin, avec
     * ces cases ? Rend les cases contrôlées.
     *
     * @param  array{role: RoleMagasin, autorisations: list<string>|null}  $demandee
     * @return list<string>
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function verifierLesDroits(User $auteur, Magasin $magasin, array $demandee): array
    {
        if ((int) $auteur->societe_id !== (int) $magasin->societe_id || ! $this->portee->peutDonnerLeRole($auteur, null, $magasin, $demandee['role'])) {
            throw new AuthorizationException(PorteeDesDroits::REFUS);
        }

        return $this->affectations->cases($auteur, $magasin, $demandee);
    }
}
