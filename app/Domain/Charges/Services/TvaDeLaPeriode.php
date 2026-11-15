<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ChargesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\VentesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * La TVA à payer d'une société, réelle et estimée, par mois (décision de
 * l'éditeur du 14/11/2026, lot CHARGES) : chaque vente et chaque achat
 * dans le mois de son magasin (son calendrier), chaque charge dans son
 * mois de TVA (voir MontantsDeTva) :
 * - la TVA collectée : les ventes nettes des avoirs, plus l'autoliquidation
 *   des charges validées d'un fournisseur établi hors de France ;
 * - la TVA déductible des achats de marchandises (factures d'achat validées
 *   moins avoirs fournisseur), et celle des charges validées ;
 * - la TVA à payer réelle : la collectée moins ces déductibles ;
 * - la TVA à payer estimée : la réelle, avec aussi les charges à valider.
 * Les montants sont ceux des documents et des charges, en centimes,
 * additionnés ici, jamais par une somme de la base sur une colonne
 * décimale. Une société en franchise en base n'a pas de TVA : « TVA non
 * applicable ». L'état de TVA officiel (CA3, exigibilité exacte) viendra
 * avec la comptabilité (étape 5), qui reprendra les charges validées.
 *
 * @phpstan-import-type DocumentDeVente from VentesDuTableauDeBord
 * @phpstan-import-type DocumentDAchat from AchatsDuTableauDeBord
 * @phpstan-import-type ChargeDeLaTva from ChargesDuTableauDeBord
 */
final class TvaDeLaPeriode
{
    public function __construct(
        private readonly VentesDuTableauDeBord $ventes,
        private readonly AchatsDuTableauDeBord $achats,
        private readonly ChargesDuTableauDeBord $charges,
    ) {}

    /**
     * La TVA de chacun de ces mois, depuis ces documents déjà lus, et ces
     * charges (null : sans les charges), ceux de ces magasins seulement ;
     * les charges de « Toute la société » seulement quand elles sont
     * comptées : jamais la TVA déductible d'un magasin qui n'est pas compté,
     * ni celle de toute la société contre les ventes de quelques magasins.
     *
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  list<int>  $magasins
     * @param  list<array{document: string, magasin: int, jour: string, tva: int}>  $ventes
     * @param  list<array{document: string, magasin: int, jour: string, tva: int}>  $achats
     * @param  list<ChargeDeLaTva>|null  $charges
     * @return array<string, MontantsDeTva>
     */
    public static function parMois(array $mois, array $magasins, array $ventes, array $achats, ?array $charges, bool $avecCellesDeLaSociete = true): array
    {
        $permis = array_flip($magasins);
        $sommes = array_fill_keys($mois, ['ventes' => 0, 'achats' => 0, 'autoliquidee_validee' => 0, 'deductible_validee' => 0, 'autoliquidee_a_valider' => 0, 'deductible_a_valider' => 0]);

        foreach (['ventes' => $ventes, 'achats' => $achats] as $source => $documents) {
            foreach ($documents as $document) {
                $leMois = substr($document['jour'], 0, 7);

                if (isset($permis[$document['magasin']], $sommes[$leMois])) {
                    $sommes[$leMois][$source] += in_array($document['document'], ['avoir', 'avoir_fournisseur'], true) ? -$document['tva'] : $document['tva'];
                }
            }
        }

        foreach ($charges ?? [] as $charge) {
            $comptee = $charge['magasin'] === null ? $avecCellesDeLaSociete : isset($permis[$charge['magasin']]);

            if ($comptee && isset($sommes[$charge['mois']])) {
                $etat = $charge['validee'] ? 'validee' : 'a_valider';
                $sommes[$charge['mois']]['autoliquidee_'.$etat] += $charge['autoliquidee'];
                $sommes[$charge['mois']]['deductible_'.$etat] += $charge['deductible'];
            }
        }

        $montants = [];

        foreach ($sommes as $leMois => $somme) {
            $montants[$leMois] = new MontantsDeTva($somme['ventes'], $somme['autoliquidee_validee'], $somme['achats'], $somme['deductible_validee'], $somme['autoliquidee_a_valider'], $somme['deductible_a_valider'], $charges !== null);
        }

        return $montants;
    }

    /**
     * La TVA de ce mois « AAAA-MM » telle que ce périmètre la montre (voir
     * PerimetreDesGraphiques) : les ventes et les achats des magasins où il
     * voit tous ces documents, et les charges qu'il compte (voir
     * Perimetre::chargesComptees()) de ces magasins, et de « Toute la
     * société » quand tous ses magasins sont comptés ; null pour une
     * société en franchise en base (TVA non applicable). La page des
     * charges la montre pour le mois choisi.
     *
     * @throws LogicException un mois illisible
     */
    public function duPerimetre(Perimetre $perimetre, string $mois): ?MontantsDeTva
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $mois) !== 1) {
            throw new LogicException('Le mois s\'écrit « AAAA-MM ».');
        }

        if ($perimetre->societe->estEnFranchiseDeTva()) {
            return null;
        }

        [$du, $au] = [$mois.'-01', Calendrier::ajouterJours(Mois::decaler($mois, 1).'-01', -1)];
        $articles = $perimetre->societe->aDesArticles();
        $magasins = self::magasinsDeLaTva($perimetre);
        $ids = CleDuModele::desModeles($magasins);

        return self::parMois(
            [$mois],
            $ids,
            $magasins->isEmpty() ? [] : $this->ventes->documents($perimetre, $magasins, $du, $au),
            $magasins->isEmpty() || ! $articles ? [] : $this->achats->documents($perimetre, $magasins, $du, $au),
            $this->charges->pourLaTva($perimetre, [$mois]),
            $perimetre->compteLesChargesDeLaSociete($ids),
        )[$mois];
    }

    /**
     * Les magasins que compte la TVA de ce périmètre : ceux où il voit
     * toutes les ventes et, dans une société qui a des articles, tous les
     * achats.
     *
     * @return list<int>
     */
    public static function magasinsComptes(Perimetre $perimetre): array
    {
        return CleDuModele::desModeles(self::magasinsDeLaTva($perimetre));
    }

    /**
     * @return Collection<int, Magasin>
     */
    private static function magasinsDeLaTva(Perimetre $perimetre): Collection
    {
        return $perimetre->magasinsAvec(SourceDesGraphiques::Ventes, ...($perimetre->societe->aDesArticles() ? [SourceDesGraphiques::Achats] : []));
    }
}
