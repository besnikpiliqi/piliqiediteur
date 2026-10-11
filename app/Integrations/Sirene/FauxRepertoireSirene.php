<?php

namespace App\Integrations\Sirene;

use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Integrations\Sirene\Contracts\RepertoireSirene;

/**
 * Le répertoire Sirene factice du développement et des tests, sans aucune
 * requête (voir VerificationServiceProvider) : toute entreprise d'un SIREN
 * valide y est active, une société par actions simplifiée, et tout
 * établissement d'un SIRET valide y est actif, rattaché à son SIREN, à
 * Paris. Font exception, pour essayer les refus :
 * - SIREN_CESSEE, une entreprise cessée ;
 * - SIREN_INCONNU, une entreprise que le répertoire ne connaît pas ;
 * - SIREN_INJOIGNABLE, pour laquelle le répertoire ne répond pas ;
 * - SIREN_DIFFUSION_PARTIELLE, une entreprise en diffusion partielle ;
 * - SIREN_ENTREPRENEUR_INDIVIDUEL, un entrepreneur individuel ;
 * - tout établissement dont le NIC commence par 9, fermé.
 *
 * Un test règle en plus ses propres réponses (voir fermer(), cesser(),
 * oublier(), deplacer(), nommer(), rendreInjoignable()) sur l'instance du
 * conteneur.
 */
class FauxRepertoireSirene implements RepertoireSirene
{
    public const SIREN_CESSEE = '000000992';

    public const SIREN_INCONNU = '000000984';

    public const SIREN_INJOIGNABLE = '000000976';

    public const SIREN_DIFFUSION_PARTIELLE = '000000968';

    public const SIREN_ENTREPRENEUR_INDIVIDUEL = '000000950';

    private bool $injoignable = false;

    /** @var array<string, true> */
    private array $cessees = [];

    /** @var array<string, true> */
    private array $fermes = [];

    /** @var array<string, true> */
    private array $inconnus = [];

    /** @var array<string, array{0: string, 1: string}> */
    private array $adresses = [];

    /** @var array<string, string> */
    private array $denominations = [];

    public function unite(string $siren): ?UniteSirene
    {
        $this->repondre($siren);

        if (! IdentifiantsInsee::sirenValide($siren) || $siren === self::SIREN_INCONNU || isset($this->inconnus[$siren])) {
            return null;
        }

        $diffusionPartielle = $siren === self::SIREN_DIFFUSION_PARTIELLE;
        $entrepreneurIndividuel = $siren === self::SIREN_ENTREPRENEUR_INDIVIDUEL;

        return new UniteSirene(
            siren: $siren,
            active: $siren !== self::SIREN_CESSEE && ! isset($this->cessees[$siren]),
            denomination: match (true) {
                $diffusionPartielle => null,
                isset($this->denominations[$siren]) => $this->denominations[$siren],
                $entrepreneurIndividuel => 'JEANNE DUPONT',
                default => "SOCIETE FICTIVE {$siren}",
            },
            categorieJuridique: $entrepreneurIndividuel ? '1000' : '5710',
            nicDuSiege: substr(self::siretDuSiege($siren), 9),
            diffusionPartielle: $diffusionPartielle,
        );
    }

    public function etablissement(string $siret): ?EtablissementSirene
    {
        $siren = substr($siret, 0, 9);
        $this->repondre($siren);

        if (! IdentifiantsInsee::siretValide($siret) || $siren === self::SIREN_INCONNU || isset($this->inconnus[$siren]) || isset($this->inconnus[$siret])) {
            return null;
        }

        $diffusionPartielle = $siren === self::SIREN_DIFFUSION_PARTIELLE;

        return new EtablissementSirene(
            siret: $siret,
            siren: $siren,
            actif: ! str_starts_with(substr($siret, 9), '9') && ! isset($this->fermes[$siret]),
            adresse: $diffusionPartielle ? null : '1 RUE DE LA REPUBLIQUE',
            complementAdresse: null,
            codePostal: $diffusionPartielle ? null : ($this->adresses[$siret][0] ?? '75001'),
            ville: $this->adresses[$siret][1] ?? 'PARIS',
            diffusionPartielle: $diffusionPartielle,
        );
    }

    /**
     * Le SIRET du siège d'une entreprise fictive : son SIREN suivi du NIC
     * 0001 et de sa clé.
     */
    public static function siretDuSiege(string $siren): string
    {
        return IdentifiantsInsee::avecSaCle($siren.'0001');
    }

    public function fermer(string $siret): void
    {
        $this->fermes[$siret] = true;
    }

    /**
     * Un établissement situé à ce code postal, dans cette ville.
     */
    public function deplacer(string $siret, string $codePostal, string $ville): void
    {
        $this->adresses[$siret] = [$codePostal, $ville];
    }

    /**
     * Une entreprise que le répertoire nomme ainsi.
     */
    public function nommer(string $siren, string $denomination): void
    {
        $this->denominations[$siren] = $denomination;
    }

    public function cesser(string $siren): void
    {
        $this->cessees[$siren] = true;
    }

    /**
     * Un SIREN ou un SIRET que le répertoire ne connaît plus.
     */
    public function oublier(string $numero): void
    {
        $this->inconnus[$numero] = true;
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    /**
     * @throws SireneIndisponibleException
     */
    private function repondre(string $siren): void
    {
        if ($this->injoignable || $siren === self::SIREN_INJOIGNABLE) {
            throw new SireneIndisponibleException;
        }
    }
}
