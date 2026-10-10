<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use LogicException;

/**
 * Ce qui, hors des lignes elles-mêmes, décide du calcul d'une vente (voir
 * ConstructeurLigneDocument) : le type du client -- article par article
 * pour un particulier, depuis le HT de chaque ligne pour un professionnel
 * (E1, voir calculeDepuisLeHtDeLigne()) --, le régime de TVA du magasin --
 * en franchise en base, tout se vend à 0 % au même prix TTC, article par
 * article quel que soit le client (B10) -- et le taux proposé pour une
 * ligne de service sans taux saisi, celui du territoire du magasin (B2).
 *
 * Un brouillon de facture ou un devis note le type du client et la
 * franchise à chaque enregistrement (colonnes()) : l'émission d'une
 * facture comme l'envoi d'un devis refuse un document dont le client a
 * changé de type depuis (voir refuserSiLeTypeDeClientAChange()), ou dont
 * le magasin a changé de régime depuis (voir refuserSiLeRegimeAChange()).
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
     * Un brouillon de facture ou un devis part avec le type de client noté
     * à son enregistrement : si la fiche du client est devenue celle d'un
     * professionnel, ou d'un particulier, depuis, ses lignes sont à
     * recalculer pour ce type (E1), en l'enregistrant de nouveau. Sinon, un
     * professionnel recevrait un devis calculé article par article et une
     * facture calculée depuis le HT de ses lignes, qui ne coûterait pas le
     * prix accepté (1 000 x 1,00 € à 20 % : 830,00 € HT sur le devis,
     * 833,33 € HT sur la facture) ; et une facture partirait dans la série
     * des tickets, ou un ticket dans celle des factures (voir
     * Facture::serie()).
     *
     * @throws LogicException
     */
    public static function refuserSiLeTypeDeClientAChange(Facture|Devis $document): void
    {
        $typeActuel = $document->client->type_client;

        if ($typeActuel !== $document->type_client) {
            $nouveauType = $typeActuel === TypeClient::Professionnel ? 'professionnel' : 'particulier';

            throw new LogicException("Ce client est devenu {$nouveauType} depuis l'enregistrement du brouillon : ouvrez-le et enregistrez-le de nouveau.");
        }
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
     * Une vente à un professionnel se calcule depuis le HT de chaque ligne,
     * quantité x PU TTC ÷ (1 + taux) arrondi une fois, et la TVA de chaque
     * taux sur le total HT de ses lignes (voir CalculateurLigne) ; une vente
     * à un particulier, article par article. En franchise en base, toutes
     * les lignes sont à 0 % et le client paie le même prix TTC, sans TVA
     * (B10) : professionnel comme particulier, la vente se calcule article
     * par article, remise comprise -- 9,99 € remisés de 50 % font 4,99 €
     * pour l'un comme pour l'autre.
     */
    public function calculeDepuisLeHtDeLigne(): bool
    {
        return self::depuisLeHtDeLigne($this->typeClient, $this->franchiseEnBase);
    }

    /**
     * Le calcul avec lequel un document a été enregistré, d'après le type
     * de client et la franchise qu'il a notés (voir colonnes()), et non
     * d'après la fiche du client ou le régime d'aujourd'hui : ses retours
     * se calculent de même (voir LigneFacture::montantsDuRetour()), et sa
     * note de calcul l'explique (voir MentionsFiscales::calcul()).
     */
    public static function documentCalculeDepuisLeHtDeLigne(Facture|Avoir|Devis $document): bool
    {
        return self::depuisLeHtDeLigne($document->type_client, $document->franchise_en_base);
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

    private static function depuisLeHtDeLigne(TypeClient $typeClient, bool $franchiseEnBase): bool
    {
        return $typeClient === TypeClient::Professionnel && ! $franchiseEnBase;
    }
}
