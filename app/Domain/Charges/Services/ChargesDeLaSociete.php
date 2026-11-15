<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Enums\EtatDeLaCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Exceptions\ConfirmationRequiseException;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\LigneDeCharge;
use App\Domain\Charges\Support\AccesAuxCharges;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les lectures des charges d'une société, pour un compte (voir
 * AccesAuxCharges) : toujours hors du scope de la société courante, filtrées
 * par la société de l'accès et par les magasins qu'il voit, et limitées
 * aux colonnes nommées. La page d'un client dans l'espace d'un cabinet les
 * lit ainsi, comme l'espace de la société et le panneau d'un magasin.
 * - le tableau « type de charge × mois » (modèle C1) : une case par type
 *   et par mois, avec le nombre de ses charges et l'état le moins avancé,
 *   en une requête ; un type sans charge de l'année n'a pas de ligne, sauf
 *   les types habituels de la société ;
 * - les doublons : à la saisie, les charges du même type déjà saisies pour
 *   le même mois (un avertissement) ; à l'enregistrement, une seconde
 *   charge du même fournisseur avec le même numéro de pièce est refusée,
 *   et une charge sans numéro du même fournisseur, du même mois et du même
 *   TTC qu'une autre demande une confirmation ;
 * - ce que le journal d'audit retient d'une charge (voir valeurs()).
 */
final class ChargesDeLaSociete
{
    public const REFUS_DOUBLON = 'Une charge de ce fournisseur porte déjà ce numéro de pièce dans la société : elle est déjà saisie.';

