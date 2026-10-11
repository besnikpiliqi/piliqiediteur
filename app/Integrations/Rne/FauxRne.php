<?php

namespace App\Integrations\Rne;

use App\Integrations\EnvoiRecommande\AdressePostale;
use App\Integrations\Rne\Contracts\RegistreNationalDesEntreprises;

/**
 * Le registre national des entreprises factice du développement et des
 * tests, sans aucune requête (voir VerificationServiceProvider) : toute
 * entreprise y est présidée par Jeanne DUPONT, née en janvier 1980, en
 * fonction depuis le 1er janvier 2020 (celle que la vérification
 * d'identité factice vérifie par défaut, voir FausseVerificationIdentite),
 * avec son siège au 1 rue de la République, 75001 Paris.
 *
 * Un test règle ses propres réponses (voir dirigeants(), domicilier(),
 * rendreInjoignable()) sur l'instance du conteneur.
 */
class FauxRne implements RegistreNationalDesEntreprises
{
    private bool $injoignable = false;

    /** @var array<string, list<RepresentantRne>> */
    private array $dirigeants = [];

    /** @var array<string, AdressePostale|null> */
    private array $sieges = [];

    public function representants(string $siren): array
    {
        $this->repondre();

        return $this->dirigeants[$siren] ?? [self::presidente()];
    }

    public function adresseDuSiege(string $siren): ?AdressePostale
    {
        $this->repondre();

        return array_key_exists($siren, $this->sieges)
            ? $this->sieges[$siren]
            : new AdressePostale("ENTREPRISE {$siren}", '1 RUE DE LA REPUBLIQUE', null, '75001', 'PARIS', AdressePostale::SOURCE_RNE);
    }

    /**
     * La présidente par défaut de toute entreprise.
     */
    public static function presidente(): RepresentantRne
    {
        return new RepresentantRne('DUPONT', null, ['Jeanne'], '1980-01', 'Présidente', true, null, '2020-01-01');
    }

    /**
     * Les dirigeants de cette entreprise.
     *
     * @param  list<RepresentantRne>  $dirigeants
     */
    public function dirigeants(string $siren, array $dirigeants): void
    {
        $this->dirigeants[$siren] = $dirigeants;
    }

    /**
     * Le siège de cette entreprise, ou aucun.
     */
    public function domicilier(string $siren, ?AdressePostale $siege): void
    {
        $this->sieges[$siren] = $siege;
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    /**
     * @throws RneIndisponibleException
     */
    private function repondre(): void
    {
        if ($this->injoignable) {
            throw new RneIndisponibleException;
        }
    }
}
