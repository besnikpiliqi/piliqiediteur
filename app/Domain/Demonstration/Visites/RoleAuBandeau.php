<?php

namespace App\Domain\Demonstration\Visites;

use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Models\User;
use Database\Seeders\Semis\ComptesDuBandeauDuModele;

/**
 * Le rôle d'un compte au bandeau « Vous êtes : » d'une copie (lot
 * DEMO-COPIE, C.8), lu sur le compte tel qu'il est maintenant : une clé
 * (le rôle de société, ou le rôle de magasin d'un membre), un libellé et
 * son groupe (« La société », le magasin d'un membre, « Le comptable »,
 * « Le cabinet »). Le libellé écrit au bandeau (par le semis du modèle ou
 * à la création du compte) ne vaut que tant que le compte garde le rôle
 * qu'il décrit : un vendeur nommé responsable ou administrateur, une
 * propriété transmise, se lisent aussitôt avec leur nouveau rôle et leur
 * nouveau groupe.
 */
final readonly class RoleAuBandeau
{
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $groupe,
        public bool $estProprietaire,
    ) {}

    /**
     * Le rôle actuel de ce compte, ses affectations et leur magasin lus
     * (chargés d'avance par le bandeau), dans une société qui est un
     * cabinet ou non.
     */
    public static function de(User $compte, bool $dansUnCabinet): self
    {
        if ($compte->role_societe !== RoleSociete::Membre) {
            return match ($compte->role_societe) {
                RoleSociete::Proprietaire => new self('proprietaire', $dansUnCabinet ? 'Propriétaire du cabinet' : 'Propriétaire', $dansUnCabinet ? ComptesDuBandeauDuModele::LE_CABINET : ComptesDuBandeauDuModele::LA_SOCIETE, true),
                RoleSociete::Administrateur => new self('administrateur', 'Administrateur', ComptesDuBandeauDuModele::LA_SOCIETE, false),
                RoleSociete::ExpertComptable => new self('expert_comptable', 'Expert-comptable déclaré', ComptesDuBandeauDuModele::LE_COMPTABLE, false),
                RoleSociete::Collaborateur => new self('collaborateur', 'Collaborateur', ComptesDuBandeauDuModele::LE_CABINET, false),
            };
        }

        $affectation = $compte->affectations->first(static fn (Affectation $affectation): bool => $affectation->role !== null);

        return $affectation?->role === null
            ? new self('membre', RoleSociete::Membre->getLabel(), ComptesDuBandeauDuModele::LA_SOCIETE, false)
            : new self($affectation->role->value, $affectation->role->getLabel(), (string) $affectation->magasin?->nom, false);
    }
}