    public function __construct(
        private readonly TypesDeCharge $types,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * Les charges que l'accès voit.
     *
     * @return Builder<Charge>
     */
    public function visibles(AccesAuxCharges $acces): Builder
    {
        return $acces->filtrer(Charge::query()->withoutGlobalScope(SocieteScope::class));
    }

    /**
     * La charge de cet uuid, relue parmi celles que l'accès voit ; null
     * pour une charge d'une autre société, d'un magasin qu'il ne voit pas,
     * ou qui n'existe plus.
     */
    public function trouver(AccesAuxCharges $acces, string $uuid): ?Charge
    {
        return $this->visibles($acces)->parUuid($uuid)->first();
    }

    /**
     * Le tableau des mois : pour chaque type qui a une charge sur ces mois
     * (ou habituel), son libellé et, par mois, le nombre de ses charges et
     * l'état le moins avancé ; dans l'ordre de la liste de PILIQI, puis les
     * types de la société par nom. Une seule requête pour les cases.
     *
     * @param  list<string>  $mois  « AAAA-MM »
     * @return array<string, array{libelle: string, mois: array<string, array{nombre: int, etat: EtatDeLaCharge}>}>
     */
    public function tableauDesMois(AccesAuxCharges $acces, array $mois, ?int $magasin = null): array
    {
        $cases = $this->visibles($acces)
            ->leftJoin('types_de_charge_de_la_societe', fn (JoinClause $jointure): JoinClause => $jointure
                ->on('types_de_charge_de_la_societe.id', '=', 'charges.type_de_la_societe_id')
                ->on('types_de_charge_de_la_societe.societe_id', '=', 'charges.societe_id'))
            ->whereIn('charges.mois_tva', $mois)
            ->when($magasin !== null, fn (Builder $requete): Builder => $requete->where('charges.magasin_id', $magasin))
            ->groupBy('charges.type', 'types_de_charge_de_la_societe.uuid', 'types_de_charge_de_la_societe.nom', 'types_de_charge_de_la_societe.nom_cle', 'charges.mois_tva', 'charges.etat')
            ->toBase()
            ->get([
                'charges.type',
                'types_de_charge_de_la_societe.uuid as type_uuid',
                'types_de_charge_de_la_societe.nom as type_nom',
                'types_de_charge_de_la_societe.nom_cle as type_cle',
                'charges.mois_tva',
                'charges.etat',
                DB::raw('COUNT(*) AS nombre'),
            ]);

        $lignes = [];
        $ordre = [];

        foreach ($this->types->habituels($acces->societe) as $habituel) {
            $lignes[TypesDeCharge::PILIQI.$habituel->value] = ['libelle' => $habituel->getLabel(), 'mois' => []];
        }

        foreach ($cases as $case) {
            $type = ValeursLues::texteOuNul($case->type);
            $cle = $type !== null ? TypesDeCharge::PILIQI.$type : TypesDeCharge::SOCIETE.ValeursLues::texte($case->type_uuid);
            $libelle = $type !== null ? (TypeDeCharge::tryFrom($type)?->getLabel() ?? $type) : ValeursLues::texte($case->type_nom);
            $ordre[$cle] = $type !== null ? sprintf('0|%03d', array_search(TypeDeCharge::from($type), TypeDeCharge::cases(), true)) : '1|'.ValeursLues::texte($case->type_cle);
            $lignes[$cle] ??= ['libelle' => $libelle, 'mois' => []];
            $leMois = ValeursLues::texte($case->mois_tva);
            $etat = EtatDeLaCharge::from(ValeursLues::texte($case->etat));
            $avant = $lignes[$cle]['mois'][$leMois] ?? null;
            $lignes[$cle]['mois'][$leMois] = [
                'nombre' => ($avant['nombre'] ?? 0) + ValeursLues::entier($case->nombre),
                'etat' => $avant === null || $etat->rang() < $avant['etat']->rang() ? $etat : $avant['etat'],
            ];
        }

        foreach (array_keys($lignes) as $cle) {
            $ordre[$cle] ??= str_starts_with($cle, TypesDeCharge::PILIQI)
                ? sprintf('0|%03d', array_search(TypeDeCharge::from(substr($cle, strlen(TypesDeCharge::PILIQI))), TypeDeCharge::cases(), true))
                : '1|'.$cle;
        }

        uksort($lignes, fn (string $une, string $autre): int => $ordre[$une] <=> $ordre[$autre]);

        return $lignes;
    }

    /**
     * Les charges du même type déjà saisies pour ce mois, parmi celles que
     * l'accès voit, sauf celle-ci : l'avertissement de la fenêtre « Ajouter
     * une charge » (modèle C2).
     *
     * @return list<array{etat: EtatDeLaCharge, fournisseur: string|null, numero_piece: string|null}>
     */
    public function dejaSaisiesCeMois(AccesAuxCharges $acces, string $cleDuType, string $mois, ?int $sauf = null): array
    {
        try {
            [$type, $propre] = $this->types->resoudre($acces->societe, $cleDuType);
        } catch (InvalidArgumentException) {
            return [];
        }

        return $this->visibles($acces)
            ->where('charges.mois_tva', $mois)
            ->when($type !== null, fn (Builder $requete): Builder => $requete->where('charges.type', $type?->value))
            ->when($propre !== null, fn (Builder $requete): Builder => $requete->where('charges.type_de_la_societe_id', $propre?->getKey()))
            ->when($sauf !== null, fn (Builder $requete): Builder => $requete->whereKeyNot($sauf))
            ->orderBy('charges.id')
            ->get(['charges.id', 'charges.etat', 'charges.fournisseur', 'charges.numero_piece'])
            ->map(fn (Charge $charge): array => ['etat' => $charge->etat, 'fournisseur' => $charge->fournisseur, 'numero_piece' => $charge->numero_piece])
            ->values()
            ->all();
    }

    /**
     * Refuse une seconde charge du même fournisseur avec le même numéro de
     * pièce dans la société, toutes les charges comptées, même celles que
     * le compte ne voit pas : le refus ne dit rien d'elles. L'index unique
     * le garantit aussi, sous une course.
     *
     * @throws InvalidArgumentException
     */
    public function refuserLeDoublon(int $societe, ?string $fournisseurCle, ?string $numeroCle, ?int $sauf): void
    {
        if ($fournisseurCle === null || $numeroCle === null) {
            return;
        }

        $existe = Charge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe)
            ->where('fournisseur_cle', $fournisseurCle)
            ->where('numero_piece_cle', $numeroCle)
            ->when($sauf !== null, fn (Builder $requete): Builder => $requete->whereKeyNot($sauf))
            ->exists();

        if ($existe) {
            throw new InvalidArgumentException(self::REFUS_DOUBLON);
        }
    }

