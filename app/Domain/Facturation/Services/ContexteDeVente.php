<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Models\Magasin;

/**
 * Ce qui, hors des lignes elles-mêmes, décide du calcul d'une vente (voir
 * ConstructeurLigneDocument) : le type du client, le régime de TVA du
 * magasin -- en franchise en base, tout se vend à 0 % au même prix TTC
 * (B10) -- et le taux proposé pour une ligne de service sans taux saisi,
 * celui du territoire du magasin (B2).
 *
 * Un brouillon de facture ou un devis note le type du client et la
 * franchise à chaque enregistrement (colonnes()) : l'émission refuse un
 * brouillon dont le type de client ou le régime du magasin a changé depuis
 * (voir EmettreFactureAction).
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
