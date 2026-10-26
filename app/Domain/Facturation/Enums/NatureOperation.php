<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La nature d'une vente, choisie sur la facture ou le devis (I1) : elle
 * dit si la vente sort de la TVA française, et pourquoi. Par défaut, la
 * vente en France, au taux français ; une autre nature ne s'émet que si
 * toutes ses conditions sont réunies (voir ControleNatureOperation),
 * sinon la vente reste en France. Une seule nature par document, que
 * reprend l'avoir qui le corrige (voir CreerAvoirAction).
 *
 * Hors de la vente en France, aucune TVA française n'est facturée : un
 * produit se vend au prix TTC de son catalogue sans la TVA de son taux,
 * un service à son prix saisi sans TVA (K3, voir ContexteDeVente).
 *
 * Les mentions citent les articles du CGI en vigueur : elles passeront
 * aux articles du code des impositions sur les biens et services (CIBS)
 * une fois ceux-ci confirmés, comme celle de la franchise en base (voir
 * RegimeTva::MENTION_FRANCHISE).
 */
enum NatureOperation: string implements HasLabel
{
    case VenteEnFrance = 'vente_france';
    case ExportHorsUe = 'export_hors_ue';
    case LivraisonDom = 'livraison_dom';
    case LivraisonIntracommunautaire = 'livraison_intracommunautaire';
    case PrestationUeAutoliquidation = 'prestation_ue_autoliquidation';
    case PrestationHorsUe = 'prestation_hors_ue';

    public function getLabel(): string
    {
        return match ($this) {
            self::VenteEnFrance => 'Vente en France',
            self::ExportHorsUe => 'Export hors UE expédié par le magasin',
            self::LivraisonDom => 'Livraison vers un DOM',
            self::LivraisonIntracommunautaire => 'Livraison intracommunautaire à un professionnel',
            self::PrestationUeAutoliquidation => 'Prestation à un professionnel de l\'UE (autoliquidation)',
            self::PrestationHorsUe => 'Prestation à un professionnel hors UE',
        };
    }

    /**
     * La mention qu'imprime le document (art. 242 nonies A de l'annexe II
     * au CGI) : le texte qui exonère la vente, ou qui la place hors du
     * champ de la TVA française ; aucune pour une vente en France.
     */
    public function mention(): ?string
    {
        return match ($this) {
            self::VenteEnFrance => null,
            self::ExportHorsUe => 'Exonération de TVA, article 262 I du CGI',
            self::LivraisonDom => 'Exonération de TVA en application de l\'article 294 du Code général des impôts',
            self::LivraisonIntracommunautaire => 'Exonération de TVA, article 262 ter I du CGI',
            self::PrestationUeAutoliquidation => 'Autoliquidation',
            self::PrestationHorsUe => 'TVA non applicable – article 259-1° du CGI',
        };
    }

    /**
     * Ce que la vente doit réunir, en une phrase, pour l'aide du choix de
     * la nature (voir ChoisitLaNatureDeLOperation) ; le contrôle complet
     * se fait à l'émission (voir ControleNatureOperation).
     */
    public function conditions(): string
    {
        return match ($this) {
            self::VenteEnFrance => 'TVA française, quel que soit le client : tickets, remises en magasin, livraisons en France, en Corse ou à Monaco.',
            self::ExportHorsUe => 'Des biens expédiés par le magasin hors de l\'Union européenne, avec une adresse de livraison complète ; la preuve d\'export (MRN ou CN23) est à conserver.',
            self::LivraisonDom => 'Des biens expédiés vers la Guadeloupe, la Martinique, la Guyane, La Réunion ou Mayotte, avec une adresse de livraison complète.',
            self::LivraisonIntracommunautaire => 'Des biens livrés dans un autre État membre à un professionnel dont le n° de TVA de cet État est valide sur VIES le jour de l\'émission, avec l\'adresse et la date de livraison.',
            self::PrestationUeAutoliquidation => 'Des services à un professionnel établi dans un autre État membre, au n° de TVA valide sur VIES le jour de l\'émission ; il autoliquide la TVA de son pays.',
            self::PrestationHorsUe => 'Des services à un professionnel établi hors de l\'Union européenne, identifié par son n° de TVA ou un autre identifiant.',
        };
    }

