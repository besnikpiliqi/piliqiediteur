<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use InvalidArgumentException;

/**
 * Le pied fixé en bas de chaque page d'un PDF de vente (point 4), au-dessus
 * de « Page x sur y » (voir MiseEnPageA4) : l'identité légale de la société
 * sur une ligne (voir Emetteur::lignesDeLaSociete()), puis, une par ligne,
 * les conditions de paiement saisies sur une facture ou un devis, les
 * mentions entre professionnels d'une facture à un professionnel
 * (pénalités, indemnité forfaitaire, escompte), les mentions fiscales du
 * document (catégorie de l'opération, exonération, franchise ou option
 * pour les débits, voir MentionsFiscales) et la mention du bon d'avoir
 * d'un avoir fait au client comptoir. Le magasin qui vend reste en tête
 * (voir Emetteur::lignesDeLEtablissement()).
 *
 * Le pied d'une page ne recouvre jamais ses lignes : la marge du bas de la
 * page lui réserve sa hauteur (voir hauteurEnMillimetres()), comptée
 * largement d'après la longueur de ses textes.
 */
final readonly class PiedDePage
{
    /**
     * La plus longue saisie des conditions de paiement d'une facture ou
     * d'un devis, en caractères : comme chacune des mentions du magasin,
     * elle s'imprime dans le pied de chaque page, qui doit laisser aux
     * lignes leur place sur la page.
     */
    public const CARACTERES_DES_CONDITIONS_DE_PAIEMENT = 500;

    /**
     * Les caractères comptés par ligne imprimée : en corps 7 sur la largeur
     * d'une page A4 moins ses marges, une ligne en tient 125 en majuscules
     * larges, 150 en chiffres et 200 en texte courant (mesure du lot S8-P),
     * et le retour à la ligne entre deux mots en perd quelques-uns.
     */
    private const CARACTERES_PAR_LIGNE = 110;

    /**
     * La hauteur d'une ligne imprimée en corps 7, en dixièmes de
     * millimètre (2,96 mm mesurés).
     */
    private const DIXIEMES_PAR_LIGNE = 30;

    /**
     * Le filet et l'espace au-dessus du texte, en dixièmes de millimètre.
     */
    private const DIXIEMES_DU_FILET = 20;

    /**
     * @param  string  $identite  l'identité de la société, sur une ligne
     * @param  list<string>  $lignes  les conditions de paiement et les mentions, dans l'ordre où elles s'impriment
     */
    public function __construct(
        public string $identite,
        public array $lignes,
    ) {}

    /**
     * Le pied du document, d'après l'émetteur qu'il imprime : celui qu'il
     * a signé une fois émis (voir FigeSonEmetteur), la société et le
     * magasin d'aujourd'hui pour un brouillon ou un devis.
     */
    public static function pour(Facture|Avoir|Devis $document, Emetteur $emetteur): self
    {
        return new self(
            implode(' · ', $emetteur->lignesDeLaSociete()),
            array_values(array_filter([
                $document instanceof Avoir ? null : $document->conditions_paiement,
                ...($document instanceof Facture && $document->estPourUnProfessionnel() ? array_values($emetteur->mentionsEntreProfessionnels) : []),
                ...app(MentionsFiscales::class)->pour($document, $emetteur),
                $document instanceof Avoir ? $document->mentionDuBonDAvoir() : null,
            ], filled(...))),
        );
    }

    /**
     * Refuse des conditions de paiement plus longues que
     * CARACTERES_DES_CONDITIONS_DE_PAIEMENT, avant que la facture ou le
     * devis ne s'enregistre : le même refus sur tous les moteurs de base
     * de données, leur colonne étant un texte libre.
     *
     * @throws InvalidArgumentException
     */
    public static function verifierLesConditionsDePaiement(mixed $conditions): void
    {
        if (is_string($conditions) && mb_strlen($conditions) > self::CARACTERES_DES_CONDITIONS_DE_PAIEMENT) {
            throw new InvalidArgumentException('Les conditions de paiement comptent '.self::CARACTERES_DES_CONDITIONS_DE_PAIEMENT.' caractères au plus : elles s\'impriment dans le pied de chaque page du document.');
        }
    }

    /**
     * La hauteur que le pied réserve en bas de chaque page, en millimètres
     * entiers : son filet et une ligne imprimée par tranche de
     * CARACTERES_PAR_LIGNE caractères de chacun de ses textes.
     */
    public function hauteurEnMillimetres(): int
    {
        $lignesImprimees = array_sum(array_map(
            fn (string $texte): int => max(1, (int) ceil(mb_strlen($texte) / self::CARACTERES_PAR_LIGNE)),
            [$this->identite, ...$this->lignes],
        ));

        return (int) ceil((self::DIXIEMES_DU_FILET + $lignesImprimees * self::DIXIEMES_PAR_LIGNE) / 10);
    }
}
