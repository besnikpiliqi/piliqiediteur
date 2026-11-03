<?php

namespace App\Integrations\Ordre;

use App\Integrations\Ordre\Contracts\AnnuaireDeLOrdre;
use App\Integrations\Sirene\FauxRepertoireSirene;

/**
 * L'annuaire de l'Ordre des experts-comptables factice du développement et
 * des tests, automatique et sans aucune requête (voir
 * VerificationServiceProvider) : le cabinet de test du répertoire Sirene
 * factice (FauxRepertoireSirene::SIREN_CABINET) y est inscrit, sous le
 * n° NUMERO_DU_CABINET_DE_TEST, au conseil régional d'Île-de-France ;
 * toute autre société y est inconnue.
 *
 * Un test règle ses propres réponses (voir inscrire(), radier(),
 * rendreInjoignable()) sur l'instance du conteneur, que les requêtes d'un
 * même test partagent.
 */
class FauxAnnuaireDeLOrdre implements AnnuaireDeLOrdre
{
    public const NUMERO_DU_CABINET_DE_TEST = '01-23456';

    public const CONSEIL_DU_CABINET_DE_TEST = 'Île-de-France';

    private bool $injoignable = false;

    /** @var array<string, InscriptionALOrdre> */
    private array $inscriptions = [];

    public function __construct()
    {
        $this->inscrire(FauxRepertoireSirene::SIREN_CABINET, self::NUMERO_DU_CABINET_DE_TEST, self::CONSEIL_DU_CABINET_DE_TEST);
    }

    public function estAutomatique(): bool
    {
        return true;
    }

    /**
     * @throws AnnuaireDeLOrdreIndisponibleException
     */
    public function inscription(string $siren): ?InscriptionALOrdre
    {
        if ($this->injoignable) {
            throw new AnnuaireDeLOrdreIndisponibleException;
        }

        return $this->inscriptions[$siren] ?? null;
    }

    /**
     * Inscrit au tableau la société de ce SIREN.
     */
    public function inscrire(string $siren, string $numero, string $conseilRegional): void
    {
        $this->inscriptions[$siren] = new InscriptionALOrdre($siren, null, $numero, $conseilRegional, true);
    }

    /**
     * Radie du tableau la société de ce SIREN : l'annuaire la connaît
     * encore, non inscrite.
     */
    public function radier(string $siren): void
    {
        $inscription = $this->inscriptions[$siren] ?? new InscriptionALOrdre($siren, null, '00-00000', self::CONSEIL_DU_CABINET_DE_TEST, true);
        $this->inscriptions[$siren] = new InscriptionALOrdre($siren, $inscription->denomination, $inscription->numero, $inscription->conseilRegional, false);
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }
}
