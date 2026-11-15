<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Charges\Support\CleDeComparaison;
use App\Domain\Charges\Support\SaisieDeCharge;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * L'écriture d'une saisie sur une charge (voir SaisieDeCharge), commune
 * au dépôt, à la saisie des chiffres et à la correction : son magasin,
 * parmi ceux que l'accès voit (un responsable n'en a qu'un, le sien), son
 * type, proposé dans la société, son fournisseur, sa pièce, ses dates, le
 * mois de TVA proposé (voir PropositionsDeTva) ou fixé par qui valide, et
 * ses lignes chiffrées (voir ChiffrageDUneCharge) ; une seconde charge du
 * même fournisseur avec le même numéro de pièce est refusée, une charge
 * sans numéro du même fournisseur, du même mois et du même TTC demande une
 * confirmation (voir ChargesDeLaSociete).
 *
 * @phpstan-import-type ChargeChiffree from ChiffrageDUneCharge
 */
final class EcritureDUneCharge
{
    public const REFUS_MAGASIN = 'Ce magasin n\'est pas proposé pour cette charge.';

    public const REFUS_SANS_MAGASIN = 'Choisissez le magasin de cette charge.';

    public const REFUS_FOURNISSEUR = 'Écrivez le fournisseur de la pièce avec ses chiffres.';

    public function __construct(
        private readonly TypesDeCharge $types,
        private readonly ChiffrageDUneCharge $chiffrage,
        private readonly ChargesDeLaSociete $charges,
    ) {}

    /**
     * Remplit la charge depuis la saisie, sans l'enregistrer, et rend ses
     * lignes chiffrées ; null quand la saisie n'a pas de chiffres.
     *
     * @return ChargeChiffree|null
     *
     * @throws ConfirmationRequiseException
     * @throws InvalidArgumentException
     */
    public function remplir(AccesAuxCharges $acces, Charge $charge, SaisieDeCharge $saisie): ?array
    {
        $societe = CleDuModele::de($acces->societe);
        [$type, $propre] = $this->types->resoudre($acces->societe, $saisie->type, $charge->type_de_la_societe_id);
        $nature = TypesDeCharge::natureDe([$type, $propre]);
        $fournisseur = $saisie->fournisseur === null ? null : Str::limit(Str::squish($saisie->fournisseur), 120, '');
        $numero = $saisie->numeroPiece === null ? null : Str::limit(Str::squish($saisie->numeroPiece), 60, '');

        if ($saisie->aDesChiffres() && $fournisseur === null) {
            throw new InvalidArgumentException(self::REFUS_FOURNISSEUR);
        }

        $charge->forceFill([
            'magasin_id' => $this->magasin($acces, $saisie->magasin),
            'type' => $type,
            'type_de_la_societe_id' => $propre?->getKey(),
            'fournisseur' => $fournisseur,
            'fournisseur_cle' => CleDeComparaison::ouNul($fournisseur),
            'numero_piece' => $numero,
            'numero_piece_cle' => CleDeComparaison::ouNul($numero),
            'date_piece' => $saisie->datePiece,
            'date_paiement' => $saisie->datePaiement,
            'fournisseur_hors_france' => $saisie->fournisseurHorsFrance,
            'vehicule_utilitaire' => $type === TypeDeCharge::Carburant ? $saisie->vehiculeUtilitaire : null,
            'note' => $saisie->note,
        ]);

        if ($acces->valide() && $saisie->moisTva !== null) {
            $charge->forceFill(['mois_tva' => $saisie->moisTva, 'mois_tva_fixe' => true]);
        } elseif (! $charge->mois_tva_fixe || $acces->valide()) {
            $charge->forceFill(['mois_tva' => PropositionsDeTva::moisDeTva($nature, $saisie->datePiece, $saisie->datePaiement), 'mois_tva_fixe' => false]);
        }

        $chiffree = $saisie->aDesChiffres()
            ? $this->chiffrage->chiffrer($saisie->lignes, $type, (bool) $charge->vehicule_utilitaire, $saisie->fournisseurHorsFrance, $acces->valide(), $saisie->confirmeLesEcarts)
            : null;

        $charge->forceFill([
            'total_ht' => $chiffree['total_ht'] ?? 0,
            'total_tva' => $chiffree['total_tva'] ?? 0,
            'total_ttc' => $chiffree['total_ttc'] ?? 0,
            'total_tva_deductible' => $chiffree['total_tva_deductible'] ?? 0,
            'tva_a_verifier' => $chiffree['tva_a_verifier'] ?? false,
        ]);

        $sauf = $charge->exists ? CleDuModele::de($charge) : null;
        $this->charges->refuserLeDoublon($societe, $charge->fournisseur_cle, $charge->numero_piece_cle, $sauf);

        if ($chiffree !== null) {
            $this->charges->demanderLaConfirmationDuDoublon($societe, $charge->fournisseur_cle, $charge->numero_piece_cle, $charge->mois_tva, $chiffree['total_ttc'], $sauf, $saisie->confirmeLeDoublon);
        }

        return $chiffree;
    }

    /**
     * Écrit les lignes chiffrées de la charge enregistrée, de cette version
     * (0 avant toute correction), après avoir effacé celles qui la
     * précèdent ; aucune pour une saisie sans chiffres.
     *
     * @param  ChargeChiffree|null  $chiffree
     */
    public function ecrireLesLignes(Charge $charge, ?array $chiffree, int $version): void
    {
        LigneDeCharge::query()->where('charge_id', $charge->getKey())->delete();

        foreach ($chiffree['lignes'] ?? [] as $ligne) {
            (new LigneDeCharge)->forceFill([...$ligne, 'charge_id' => $charge->getKey(), 'version' => $version])->save();
        }
    }

    /**
     * Le magasin d'une charge : null pour « Toute la société », quand
     * l'accès la voit ; le seul magasin d'un responsable quand il n'en
     * choisit pas ; sinon un magasin réel de la société que l'accès voit.
     *
     * @throws InvalidArgumentException
     */
    private function magasin(AccesAuxCharges $acces, ?string $uuid): ?int
    {
        if ($uuid === null) {
            return match (true) {
                $acces->chargesDeLaSociete => null,
                $acces->magasins !== null && count($acces->magasins) === 1 => $acces->magasins[0],
                default => throw new InvalidArgumentException(self::REFUS_SANS_MAGASIN),
            };
        }

        $magasin = Magasin::query()
            ->where('societe_id', $acces->societe->getKey())
            ->where('est_demonstration', false)
            ->where('uuid', Str::isUuid($uuid) ? $uuid : null)
            ->first(['id']);

        if ($magasin === null || ! $acces->voitLeMagasin(CleDuModele::de($magasin))) {
            throw new InvalidArgumentException(self::REFUS_MAGASIN);
        }

        return CleDuModele::de($magasin);
    }
}
