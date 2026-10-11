<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Societe;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\RneIndisponibleException;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\SireneIndisponibleException;

/**
 * L'adresse officielle du siège d'une entreprise, lue le jour même où un
 * pli y part : celle que le répertoire Sirene de l'INSEE donne de son
 * siège, ou, quand il la masque (diffusion partielle), celle du registre
 * national des entreprises. Jamais une adresse saisie dans PILIQI ni tirée
 * d'une pièce déposée : un usurpateur ne fait pas partir un courrier chez
 * lui.
 */
class AdresseOfficielle
{
    public const MESSAGE_INDISPONIBLE = 'L\'adresse officielle de votre entreprise ne peut pas être lue pour le moment : réessayez plus tard.';

    public const MESSAGE_AUCUNE = 'Aucune adresse officielle de votre entreprise n\'est publiée : l\'éditeur vous contactera.';

    public function __construct(
        private readonly RepertoireSirene $repertoire,
        private readonly RegistreNationalDesEntreprises $rne,
    ) {}

    /**
     * @throws VerificationRefuseeException quand aucune adresse officielle ne peut être lue
     */
    public function du(Societe $societe): AdressePostale
    {
        try {
            $unite = $this->repertoire->unite((string) $societe->siren);
            $siege = $unite?->siretDuSiege() === null ? null : $this->repertoire->etablissement($unite->siretDuSiege());

            if ($unite !== null && $siege !== null && ! $unite->diffusionPartielle && ! $siege->diffusionPartielle && filled($siege->adresse) && filled($siege->codePostal) && filled($siege->ville)) {
                return new AdressePostale($unite->denomination, $siege->adresse, $siege->complementAdresse, $siege->codePostal, $siege->ville, AdressePostale::SOURCE_SIRENE);
            }

            return $this->rne->adresseDuSiege((string) $societe->siren) ?? throw new VerificationRefuseeException(self::MESSAGE_AUCUNE);
        } catch (SireneIndisponibleException|RneIndisponibleException) {
            throw new VerificationRefuseeException(self::MESSAGE_INDISPONIBLE);
        }
    }
}