    /**
     * La vente sort-elle de la TVA française ? Toute nature sauf la vente
     * en France : ses lignes sont à 0 %.
     */
    public function exonere(): bool
    {
        return $this !== self::VenteEnFrance;
    }

    /**
     * Une livraison de biens : le document ne vend que des biens et les
     * frais qui les accompagnent (port, emballage, assurance), qui suivent
     * leur nature (voir Service::frais_accessoires).
     */
    public function porteSurDesBiens(): bool
    {
        return in_array($this, [self::ExportHorsUe, self::LivraisonDom, self::LivraisonIntracommunautaire], true);
    }

    /**
     * Une prestation à un professionnel établi hors de France : le document
     * ne vend que des services dont le lieu suit celui du client (art.
     * 259-1° du CGI), jamais un service au lieu fixé par la loi (voir
     * Service::lieu_specifique).
     */
    public function porteSurDesServices(): bool
    {
        return in_array($this, [self::PrestationUeAutoliquidation, self::PrestationHorsUe], true);
    }

    /**
     * Une nature qui ne se vend qu'à un client professionnel : un
     * particulier ne peut choisir que la vente en France, l'export ou la
     * livraison vers un DOM.
     */
    public function reserveeAuxProfessionnels(): bool
    {
        return in_array($this, [self::LivraisonIntracommunautaire, self::PrestationUeAutoliquidation, self::PrestationHorsUe], true);
    }

    /**
     * Le n° de TVA du client doit être valide sur VIES le jour de
     * l'émission (I2, art. 262 ter I et 259-1° du CGI) : la consultation
     * retenue se conserve avec la facture.
     */
    public function exigeVies(): bool
    {
        return in_array($this, [self::LivraisonIntracommunautaire, self::PrestationUeAutoliquidation], true);
    }

    /**
     * Le document indique où les biens sont livrés, et comment : la
     * destination décide de l'exonération.
     */
    public function exigeUneLivraison(): bool
    {
        return $this->porteSurDesBiens();
    }

    /**
     * Qui transporte les biens, quand la nature l'impose : le magasin, ou
     * un transporteur pour son compte, pour un export ou une livraison
     * vers un DOM. Une livraison intracommunautaire se fait aussi par le
     * client ou son transporteur, qu'il faut alors préciser ; une vente en
     * France ou une prestation ne transporte rien.
     */
    public function transportImpose(): ?TransportPar
    {
        return in_array($this, [self::ExportHorsUe, self::LivraisonDom], true) ? TransportPar::Magasin : null;
    }

    /**
     * La nature telle qu'un formulaire la tient : la vente en France tant
     * qu'aucune nature connue n'est choisie.
     */
    public static function depuisSaisie(mixed $saisie): self
    {
        return $saisie instanceof self ? $saisie : (self::tryFrom(is_string($saisie) ? $saisie : '') ?? self::VenteEnFrance);
    }

    /**
     * Les natures qu'un client de ce type peut choisir : la vente en
     * France seule pour un magasin en franchise en base, qui ne facture
     * jamais de TVA, hors de France comme en France (sa franchise couvre
     * aussi ses ventes à l'étranger).
     *
     * @return list<self>
     */
    public static function proposees(bool $professionnel, bool $franchiseEnBase): array
    {
        if ($franchiseEnBase) {
            return [self::VenteEnFrance];
        }

        return array_values(array_filter(self::cases(), fn (self $nature): bool => $professionnel || ! $nature->reserveeAuxProfessionnels()));
    }
}
