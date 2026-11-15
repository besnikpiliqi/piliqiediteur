<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use App\Domain\TableauDeBord\Services\ChargesDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\TableauDeBord\Services\VentesDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Auth\Access\AuthorizationException;
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
        private readonly PerimetreDesGraphiques $perimetres,
        private readonly VentesDuTableauDeBord $ventes,
        private readonly AchatsDuTableauDeBord $achats,
        private readonly ChargesDuTableauDeBord $charges,
    ) {}

    /**
     * La TVA de chacun de ces mois, depuis ces documents déjà lus, ceux de
     * ces magasins seulement, et ces charges (null : sans les charges).
     *
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  list<int>  $magasins
     * @param  list<array{document: string, magasin: int, jour: string, tva: int}>  $ventes
     * @param  list<array{document: string, magasin: int, jour: string, tva: int}>  $achats
     * @param  list<ChargeDeLaTva>|null  $charges
     * @return array<string, MontantsDeTva>
     */
    public static function parMois(array $mois, array $magasins, array $ventes, array $achats, ?array $charges): array
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
            if (isset($sommes[$charge['mois']])) {
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
     * La TVA de toute la société sur ce mois « AAAA-MM » : tous ses magasins
     * réels, toutes ses charges, comme son propriétaire les voit ; null pour
     * une société en franchise en base (TVA non applicable).
     *
     * @throws LogicException une société sans propriétaire, ou un mois illisible
     */
    public function deLaSociete(Societe $societe, string $mois): ?MontantsDeTva
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $mois) !== 1) {
            throw new LogicException('Le mois s\'écrit « AAAA-MM ».');
        }

        if ($societe->estEnFranchiseDeTva()) {
            return null;
        }

        $proprietaire = $societe->proprietaire()->first() ?? throw new LogicException('Une société sans propriétaire n\'a pas de TVA à lire.');
        $charges = $this->charges->deLaSociete($societe, [$mois]);

        try {
            $perimetre = $this->perimetres->deLaSociete($proprietaire, $societe);
        } catch (AuthorizationException) {
            return self::parMois([$mois], [], [], [], $charges)[$mois];
        }

        [$du, $au] = [$mois.'-01', Calendrier::ajouterJours(Mois::decaler($mois, 1).'-01', -1)];
        $magasins = $perimetre->magasinsAvec(SourceDesGraphiques::Ventes, ...($societe->aDesArticles() ? [SourceDesGraphiques::Achats] : []));

        return self::parMois(
            [$mois],
            CleDuModele::desModeles($magasins),
            $magasins->isEmpty() ? [] : $this->ventes->documents($perimetre, $magasins, $du, $au),
            $magasins->isEmpty() || ! $societe->aDesArticles() ? [] : $this->achats->documents($perimetre, $magasins, $du, $au),
            $charges,
        )[$mois];
    }
}
