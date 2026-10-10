<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use LogicException;

/**
 * Ce qui, hors des lignes elles-mêmes, décide du calcul d'une vente (voir
 * ConstructeurLigneDocument) : le type du client, le régime de TVA du
 * magasin -- en franchise en base, tout se vend à 0 % au même prix TTC
 * (B10) -- et le taux proposé pour une ligne de service sans taux saisi,
 * celui du territoire du magasin (B2).
 *
 * Un brouillon de facture ou un devis note le type du client et la
 * franchise à chaque enregistrement (colonnes()) : l'émission d'une
 * facture refuse un brouillon dont le type de client a changé depuis (voir
 * EmettreFactureAction), et celle d'une facture comme l'envoi d'un devis,
 * un document dont le régime du magasin a changé depuis (voir
 * refuserSiLeRegimeAChange()).
 */
final readonly class ContexteDeVente
{
    public function __construct(
        public TypeClient $typeClient,
        public bool $franchiseEnBase,
        public float $tauxTvaParDefaut,
    ) {}

    /**
     * La vente qui s'enregistre maintenant pour ce client, dans ce magasin,
     * avec son régime de TVA d'aujourd'hui. Un client pas encore choisi
     * (formulaire en cours de saisie) compte comme un particulier.
     */
    public static function pour(?Client $client, Magasin $magasin): self
    {
        return new self(
            typeClient: $client->type_client ?? TypeClient::Particulier,
            franchiseEnBase: $magasin->estEnFranchiseDeTva(),
            tauxTvaParDefaut: $magasin->tauxTvaParDefaut(),
        );
    }

    /**
     * Un brouillon de facture ou un devis part avec la franchise notée à
     * son enregistrement : si le magasin est entré en franchise en base, ou
     * en est sorti, depuis, ses lignes sont à recalculer, à 0 % ou à leurs
     * taux, en l'enregistrant de nouveau. Sinon, un magasin en franchise
     * enverrait un document avec de la TVA et sans la mention, ou un
     * magasin redevable un document à 0 % avec la mention (B10).
     *
     * @throws LogicException
     */
    public static function refuserSiLeRegimeAChange(Facture|Devis $document): void
    {
        if ($document->franchise_en_base !== $document->magasin->estEnFranchiseDeTva()) {
            throw new LogicException('Le régime de TVA du magasin a changé depuis l\'enregistrement de ce brouillon : ouvrez-le et enregistrez-le de nouveau pour recalculer ses lignes.');
        }
    }

    /**
     * Les colonnes qu'un brouillon de facture ou un devis enregistre avec
     * ses lignes.
     *
     * @return array{type_client: TypeClient, franchise_en_base: bool}
     */
    public function colonnes(): array
    {
        return [
            'type_client' => $this->typeClient,
            'franchise_en_base' => $this->franchiseEnBase,
        ];
    }
}
