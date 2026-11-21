<?php

namespace App\Domain\Charges\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Facturation\Services\SaisieDecimale;
use InvalidArgumentException;

/**
 * Ce qu'une personne écrit d'une charge (modèle C2 ; voir
 * ChiffrageDUneCharge et les actions de App\Domain\Charges\Actions) : son
 * type (« piliqi:loyer », ou « societe:{uuid} » pour un type de la
 * société), son magasin (son uuid, ou aucun pour « Toute la société »), son
 * fournisseur, le numéro et la date de sa pièce, sa date de paiement, le
 * mois de TVA que le comptable fixe, l'autoliquidation d'un fournisseur
 * établi hors de France, le véhicule du carburant, une note, et ses lignes
 * par taux, en centimes : HT, et, quand la personne les recopie de la
 * pièce, TVA, TTC et TVA déductible ; sans ligne, la pièce est déposée
 * sans ses chiffres. Elle porte aussi les confirmations que l'écran a
 * demandées (écart de TVA au-delà d'un centime, doublon probable). Rien
 * n'y est encore vérifié contre la société : les actions le font.
 */
final readonly class SaisieDeCharge
{
    public const REFUS_DATE = 'La date de la pièce n\'est pas un jour valable.';

    public const REFUS_DATE_PAIEMENT = 'La date de paiement n\'est pas un jour valable.';

    public const REFUS_MOIS = 'Le mois de TVA s\'écrit « AAAA-MM ».';

    public const REFUS_TAUX = 'Ce taux de TVA n\'est pas proposé pour une charge.';

    public const REFUS_DEUX_LIGNES = 'Une charge n\'a qu\'une ligne par taux de TVA.';

    /**
     * @param  list<array{taux: TauxDeCharge, ht: int, tva: int|null, ttc: int|null, tva_deductible: int|null}>  $lignes
     */
    public function __construct(
        public string $type,
        public ?string $magasin,
        public ?string $fournisseur,
        public ?string $numeroPiece,
        public string $datePiece,
        public ?string $datePaiement,
        public ?string $moisTva,
        public bool $fournisseurHorsFrance,
        public bool $vehiculeUtilitaire,
        public ?string $note,
        public array $lignes,
        public bool $confirmeLesEcarts = false,
        public bool $confirmeLeDoublon = false,
    ) {
        if (Calendrier::jourValide($this->datePiece) === null) {
            throw new InvalidArgumentException(self::REFUS_DATE);
        }

        if ($this->datePaiement !== null && Calendrier::jourValide($this->datePaiement) === null) {
            throw new InvalidArgumentException(self::REFUS_DATE_PAIEMENT);
        }

        if ($this->moisTva !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $this->moisTva) !== 1) {
            throw new InvalidArgumentException(self::REFUS_MOIS);
        }

        $taux = array_map(fn (array $ligne): int => $ligne['taux']->value, $this->lignes);

        if (count($taux) !== count(array_unique($taux))) {
            throw new InvalidArgumentException(self::REFUS_DEUX_LIGNES);
        }
    }

    /**
     * La saisie d'un formulaire : les montants en euros, avec un point ou
     * une virgule, sans séparateur des milliers ; une ligne sans HT est
     * ignorée ; un champ vide est absent.
     *
     * @param  array<array-key, mixed>  $donnees
     *
     * @throws InvalidArgumentException
     */
    public static function duFormulaire(array $donnees, bool $confirmeLesEcarts = false, bool $confirmeLeDoublon = false): self
    {
        $lignes = [];

        foreach (is_array($donnees['lignes'] ?? null) ? $donnees['lignes'] : [] as $ligne) {
            if (! is_array($ligne) || self::texte($ligne['ht'] ?? null) === null) {
                continue;
            }

            $taux = TauxDeCharge::tryFrom((int) self::texte($ligne['taux'] ?? null)) ?? throw new InvalidArgumentException(self::REFUS_TAUX);
            $lignes[] = [
                'taux' => $taux,
                'ht' => self::montant($ligne['ht'] ?? null) ?? 0,
                'tva' => self::montant($ligne['tva'] ?? null),
                'ttc' => self::montant($ligne['ttc'] ?? null),
                'tva_deductible' => self::montant($ligne['tva_deductible'] ?? null),
            ];
        }

        return new self(
            type: self::texte($donnees['type'] ?? null) ?? '',
            magasin: self::texte($donnees['magasin'] ?? null),
            fournisseur: self::texte($donnees['fournisseur'] ?? null),
            numeroPiece: self::texte($donnees['numero_piece'] ?? null),
            datePiece: self::texte($donnees['date_piece'] ?? null) ?? '',
            datePaiement: self::texte($donnees['date_paiement'] ?? null),
            moisTva: self::texte($donnees['mois_tva'] ?? null),
            fournisseurHorsFrance: (bool) ($donnees['fournisseur_hors_france'] ?? false),
            vehiculeUtilitaire: (bool) ($donnees['vehicule_utilitaire'] ?? false),
            note: self::texte($donnees['note'] ?? null),
            lignes: $lignes,
            confirmeLesEcarts: $confirmeLesEcarts,
            confirmeLeDoublon: $confirmeLeDoublon,
        );
    }

    public function aDesChiffres(): bool
    {
        return $this->lignes !== [];
    }

    /**
     * Ce que le jeton d'une demande répétée compare (voir ActionsUniques).
     *
     * @return array<string, mixed>
     */
    public function signature(): array
    {
        return [
            'type' => $this->type,
            'magasin' => $this->magasin,
            'fournisseur' => $this->fournisseur,
            'numero_piece' => $this->numeroPiece,
            'date_piece' => $this->datePiece,
            'date_paiement' => $this->datePaiement,
            'mois_tva' => $this->moisTva,
            'fournisseur_hors_france' => $this->fournisseurHorsFrance,
            'vehicule_utilitaire' => $this->vehiculeUtilitaire,
            'note' => $this->note,
            'lignes' => array_map(fn (array $ligne): array => [...$ligne, 'taux' => $ligne['taux']->value], $this->lignes),
            'confirme_les_ecarts' => $this->confirmeLesEcarts,
            'confirme_le_doublon' => $this->confirmeLeDoublon,
        ];
    }

    private static function texte(mixed $valeur): ?string
    {
        $texte = is_scalar($valeur) ? trim((string) $valeur) : '';

        return $texte === '' ? null : $texte;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function montant(mixed $valeur): ?int
    {
        $texte = self::texte($valeur);

        if ($texte === null) {
            return null;
        }

        $centimes = SaisieDecimale::montantAuCentime(str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $texte));

        if ($centimes < 0) {
            throw new InvalidArgumentException('Un montant d\'une charge n\'est jamais négatif.');
        }

        return $centimes;
    }
}
