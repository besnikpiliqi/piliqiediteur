<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\EnvoiRecommande\DestinataireDuCourrier;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;
use App\Integrations\Rne\RepresentantRne;
use App\Integrations\Rne\RneIndisponibleException;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\SireneIndisponibleException;

/**
 * Le destinataire d'un courrier recommandé à code, lu le jour même où il
 * part : le représentant légal de l'entreprise inscrit au registre
 * national des entreprises de l'INPI (le premier de ses dirigeants qui a
 * le pouvoir de la représenter : gérant, président... ; une personne
 * morale dirigeante, par sa dénomination, à l'attention de son
 * représentant), à l'adresse officielle du siège que donne ce registre
 * ou, quand il n'en donne pas, le répertoire Sirene de l'INSEE. Jamais
 * une adresse saisie dans le logiciel ni tirée d'une pièce déposée : une
 * personne qui inscrit l'entreprise d'un autre ne fait pas partir le
 * courrier chez elle.
 */
class DestinataireOfficiel
{
    public const MESSAGE_INDISPONIBLE = 'Le registre national des entreprises ne répond pas pour le moment : réessayez dans quelques minutes.';

    public const MESSAGE_AUCUN_REPRESENTANT = 'Aucun représentant légal de cette entreprise n\'est inscrit au registre national des entreprises : contactez l\'éditeur.';

    public const MESSAGE_AUCUNE_ADRESSE = 'Aucune adresse officielle du siège de cette entreprise n\'est publiée : contactez l\'éditeur.';

    public function __construct(
        private readonly RegistreNationalDesEntreprises $rne,
        private readonly RepertoireSirene $repertoire,
    ) {}

    /**
     * @throws VerificationRefuseeException quand le registre ne répond pas, ou ne donne ni représentant légal ni adresse
     */
    public function du(string $siren): DestinataireDuCourrier
    {
        try {
            $representant = collect($this->rne->representants($siren))->first(fn (RepresentantRne $dirigeant): bool => $dirigeant->pouvoirDeRepresentation)
                ?? throw new VerificationRefuseeException(self::MESSAGE_AUCUN_REPRESENTANT);
            $adresse = $this->rne->adresseDuSiege($siren) ?? $this->siegeAuRepertoire($siren) ?? throw new VerificationRefuseeException(self::MESSAGE_AUCUNE_ADRESSE);
        } catch (RneIndisponibleException) {
            throw new VerificationRefuseeException(self::MESSAGE_INDISPONIBLE);
        }

        return new DestinataireDuCourrier($representant->nomPourLeCourrier(), $representant->estUnePersonneMorale(), $adresse);
    }

    /**
     * L'adresse du siège que le répertoire Sirene diffuse, null quand il
     * la masque (diffusion partielle), ne la donne pas ou ne répond pas.
     */
    private function siegeAuRepertoire(string $siren): ?AdressePostale
    {
        try {
            $unite = $this->repertoire->unite($siren);
            $siege = $unite?->siretDuSiege() === null ? null : $this->repertoire->etablissement($unite->siretDuSiege());
        } catch (SireneIndisponibleException) {
            return null;
        }

        if ($unite === null || $siege === null || $unite->diffusionPartielle || $siege->diffusionPartielle || blank($siege->adresse) || blank($siege->codePostal) || blank($siege->ville)) {
            return null;
        }

        return new AdressePostale($unite->denomination, $siege->adresse, $siege->complementAdresse, $siege->codePostal, $siege->ville, AdressePostale::SOURCE_SIRENE);
    }
}
