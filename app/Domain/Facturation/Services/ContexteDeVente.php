<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\NatureOperation;
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
 * article quel que soit le client (B10) --, la nature de l'opération --
 * hors de la vente en France, tout se vend à 0 %, au prix sans la TVA
 * française (K3, voir retireLaTvaDuPrix()) -- et le taux proposé pour une
 * ligne de service sans taux saisi, celui du territoire du magasin (B2).
 *
 * Un brouillon de facture ou un devis note le type du client et la
 * franchise à chaque enregistrement (colonnes()), avec la nature qu'il
 * choisit (voir NoteSaNatureDOperation), toujours une nature que ce client
 * et ce régime permettent (voir pourEnregistrer()) : l'émission d'une
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
        public NatureOperation $natureOperation = NatureOperation::VenteEnFrance,
    ) {}

    /**
     * La vente qui s'enregistre maintenant pour ce client, dans ce magasin,
     * avec son régime de TVA d'aujourd'hui, de cette nature. Un client pas
     * encore choisi (formulaire en cours de saisie) compte comme un
     * particulier.
     */
    public static function pour(?Client $client, Magasin $magasin, NatureOperation $natureOperation = NatureOperation::VenteEnFrance): self
    {
        return new self(
            typeClient: $client->type_client ?? TypeClient::Particulier,
            franchiseEnBase: $magasin->estEnFranchiseDeTva(),
            tauxTvaParDefaut: $magasin->tauxTvaParDefaut(),
            natureOperation: $natureOperation,
        );
    }

    /**
     * La vente qu'enregistre un brouillon de facture ou un devis (voir
     * pour()), dans une nature que son client et le régime du magasin
     * permettent (voir NatureOperation::proposees()) : un magasin en
     * franchise en base ne vend qu'en France, sa franchise couvrant aussi
     * ses ventes hors de France, et un particulier n'achète qu'en France, à
     * l'export ou livré dans un DOM. Le formulaire ne propose que ces
     * natures, et rouvre en vente en France un document dont la nature
     * n'est plus permise (voir ChoisitLaNatureDeLOperation::natureARouvrir()) ;
     * une nature reçue d'ailleurs est refusée ici, avant que rien ne
     * s'enregistre. Un client ou un régime qui change ensuite est refusé à
     * l'émission comme à l'envoi (voir refuserSiLeTypeDeClientAChange() et
     * refuserSiLeRegimeAChange()) : un document ne part donc jamais dans
     * une nature interdite à son client ou à son magasin.
     *
     * @throws LogicException
     */
    public static function pourEnregistrer(Client $client, Magasin $magasin, NatureOperation $natureOperation): self
    {
        $contexte = self::pour($client, $magasin, $natureOperation);

        if (! in_array($natureOperation, NatureOperation::proposees($contexte->typeClient === TypeClient::Professionnel, $contexte->franchiseEnBase), true)) {
            throw new LogicException("Impossible d'enregistrer en « {$natureOperation->getLabel()} » : ".($contexte->franchiseEnBase
                ? 'votre magasin est en franchise en base de TVA, qui couvre aussi ses ventes hors de France. Choisissez « '.NatureOperation::VenteEnFrance->getLabel().' ».'
                : 'cette nature est réservée aux clients professionnels. Choisissez une autre nature.'));
        }

        return $contexte;
    }

    /**
     * Les lignes sont-elles à 0 % ? En franchise en base (B10), et hors de
     * la vente en France : une vente qui sort de la TVA française n'en
     * facture aucune.
     */
    public function exonere(): bool
    {
        return $this->franchiseEnBase || $this->natureOperation->exonere();
    }

    /**
     * Le client paie-t-il le prix sans la TVA française (K3) ? Hors de la
     * vente en France, hors franchise : un produit se vend au prix TTC de
     * son catalogue ÷ (1 + son taux), et un service à son prix saisi sans
     * TVA -- 1 000 articles à 1,00 € TTC à 20 % font 833,33 € pour un
     * professionnel, 830,00 € pour un particulier, à 0,83 € l'article. En
     * franchise, le client paie le même prix TTC, sans TVA, quelle que soit
     * la nature, dont seule la vente en France s'enregistre (voir
     * pourEnregistrer()).
     */
    public function retireLaTvaDuPrix(): bool
    {
        return self::sansTvaFrancaise($this->franchiseEnBase, $this->natureOperation);
    }

    /**
     * Le prix sans la TVA française avec lequel un document a été
     * enregistré (voir retireLaTvaDuPrix()), d'après la franchise et la
     * nature qu'il a notées : ses lignes de produit gardent le prix TTC de
     * leur catalogue et le taux retiré (taux_tva_du_prix), que ses PDF et
     * sa fiche impriment à côté de son taux de 0 % pour que son calcul se
     * vérifie (voir MentionsFiscales::calcul()).
     */
    public static function documentRetireLaTvaDuPrix(Facture|Avoir|Devis $document): bool
    {
        return self::sansTvaFrancaise($document->franchise_en_base, $document->nature_operation);
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
     * à un particulier, article par article. Hors de la vente en France, de
     * même, sans TVA (voir retireLaTvaDuPrix()). En franchise en base,
     * toutes les lignes sont à 0 % et le client paie le même prix TTC, sans
     * TVA (B10) : professionnel comme particulier, la vente se calcule
     * article par article, remise comprise -- 9,99 € remisés de 50 % font
     * 4,99 € pour l'un comme pour l'autre.
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

    private static function sansTvaFrancaise(bool $franchiseEnBase, NatureOperation $natureOperation): bool
    {
        return ! $franchiseEnBase && $natureOperation->exonere();
    }
}
