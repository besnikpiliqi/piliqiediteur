<?php

namespace App\Integrations\Sirene;

use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Integrations\Sirene\Contracts\RepertoireSirene;

/**
 * Le répertoire Sirene factice du développement et des tests, sans aucune
 * requête (voir VerificationServiceProvider) : toute entreprise d'un SIREN
 * valide y est active, une société par actions simplifiée, et tout
 * établissement d'un SIRET valide y est actif, rattaché à son SIREN, à
 * Paris (75001, commune 75056) ; celui du NIC 0001 est son siège. Ses établissements actifs
 * (voir etablissementsActifs()) sont son siège et, sauf pour un
 * entrepreneur individuel, sa boutique (NIC 0002, enseigne
 * ENSEIGNE_DE_LA_BOUTIQUE), avec ceux qu'un test y ajoute, sans ceux qui
 * sont fermés, oubliés ou rattachés à une autre entreprise ; aucun pour
 * une entreprise cessée ou inconnue.
 * Font exception, pour essayer les refus :
 * - SIREN_CESSEE, une entreprise cessée ;
 * - SIREN_INCONNU, une entreprise que le répertoire ne connaît pas ;
 * - SIREN_INJOIGNABLE, pour laquelle le répertoire ne répond pas ;
 * - SIREN_DIFFUSION_PARTIELLE, une entreprise en diffusion partielle :
 *   ses établissements n'ont ni adresse ni code postal, seulement leur
 *   commune ;
 * - SIREN_ENTREPRENEUR_INDIVIDUEL, un entrepreneur individuel ;
 * - tout établissement dont le NIC commence par 9, fermé.
 *
 * Un test règle en plus ses propres réponses (voir fermer(), cesser(),
 * oublier(), deplacer(), situerHorsDeFrance(), nommer(),
 * ajouterUnEtablissement(), rattacherA(), listerEnPartie(),
 * rendreInjoignable()) sur l'instance du conteneur.
 */
class FauxRepertoireSirene implements RepertoireSirene
{
    public const SIREN_CESSEE = '000000992';

    public const SIREN_INCONNU = '000000984';

    public const SIREN_INJOIGNABLE = '000000976';

    public const SIREN_DIFFUSION_PARTIELLE = '000000968';

    public const SIREN_ENTREPRENEUR_INDIVIDUEL = '000000950';

    public const ENSEIGNE_DE_LA_BOUTIQUE = 'BOUTIQUE REPUBLIQUE';

    private bool $injoignable = false;

    private bool $enPartie = false;

    /** @var array<string, true> */
    private array $cessees = [];

    /** @var array<string, true> */
    private array $fermes = [];

    /** @var array<string, true> */
    private array $inconnus = [];

    /** @var array<string, array{0: string, 1: string, 2: string}> le code postal, la ville et la commune de chaque établissement déplacé, par SIRET */
    private array $adresses = [];

    /** @var array<string, true> */
    private array $horsDeFrance = [];

    /** @var array<string, string> */
    private array $denominations = [];

    /** @var array<string, string|null> l'enseigne de chaque établissement ajouté, par SIRET */
    private array $ajoutes = [];

    /** @var array<string, string> le SIREN auquel le répertoire rattache ces établissements, par SIRET */
    private array $rattachements = [];

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
        $horsDeFrance = isset($this->horsDeFrance[$siret]);