    /**
     * Demande une confirmation pour une charge sans numéro de pièce du même
     * fournisseur, du même mois de TVA et du même TTC qu'une autre de la
     * société : un doublon probable.
     *
     * @throws ConfirmationRequiseException
     */
    public function demanderLaConfirmationDuDoublon(int $societe, ?string $fournisseurCle, ?string $numeroCle, string $mois, int $ttc, ?int $sauf, bool $confirme): void
    {
        if ($confirme || $fournisseurCle === null || $numeroCle !== null) {
            return;
        }

        $existe = Charge::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $societe)
            ->where('fournisseur_cle', $fournisseurCle)
            ->where('mois_tva', $mois)
            ->where('total_ttc', $ttc)
            ->when($sauf !== null, fn (Builder $requete): Builder => $requete->whereKeyNot($sauf))
            ->exists();

        if ($existe) {
            throw new ConfirmationRequiseException(ConfirmationRequiseException::DOUBLON, 'Une charge de ce fournisseur, du même mois et du même montant ('.$this->calculateur->enEuros($ttc).' TTC) est déjà saisie. '.ConfirmationRequiseException::CONSIGNE_DOUBLON);
        }
    }

    /**
     * Ce qui désigne une charge au journal d'audit et dans un message.
     *
     * @return array{uuid: string, type: string, mois_tva: string, fournisseur: string|null, numero_piece: string|null}
     */
    public static function designation(Charge $charge): array
    {
        return [
            'uuid' => $charge->uuid,
            'type' => $charge->libelleDuType(),
            'mois_tva' => $charge->mois_tva,
            'fournisseur' => $charge->fournisseur,
            'numero_piece' => $charge->numero_piece,
        ];
    }

    /**
     * Tout ce que le journal d'audit retient d'une charge, avant et après
     * un geste, ses lignes relues, montants en centimes.
     *
     * @return array<string, mixed>
     */
    public static function valeurs(Charge $charge): array
    {
        return [
            'charge' => self::designation($charge),
            'etat' => $charge->etat->value,
            'magasin' => $charge->magasin_id === null ? null : ['code_magasin' => $charge->magasin?->code, 'nom' => $charge->magasin?->nom],
            'type' => $charge->libelleDuType(),
            'fournisseur' => $charge->fournisseur,
            'numero_piece' => $charge->numero_piece,
            'date_piece' => $charge->date_piece,
            'date_paiement' => $charge->date_paiement,
            'mois_tva' => $charge->mois_tva,
            'fournisseur_hors_france' => $charge->fournisseur_hors_france,
            'vehicule_utilitaire' => $charge->vehicule_utilitaire,
            'note' => $charge->note,
            'lignes' => LigneDeCharge::query()->withoutGlobalScope(SocieteScope::class)
                ->where('societe_id', $charge->societe_id)
                ->where('charge_id', $charge->getKey())
                ->orderBy('rang')
                ->get()
                ->map(fn (LigneDeCharge $ligne): array => ['taux' => $ligne->taux->value, 'ht' => $ligne->ht, 'tva' => $ligne->tva, 'ttc' => $ligne->ttc, 'tva_deductible' => $ligne->tva_deductible, 'tva_a_verifier' => $ligne->tva_a_verifier])
                ->values()
                ->all(),
            'total_ht' => $charge->total_ht,
            'total_tva' => $charge->total_tva,
            'total_ttc' => $charge->total_ttc,
            'total_tva_deductible' => $charge->total_tva_deductible,
            'tva_a_verifier' => $charge->tva_a_verifier,
            'validee_par' => $charge->validee_par?->value,
        ];
    }
}
