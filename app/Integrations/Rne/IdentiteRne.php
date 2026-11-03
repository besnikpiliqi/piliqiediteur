<?php

namespace App\Integrations\Rne;

use App\Integrations\EnvoiRecommande\AdressePostale;

/**
 * L'identité légale d'une entreprise telle que le registre national des
 * entreprises la donne (voir RegistreNationalDesEntreprises::identite()) :
 * sa dénomination, le code à 4 chiffres de sa catégorie juridique, son
 * capital (montant décimal, devise, variable ou non), son immatriculation
 * (« RCS Lyon ») et l'adresse de son siège ; chaque champ que le registre
 * ne donne pas, ou donne illisible, est nul. Elle remplit la société d'un
 * client invité par un cabinet d'expertise comptable (voir
 * AccepterLInvitationAction) ; son représentant légal se lit à part (voir
 * DestinataireOfficiel::representantLegal()).
 */
final readonly class IdentiteRne
{
    public function __construct(
        public ?string $denomination,
        public ?string $categorieJuridique,
        public ?string $capital,
        public ?string $deviseCapital,
        public ?bool $capitalVariable,
        public ?string $immatriculation,
        public ?AdressePostale $adresseDuSiege,
    ) {}
}