        return new EtablissementSirene(
            siret: $siret,
            siren: $this->rattachements[$siret] ?? $siren,
            actif: ! str_starts_with(substr($siret, 9), '9') && ! isset($this->fermes[$siret]),
            adresse: $diffusionPartielle ? null : '1 RUE DE LA REPUBLIQUE',
            complementAdresse: null,
            codePostal: $diffusionPartielle || $horsDeFrance ? null : ($this->adresses[$siret][0] ?? '75001'),
            codeCommune: $horsDeFrance ? null : ($this->adresses[$siret][2] ?? '75056'),
            ville: $horsDeFrance ? null : ($this->adresses[$siret][1] ?? 'PARIS'),
            diffusionPartielle: $diffusionPartielle,
            enseigne: match (true) {
                array_key_exists($siret, $this->ajoutes) => $this->ajoutes[$siret],
                $siret === self::siretDeLaBoutique($siren) => self::ENSEIGNE_DE_LA_BOUTIQUE,
                default => null,
            },
            estSiege: $siret === self::siretDuSiege($siren),
        );
    }

    public function etablissementsActifs(string $siren): EtablissementsActifs
    {
        $unite = $this->unite($siren);

        if ($unite === null || ! $unite->active) {
            return new EtablissementsActifs([], true);
        }

        $sirets = [
            self::siretDuSiege($siren),
            ...($siren === self::SIREN_ENTREPRENEUR_INDIVIDUEL ? [] : [self::siretDeLaBoutique($siren)]),
            ...array_filter(array_keys($this->ajoutes), static fn (string $siret): bool => str_starts_with($siret, $siren)),
        ];
        $actifs = array_values(array_filter(
            array_map($this->etablissement(...), array_unique($sirets)),
            static fn (?EtablissementSirene $etablissement): bool => $etablissement !== null && $etablissement->actif && $etablissement->estRattacheA($siren),
        ));
        usort($actifs, static fn (EtablissementSirene $un, EtablissementSirene $autre): int => [! $un->estSiege, $un->nic()] <=> [! $autre->estSiege, $autre->nic()]);

        return $this->enPartie
            ? new EtablissementsActifs(array_slice($actifs, 0, 1), false)
            : new EtablissementsActifs($actifs, true);
    }

    /**
     * Le SIRET du siège d'une entreprise fictive : son SIREN suivi du NIC
     * 0001 et de sa clé.
     */
    public static function siretDuSiege(string $siren): string
    {
        return IdentifiantsInsee::avecSaCle($siren.'0001');
    }

    /**
     * Le SIRET de la boutique d'une entreprise fictive : son SIREN suivi
     * du NIC 0002 et de sa clé.
     */
    public static function siretDeLaBoutique(string $siren): string
    {
        return IdentifiantsInsee::avecSaCle($siren.'0002');
    }

    public function fermer(string $siret): void
    {
        $this->fermes[$siret] = true;
    }

    /**
     * Un établissement situé à ce code postal, dans cette ville, de ce
     * code de commune (code officiel géographique : 97209 pour
     * Fort-de-France). En diffusion partielle, le répertoire ne donne que
     * sa commune.
     */
    public function deplacer(string $siret, string $codePostal, string $ville, string $codeCommune): void
    {
        $this->adresses[$siret] = [$codePostal, $ville, $codeCommune];
    }

    /**
     * Un établissement situé à l'étranger : le répertoire ne lui donne ni
     * commune française, ni code postal, ni ville.
     */
    public function situerHorsDeFrance(string $siret): void
    {
        $this->horsDeFrance[$siret] = true;
    }

    /**
     * Une entreprise que le répertoire nomme ainsi.
     */
    public function nommer(string $siren, string $denomination): void
    {
        $this->denominations[$siren] = $denomination;
    }

    /**
     * Un établissement de plus parmi les établissements actifs de son
     * entreprise, avec cette enseigne.
     */
    public function ajouterUnEtablissement(string $siret, ?string $enseigne): void
    {
        $this->ajoutes[$siret] = $enseigne;
    }

    /**
     * Un établissement que le répertoire rattache à une autre entreprise
     * que celle de son SIREN.
     */
    public function rattacherA(string $siret, string $siren): void
    {
        $this->rattachements[$siret] = $siren;
    }

    /**
     * Le répertoire ne rend plus que le premier des établissements actifs
     * d'une entreprise, comme pour une entreprise qui en compte plus qu'il
     * n'en rend en une fois.
     */
    public function listerEnPartie(bool $enPartie = true): void
    {
        $this->enPartie = $enPartie;
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
