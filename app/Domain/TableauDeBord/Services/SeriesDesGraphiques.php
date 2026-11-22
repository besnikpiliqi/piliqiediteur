<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\AchatsParProduit;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\ConstructeurDeSeriesParPeriode;
use App\Domain\TableauDeBord\Support\DefinitionDeSerie;
use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\HistoriqueDuStock;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
use App\Domain\TableauDeBord\Support\SommesParJour;
use App\Domain\TableauDeBord\Support\TextesDesPeriodes;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ce que dessine chaque carte du tableau de bord (voir SerieDuGraphique),
 * pour un périmètre et des réglages (voir Perimetre et
 * ReglagesDuTableauDeBord) : chaque source se lit une seule fois pour
 * toutes les cartes qui en ont besoin (voir VentesDuTableauDeBord et les
 * suivants), sur les magasins où le compte la voit, puis chaque carte ne
 * compte que les magasins où il voit toutes ses sources (voir
 * Perimetre::magasinsDe()), et dit les autres sans les nommer.
 *
 * Tout se compte en centimes, depuis les montants enregistrés sur les
 * documents émis ou validés, additionnés ici, jamais recalculés ; chaque
 * opération dans le jour, le mois et l'année de son magasin. Une barre
 * encore à venir n'a pas de valeur (null). Une société en franchise en
 * base n'a pas de TVA à estimer (voir Perimetre::voit()). Les cartes par
 * période (voir Graphique::estParPeriode()) se construisent barre par barre
 * (voir ConstructeurDeSeriesParPeriode), chacune avec les séries de sa
 * définition (voir DefinitionDeSerie) ; « Ventes par vendeur » additionne le
 * chiffre d'affaires HT net de chaque compte qui a vendu.
 *
 * La carte « Écart des prix d'achat » (décision de l'éditeur du 21/11/2026,
 * D76) lit les lignes des factures d'achat validées, une fois pour la page
 * (voir lignesDAchat()), sur les magasins où le compte voit les achats, et
 * rend, pour les douze mois de la période, le calcul de la carte (voir
 * EcartDesPrixDAchat) : elle n'a ni jeu ni valeur par mois, et ne tient
 * aucun compte de la comparaison avec l'année précédente.
 *
 * @phpstan-import-type DocumentDeVente from VentesDuTableauDeBord
 * @phpstan-import-type LigneDeVente from VentesDuTableauDeBord
 * @phpstan-import-type VenteDUnCompte from VentesDuTableauDeBord
 * @phpstan-import-type DocumentDAchat from AchatsDuTableauDeBord
 * @phpstan-import-type LigneDAchatDuTableau from AchatsDuTableauDeBord
 * @phpstan-import-type ChargeDeLaTva from ChargesDuTableauDeBord
 */
final class SeriesDesGraphiques
{
    /** Le nombre de produits, de clients et de fournisseurs classés. */
    public const CLASSES = 10;

    private const JOURS_DE_LA_SEMAINE = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];

    private const ANCIENNETES = ['a_echoir' => 'À échoir', 'de_1_a_30' => '1 à 30 j de retard', 'de_31_a_60' => '31 à 60 j de retard', 'plus_de_60' => 'Plus de 60 j de retard'];

    /**
     * La note du graphique de la TVA et du chiffre clé pour qui ne voit pas
     * les charges de la société.
     */
    public const SANS_LES_CHARGES = 'Sans les charges de la société, que vous ne voyez pas : la TVA déductible ne compte que les achats.';

    /**
     * La note de la TVA quand un magasin n'est pas compté : les charges de
     * « Toute la société » ne le sont pas non plus.
     */
    public const SANS_LES_CHARGES_DE_LA_SOCIETE = 'Les charges de « Toute la société » ne sont pas comptées, puisqu\'un magasin ne l\'est pas : seules celles des magasins comptés le sont.';

    public function __construct(
        private readonly VentesDuTableauDeBord $ventes,
        private readonly AchatsDuTableauDeBord $achats,
        private readonly PaiementsDuTableauDeBord $paiements,
        private readonly FacturesDuTableauDeBord $factures,
        private readonly StockDuTableauDeBord $stock,
        private readonly ChargesDuTableauDeBord $charges,
    ) {}

    /**
     * Les séries des cartes de ce calcul (voir CalculDesSeries), par
     * graphique.
     *
     * @return array<string, SerieDuGraphique>
     *
     * @throws AuthorizationException une carte que le périmètre ne permet pas
     */
    public function pour(CalculDesSeries $calcul): array
    {
        $perimetre = $calcul->perimetre;
        $series = [];

        foreach ($calcul->cartes as $carte) {
            if (! $perimetre->voit($carte->graphique)) {
                throw new AuthorizationException(PerimetreDesGraphiques::REFUS);
            }

            $serie = $this->serie($carte, $perimetre->magasinsDe($carte->graphique), $calcul);
            $notes = array_filter([$perimetre->phraseDesMagasinsNonComptes(...$carte->graphique->sources($perimetre->societe->aDesArticles())), $serie->note]);
            $series[$carte->graphique->value] = $serie->avecLaNote($notes === [] ? null : implode(' ', $notes));
        }

        return $series;
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function serie(CarteDuTableauDeBord $carte, Collection $magasins, CalculDesSeries $calcul): SerieDuGraphique
    {
        $ids = CleDuModele::desModeles($magasins);

        return match ($carte->graphique) {
            Graphique::ChiffreDAffairesParMois => ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
                new DefinitionDeSerie('Chiffre d\'affaires HT', $this->sommeDesVentes($calcul, $ids), principale: true),
            ]),
            Graphique::VentesEtAchatsParMois => ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
                new DefinitionDeSerie('Ventes HT', $this->sommeDesVentes($calcul, $ids), principale: true),
                new DefinitionDeSerie('Achats HT', self::somme($calcul->sommesParJour($this->documentsDAchat($calcul), $ids, fn (array $document): int => $calcul->signe($document) * $document['ht']))),
            ]),
            Graphique::TvaAPayerParMois => $this->tvaParPeriode($calcul, $carte, $ids),
            Graphique::MargeBruteParMois => $this->margeParPeriode($calcul, $carte, $magasins),
            Graphique::ChiffreDAffairesParMagasin => $this->chiffreDAffairesParMagasin($calcul, $magasins),
            Graphique::VentesParTauxDeTva => $this->ventesParTaux($calcul, $ids),
            Graphique::ParticuliersEtProfessionnels => $this->particuliersEtProfessionnels($calcul, $ids),
            Graphique::EncaissementsParMode => $this->encaissementsParMode($calcul, $ids),
            Graphique::MeilleursProduits => $this->meilleursProduits($calcul, $magasins, $carte->parQuantite()),
            Graphique::MeilleursClients => $this->meilleursClients($calcul, $magasins),
            Graphique::VentesParVendeur => $this->ventesParVendeur($calcul, $magasins),
            Graphique::AchatsParFournisseur => $this->achatsParFournisseur($calcul, $magasins),
            Graphique::FacturesParAnciennete => $this->anciennete($calcul, $magasins),
            Graphique::ValeurDuStock => $this->valeurDuStock($calcul, $carte, $magasins),
            Graphique::NombreDeVentesEtPanierMoyen => $this->nombreDeVentesEtPanierMoyen($calcul, $carte, $ids),
            Graphique::AvoirsParMois => ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
                new DefinitionDeSerie('Avoirs HT', self::somme($calcul->sommesParJour($this->avoirs($calcul), $ids, fn (array $document): int => $document['ht'])), principale: true),
            ]),
            Graphique::VentesParJourDeLaSemaine => $this->ventesParJour($calcul, $ids),
            Graphique::ArticlesEtServices => $this->articlesEtServices($calcul, $ids),
            Graphique::EcartDesPrixDAchat => $this->ecartDesPrixDAchat($calcul, $magasins),
        };
    }

    /**
     * Ce que valent, sur un intervalle de jours, les ventes HT nettes des
     * avoirs de ces magasins.
     *
     * @param  list<int>  $ids
     * @return Closure(string, string, NiveauDeLaBarre): int
     */
    private function sommeDesVentes(CalculDesSeries $calcul, array $ids): Closure
    {
        return self::somme($calcul->sommesParJour($this->documentsDeVente($calcul), $ids, fn (array $document): int => $calcul->signe($document) * $document['ht']));
    }

    /**
     * Ce que ces sommes valent sur un intervalle de jours, quel que soit le
     * niveau de la barre.
     *
     * @return Closure(string, string, NiveauDeLaBarre): int
     */
    private static function somme(SommesParJour $sommes): Closure
    {
        return fn (string $du, string $au, NiveauDeLaBarre $niveau): int => $sommes->entre($du, $au);
    }

    /**
     * La TVA à payer par période (voir TvaDeLaPeriode) : la TVA collectée,
     * la TVA déductible des achats et, pour qui voit les charges de la
     * société, celle des charges validées, puis la TVA à payer réelle, en
     * ligne, la série principale de la carte, et, avec les charges, son
     * estimation avec les charges à valider, en pointillés ; sans les
     * charges, la note le dit.
     *
     * @param  list<int>  $ids
     */
    private function tvaParPeriode(CalculDesSeries $calcul, CarteDuTableauDeBord $carte, array $ids): SerieDuGraphique
    {
        $tva = $this->tvaDeLIntervalle($calcul, $ids);
        $collectee = new DefinitionDeSerie('TVA collectée', self::deLaTva($tva, fn (MontantsDeTva $montants): int => $montants->collectee()));
        $reelle = new DefinitionDeSerie('TVA à payer', self::deLaTva($tva, fn (MontantsDeTva $montants): int => $montants->reelle()), principale: true, enLigne: true);

        if ($this->chargesDeLaTva($calcul) === null) {
            return ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
                $collectee,
                new DefinitionDeSerie('TVA déductible', self::deLaTva($tva, fn (MontantsDeTva $montants): int => $montants->deductible())),
                $reelle,
            ], noteDeLaCarte: self::SANS_LES_CHARGES);
        }

        return ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
            $collectee,
            new DefinitionDeSerie('TVA déductible des achats', self::deLaTva($tva, fn (MontantsDeTva $montants): int => $montants->deductibleDesAchats)),
            new DefinitionDeSerie('TVA déductible des charges', self::deLaTva($tva, fn (MontantsDeTva $montants): int => $montants->deductibleDesChargesValidees)),
            $reelle,
            new DefinitionDeSerie('TVA à payer (estimation)', self::deLaTva($tva, fn (MontantsDeTva $montants): int => $montants->estimee()), enLigne: true, pointilles: true),
        ], noteDuJour: TextesDesPeriodes::TVA_DES_CHARGES_PAR_JOUR, noteDeLaCarte: $calcul->perimetre->laisseLesChargesDeLaSociete($ids) ? self::SANS_LES_CHARGES_DE_LA_SOCIETE : null);
    }

    /**
     * Ce que vaut, sur un intervalle de jours, un montant de la TVA de cet
     * intervalle.
     *
     * @param  Closure(string, string, NiveauDeLaBarre): MontantsDeTva  $tva
     * @param  Closure(MontantsDeTva): int  $montant
     * @return Closure(string, string, NiveauDeLaBarre): int
     */
    private static function deLaTva(Closure $tva, Closure $montant): Closure
    {
        return fn (string $du, string $au, NiveauDeLaBarre $niveau): int => $montant($tva($du, $au, $niveau));
    }

    /**
     * La TVA de ces magasins sur un intervalle de jours : la TVA des
     * ventes (nette de celle des avoirs) et des achats (nette de celle des
     * avoirs fournisseur) de ces jours, additionnée ici depuis les montants
     * des documents, et, pour une barre d'un mois au moins, celle des
     * charges des mois qu'elle touche (voir TvaDeLaPeriode::parMois()) :
     * une charge est d'un mois de TVA, jamais d'un jour, et le détail d'un
     * mois par jour ne la compte pas. Une société sans articles n'a pas
     * d'achats.
     *
     * @param  list<int>  $ids
     * @return Closure(string, string, NiveauDeLaBarre): MontantsDeTva
     */
    private function tvaDeLIntervalle(CalculDesSeries $calcul, array $ids): Closure
    {
        $ventes = $calcul->sommesParJour($this->documentsDeVente($calcul), $ids, fn (array $document): int => $calcul->signe($document) * $document['tva']);
        $achats = $calcul->perimetre->societe->aDesArticles()
            ? $calcul->sommesParJour($this->documentsDAchat($calcul), $ids, fn (array $document): int => $calcul->signe($document) * $document['tva'])
            : SommesParJour::deJours([]);
        $charges = $this->chargesDeLaTva($calcul);
        $avecCellesDeLaSociete = $calcul->perimetre->compteLesChargesDeLaSociete($ids);
        $memo = [];

        return function (string $du, string $au, NiveauDeLaBarre $niveau) use ($ventes, $achats, $charges, $avecCellesDeLaSociete, $ids, &$memo): MontantsDeTva {
            $cle = "{$du}|{$au}|{$niveau->value}";

            if (isset($memo[$cle])) {
                return $memo[$cle];
            }

            $autoliquideeValidee = $deductibleValidee = $autoliquideeAValider = $deductibleAValider = 0;

            if ($charges !== null && $niveau !== NiveauDeLaBarre::Jour) {
                foreach (TvaDeLaPeriode::parMois(Mois::du(Mois::duJour($du), Mois::duJour($au)), $ids, [], [], $charges, $avecCellesDeLaSociete) as $desCharges) {
                    $autoliquideeValidee += $desCharges->autoliquideeValidee;
                    $deductibleValidee += $desCharges->deductibleDesChargesValidees;
                    $autoliquideeAValider += $desCharges->autoliquideeAValider;
                    $deductibleAValider += $desCharges->deductibleDesChargesAValider;
                }
            }

            return $memo[$cle] = new MontantsDeTva($ventes->entre($du, $au), $autoliquideeValidee, $achats->entre($du, $au), $deductibleValidee, $autoliquideeAValider, $deductibleAValider, $charges !== null);
        };
    }

    /**
     * La TVA de ces mois, sur ces magasins, avec les charges que compte le
     * périmètre, de ces magasins seulement, et celles de « Toute la
     * société » quand tous ses magasins sont comptés (voir
     * TvaDeLaPeriode::parMois()).
     *
     * @param  list<int>  $ids
     * @param  list<string>  $mois
     * @return array<string, MontantsDeTva>
     */
    public function tvaDesMois(CalculDesSeries $calcul, array $ids, array $mois): array
    {
        return TvaDeLaPeriode::parMois(
            $mois,
            $ids,
            $this->documentsDeVente($calcul),
            $calcul->perimetre->societe->aDesArticles() ? $this->documentsDAchat($calcul) : [],
            $this->chargesDeLaTva($calcul),
            $calcul->perimetre->compteLesChargesDeLaSociete($ids),
        );
    }

    /**
     * Les charges que compte la TVA de la page, lues une fois sur tous ses
     * mois ; null pour qui ne voit pas les charges.
     *
     * @return list<ChargeDeLaTva>|null
     */
    public function chargesDeLaTva(CalculDesSeries $calcul): ?array
    {
        if (! $calcul->chargesDeLaTvaLues) {
            $calcul->chargesDeLaTva = $this->charges->pourLaTva($calcul->perimetre, Mois::du(substr($calcul->premierJour(), 0, 7), substr($calcul->dernierJour(), 0, 7)));
            $calcul->chargesDeLaTvaLues = true;
        }

        return $calcul->chargesDeLaTva;
    }

    /**
     * La marge brute des articles suivis en stock : leurs ventes HT moins
     * leur coût, retours déduits, de chaque barre ; le taux de marge en
     * info-bulle.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function margeParPeriode(CalculDesSeries $calcul, CarteDuTableauDeBord $carte, Collection $magasins): SerieDuGraphique
    {
        $historique = $this->historiqueDuStock($calcul);
        $ids = CleDuModele::desModeles($magasins);

        return ConstructeurDeSeriesParPeriode::construire(
            $calcul,
            $carte,
            [new DefinitionDeSerie('Marge brute HT', fn (string $du, string $au, NiveauDeLaBarre $niveau): int => $historique->ventesEntre($ids, $du, $au) - $historique->coutsEntre($ids, $du, $au), principale: true)],
            function (string $du, string $au) use ($historique, $ids): ?string {
                $ventes = $historique->ventesEntre($ids, $du, $au);

                return $ventes === 0 ? null : 'Taux de marge : '.CalculDesSeries::pourcentage(CalculDesSeries::diviserArrondi(($ventes - $historique->coutsEntre($ids, $du, $au)) * 10000, $ventes));
            },
        );
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function chiffreDAffairesParMagasin(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $valeurs = [];

        foreach ($magasins as $magasin) {
            $valeurs[] = $calcul->sommesParJour($this->documentsDeVente($calcul), [CleDuModele::de($magasin)], fn (array $document): int => $calcul->signe($document) * $document['ht'])->entre($calcul->premierJourDeLaPeriode(), $calcul->dernierJourDeLaPeriode());
        }

        return new SerieDuGraphique(array_values($magasins->map(fn (Magasin $magasin): string => Perimetre::nomDuMagasin($magasin))->all()), [SerieDuGraphique::jeu('Chiffre d\'affaires HT', $valeurs)]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function ventesParTaux(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        $parTaux = [];

        foreach ($calcul->dansLaPeriode($this->lignesDeVente($calcul), $ids) as $ligne) {
            $parTaux[$ligne['taux']] = ($parTaux[$ligne['taux']] ?? 0) + $calcul->signe($ligne) * $ligne['ht'];
        }

        krsort($parTaux);

        return new SerieDuGraphique(array_map(fn (int $taux): string => CalculDesSeries::taux($taux), array_keys($parTaux)), [SerieDuGraphique::jeu('Ventes HT', array_values($parTaux))]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function particuliersEtProfessionnels(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        $parType = ['Particuliers' => 0, 'Professionnels' => 0];

        foreach ($calcul->dansLaPeriode($this->documentsDeVente($calcul), $ids) as $document) {
            $parType[$document['type_client'] === TypeClient::Particulier->value ? 'Particuliers' : 'Professionnels'] += $calcul->signe($document) * $document['ht'];
        }

        return new SerieDuGraphique(array_keys($parType), [SerieDuGraphique::jeu('Ventes HT', array_values($parType))]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function encaissementsParMode(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        $encaissements = $calcul->encaissements ??= $this->paiements->encaissements($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Paiements), $calcul->premierJour(), $calcul->dernierJour());
        $parMode = [];

        foreach (ModePaiement::cases() as $mode) {
            $parMode[$mode->value] = 0;
        }

        foreach ($calcul->dansLaPeriode($encaissements['paiements'], $ids) as $paiement) {
            $parMode[$paiement['mode']] = ($parMode[$paiement['mode']] ?? 0) + $paiement['montant'];
        }

        $credit = array_sum(array_column($calcul->dansLaPeriode($encaissements['credits'], $ids), 'montant'));
        $etiquettes = array_map(fn (string $mode): string => ModePaiement::tryFrom($mode)?->getLabel() ?? $mode, array_keys($parMode));

        return new SerieDuGraphique([...$etiquettes, 'Crédit d\'avoir utilisé'], [SerieDuGraphique::jeu('Encaissements TTC', [...array_values($parMode), $credit])]);
    }

    /**
     * Les dix produits ou services au plus fort chiffre d'affaires HT net,
     * ou aux plus fortes quantités nettes ; un produit d'un magasin n'est
     * jamais celui d'un autre, et son nom dit son magasin quand la carte en
     * compte plusieurs.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function meilleursProduits(CalculDesSeries $calcul, Collection $magasins, bool $parQuantite): SerieDuGraphique
    {
        $classes = [];

        foreach ($calcul->dansLaPeriode($this->lignesDeVente($calcul), CleDuModele::desModeles($magasins)) as $ligne) {
            $cle = $ligne['produit'] !== null ? "p:{$ligne['magasin']}:{$ligne['produit']}" : 's:'.$ligne['magasin'].':'.($ligne['service'] ?? mb_strtolower($ligne['description']));
            $classes[$cle]['libelle'] = $ligne['description'];
            $classes[$cle]['magasin'] = $ligne['magasin'];
            $classes[$cle]['valeur'] = ($classes[$cle]['valeur'] ?? 0) + $calcul->signe($ligne) * ($parQuantite ? $ligne['quantite'] : $ligne['ht']);
        }

        return $this->classement($classes, $magasins, $parQuantite ? 'Quantité vendue' : 'Chiffre d\'affaires HT', $parQuantite ? SerieDuGraphique::NOMBRE : SerieDuGraphique::EUROS);
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function meilleursClients(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $parClient = [];

        foreach ($calcul->dansLaPeriode($this->documentsDeVente($calcul), CleDuModele::desModeles($magasins)) as $document) {
            $parClient[$document['client']] = ['magasin' => $document['magasin'], 'valeur' => ($parClient[$document['client']]['valeur'] ?? 0) + $calcul->signe($document) * $document['ht']];
        }

        $noms = $this->ventes->nomsDesClients($calcul->perimetre, $magasins, array_keys($parClient));
        $classes = [];

        foreach ($parClient as $client => $total) {
            if (isset($noms[$client])) {
                $classes[(string) $client] = ['libelle' => $noms[$client], 'magasin' => $total['magasin'], 'valeur' => $total['valeur']];
            }
        }

        return $this->classement($classes, $magasins, 'Chiffre d\'affaires HT');
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function achatsParFournisseur(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $parFournisseur = [];

        foreach ($calcul->dansLaPeriode($this->documentsDAchat($calcul), CleDuModele::desModeles($magasins)) as $document) {
            $parFournisseur[$document['fournisseur']] = ['magasin' => $document['magasin'], 'valeur' => ($parFournisseur[$document['fournisseur']]['valeur'] ?? 0) + $calcul->signe($document) * $document['ht']];
        }

        $noms = $this->achats->nomsDesFournisseurs($calcul->perimetre, $magasins, array_keys($parFournisseur));
        $classes = [];

        foreach ($parFournisseur as $fournisseur => $total) {
            $classes[(string) $fournisseur] = ['libelle' => $noms[$fournisseur] ?? 'Fournisseur', 'magasin' => $total['magasin'], 'valeur' => $total['valeur']];
        }

        return $this->classement($classes, $magasins, 'Achats HT');
    }

    /**
     * Le calcul de la carte « Écart des prix d'achat » : les achats des
     * douze mois de la période, ou des douze derniers mois quand le
     * découpage est l'année (une phrase grise le dit), dans les magasins
     * comptés, produit par produit (voir AchatsParProduit) ; le nom d'un
     * produit dit son magasin quand la carte en compte plusieurs.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function ecartDesPrixDAchat(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $lignes = $calcul->dansLesMoisDeLEcart($this->lignesDAchat($calcul), CleDuModele::desModeles($magasins));
        $noms = [];

        foreach ($magasins as $magasin) {
            $noms[CleDuModele::de($magasin)] = (string) $magasin->nom;
        }

        $produits = AchatsParProduit::depuis($lignes, $calcul->moisDeLEcart, $magasins->count() > 1, $noms);
        $serie = new SerieDuGraphique(array_map(Mois::libelle(...), $calcul->moisDeLEcart), [], null, [], EcartDesPrixDAchat::calculer($produits, $calcul->moisDeLEcart));

        return $calcul->reglages->decoupage === DecoupageDuTableauDeBord::Annee ? $serie->avecLaNote(TextesDesPeriodes::ECART_DES_PRIX_D_ACHAT_PAR_ANNEE) : $serie;
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function anciennete(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $tranches = $this->factures->anciennete($calcul->facturesAEncaisser($this->factures)->whereIn('magasin_id', $magasins->modelKeys()));

        return new SerieDuGraphique(array_values(self::ANCIENNETES), [SerieDuGraphique::jeu('Restant dû', array_map(fn (string $tranche): int => $tranches[$tranche], array_keys(self::ANCIENNETES)))]);
    }

    /**
     * La valeur du stock au coût moyen à la fin de chaque barre, jamais
     * cumulée.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function valeurDuStock(CalculDesSeries $calcul, CarteDuTableauDeBord $carte, Collection $magasins): SerieDuGraphique
    {
        $historique = $this->historiqueDuStock($calcul);
        $ids = CleDuModele::desModeles($magasins);

        return ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
            new DefinitionDeSerie('Valeur du stock HT', fn (string $du, string $au, NiveauDeLaBarre $niveau): int => $historique->valeurA($ids, $au), principale: true),
        ]);
    }

    /**
     * Le nombre des ventes (factures et tickets, sans les avoirs) et leur
     * panier moyen TTC, le TTC des ventes divisé par leur nombre, zéro pour
     * une barre sans vente ; la série principale est le nombre de ventes.
     *
     * @param  list<int>  $ids
     */
    private function nombreDeVentesEtPanierMoyen(CalculDesSeries $calcul, CarteDuTableauDeBord $carte, array $ids): SerieDuGraphique
    {
        $ventes = array_values(array_filter($this->documentsDeVente($calcul), fn (array $document): bool => $document['document'] === 'facture'));
        $nombres = $calcul->sommesParJour($ventes, $ids, fn (array $document): int => 1);
        $ttc = $calcul->sommesParJour($ventes, $ids, fn (array $document): int => $document['ttc']);

        return ConstructeurDeSeriesParPeriode::construire($calcul, $carte, [
            new DefinitionDeSerie('Nombre de ventes', self::somme($nombres), principale: true, unite: SerieDuGraphique::NOMBRE),
            new DefinitionDeSerie('Panier moyen TTC', function (string $du, string $au, NiveauDeLaBarre $niveau) use ($nombres, $ttc): int {
                $nombre = $nombres->entre($du, $au);

                return $nombre === 0 ? 0 : CalculDesSeries::diviserArrondi($ttc->entre($du, $au), $nombre);
            }, enLigne: true, axeDeDroite: true),
        ]);
    }

    /**
     * Le chiffre d'affaires HT net moyen de chaque jour de la semaine : la
     * somme de ses jours de la période, jusqu'à aujourd'hui, divisée par le
     * nombre de ces jours.
     *
     * @param  list<int>  $ids
     */
    private function ventesParJour(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        $sommes = array_fill_keys(array_keys(self::JOURS_DE_LA_SEMAINE), 0);
        $nombres = array_fill_keys(array_keys(self::JOURS_DE_LA_SEMAINE), 0);
        $dernier = min($calcul->dernierJourDeLaPeriode(), $calcul->perimetre->aujourdhui());

        for ($jour = $calcul->premierJourDeLaPeriode(); $jour <= $dernier; $jour = Calendrier::ajouterJours($jour, 1)) {
            $nombres[CalculDesSeries::jourDeLaSemaine($jour)]++;
        }

        foreach ($calcul->dansLaPeriode($this->documentsDeVente($calcul), $ids) as $document) {
            $sommes[CalculDesSeries::jourDeLaSemaine($document['jour'])] += $calcul->signe($document) * $document['ht'];
        }

        $moyennes = array_map(fn (int $jour): int => $nombres[$jour] === 0 ? 0 : CalculDesSeries::diviserArrondi($sommes[$jour], $nombres[$jour]), array_keys(self::JOURS_DE_LA_SEMAINE));

        return new SerieDuGraphique(array_values(self::JOURS_DE_LA_SEMAINE), [SerieDuGraphique::jeu('Chiffre d\'affaires HT moyen', $moyennes)]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function articlesEtServices(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        $parNature = ['Articles' => 0, 'Services' => 0];

        foreach ($calcul->dansLaPeriode($this->lignesDeVente($calcul), $ids) as $ligne) {
            $parNature[$ligne['nature'] === NatureLigne::Service->value ? 'Services' : 'Articles'] += $calcul->signe($ligne) * $ligne['ht'];
        }

        return new SerieDuGraphique(array_keys($parNature), [SerieDuGraphique::jeu('Ventes HT', array_values($parNature))]);
    }

    /**
     * Les dix comptes qui ont vendu au plus fort chiffre d'affaires HT net
     * des avoirs, dans chaque magasin compté (le compte d'un vendeur ne vend
     * que dans son magasin, celui d'un administrateur autorisé peut vendre
     * dans plusieurs : une barre par magasin) : chaque vente garde le nom de la personne tel
     * qu'il était à son émission, et un compte qui a changé de nom prend,
     * pour sa barre, le nom de sa dernière vente de la période ; un avoir
     * se déduit des ventes du compte qui a fait la vente qu'il corrige (voir
     * VentesDuTableauDeBord::ventesParCompte()).
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function ventesParVendeur(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $classes = [];
        $dernierJour = [];

        foreach ($calcul->dansLaPeriode($this->ventesParCompte($calcul), CleDuModele::desModeles($magasins)) as $vente) {
            $cle = $vente['magasin'].':'.($vente['compte'] ?? 'sans');
            $classes[$cle]['magasin'] = $vente['magasin'];
            $classes[$cle]['valeur'] = ($classes[$cle]['valeur'] ?? 0) + $vente['ht'];

            if ($vente['nom'] !== null && $vente['jour'] >= ($dernierJour[$cle] ?? '')) {
                $dernierJour[$cle] = $vente['jour'];
                $classes[$cle]['libelle'] = $vente['nom'];
            }
        }

        return $this->classement(array_map(fn (array $classe): array => ['libelle' => $classe['libelle'] ?? 'Vendeur inconnu', 'magasin' => $classe['magasin'], 'valeur' => $classe['valeur']], $classes), $magasins, 'Chiffre d\'affaires HT');
    }

    /**
     * Les dix premiers de ce classement, par valeur décroissante puis par
     * nom ; le nom dit le magasin quand la carte en compte plusieurs.
     *
     * @param  array<int|string, array{libelle: string, magasin: int, valeur: int}>  $classes
     * @param  Collection<int, Magasin>  $magasins
     * @param  'euros'|'nombre'  $unite
     */
    private function classement(array $classes, Collection $magasins, string $libelle, string $unite = SerieDuGraphique::EUROS): SerieDuGraphique
    {
        uasort($classes, fn (array $un, array $autre): int => [$autre['valeur'], $un['libelle']] <=> [$un['valeur'], $autre['libelle']]);
        $premiers = array_slice(array_filter($classes, fn (array $classe): bool => $classe['valeur'] > 0), 0, self::CLASSES);
        $plusieurs = $magasins->count() > 1;
        $noms = $magasins->mapWithKeys(fn (Magasin $magasin): array => [CleDuModele::de($magasin) => (string) $magasin->nom]);

        return new SerieDuGraphique(
            array_values(array_map(fn (array $classe): string => $classe['libelle'].($plusieurs ? ' · '.$noms[$classe['magasin']] : ''), $premiers)),
            [SerieDuGraphique::jeu($libelle, array_column($premiers, 'valeur'), $unite)],
        );
    }

    /**
     * Le grand livre rejoué des magasins où le compte voit le stock, lu une
     * fois pour la marge et la valeur du stock ; le chiffre d'affaires des
     * articles vendus, seulement quand une carte de marge le demande.
     */
    private function historiqueDuStock(CalculDesSeries $calcul): HistoriqueDuStock
    {
        if ($calcul->historiqueDuStock !== null) {
            return $calcul->historiqueDuStock;
        }

        $lignesDeLaMarge = [];

        if ($calcul->montre(Graphique::MargeBruteParMois)) {
            $marge = CleDuModele::desModeles($calcul->perimetre->magasinsDe(Graphique::MargeBruteParMois));
            $lignesDeLaMarge = array_values(array_filter($this->lignesDeVente($calcul), fn (array $ligne): bool => $ligne['produit'] !== null && in_array($ligne['magasin'], $marge, true)));
        }

        return $calcul->historiqueDuStock = $this->stock->historique($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Stock), $lignesDeLaMarge);
    }

    /**
     * Les ventes de tout ce qu'il faut lire, sur les magasins où le compte
     * voit les ventes, lues une fois pour la page (cartes et chiffres clés).
     *
     * @return list<DocumentDeVente>
     */
    public function documentsDeVente(CalculDesSeries $calcul): array
    {
        return $calcul->documentsDeVente ??= $this->ventes->documents($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Ventes), $calcul->premierJour(), $calcul->dernierJour());
    }

    /**
     * Les ventes de chaque compte, sur les magasins où le compte voit toutes
     * les ventes, lues une fois pour la page ; seulement quand la carte
     * « Ventes par vendeur » est affichée.
     *
     * @return list<VenteDUnCompte>
     */
    private function ventesParCompte(CalculDesSeries $calcul): array
    {
        return $calcul->ventesParCompte ??= $this->ventes->ventesParCompte($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Ventes), $calcul->premierJourDeLaPeriode(), $calcul->dernierJourDeLaPeriode());
    }

    /**
     * @return list<LigneDeVente>
     */
    private function lignesDeVente(CalculDesSeries $calcul): array
    {
        return $calcul->lignesDeVente ??= $this->ventes->lignes($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Ventes), $calcul->premierJour(), $calcul->dernierJour());
    }

    /**
     * @return list<DocumentDeVente>
     */
    private function avoirs(CalculDesSeries $calcul): array
    {
        return $calcul->avoirs ??= $this->ventes->avoirs($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Avoirs), $calcul->premierJour(), $calcul->dernierJour());
    }

    /**
     * Les achats de tout ce qu'il faut lire, sur les magasins où le compte
     * voit les achats, lus une fois pour la page.
     *
     * @return list<DocumentDAchat>
     */
    public function documentsDAchat(CalculDesSeries $calcul): array
    {
        return $calcul->documentsDAchat ??= $this->achats->documents($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Achats), $calcul->premierJour(), $calcul->dernierJour());
    }

    /**
     * Les lignes des factures d'achat validées des douze mois de la carte
     * « Écart des prix d'achat », sur les magasins où le compte voit les
     * achats, lues une fois pour la page, en une seule requête.
     *
     * @return list<LigneDAchatDuTableau>
     */
    private function lignesDAchat(CalculDesSeries $calcul): array
    {
        return $calcul->lignesDAchat ??= $this->achats->lignes($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Achats), $calcul->premierJourDeLEcart(), $calcul->dernierJourDeLEcart());
    }
}
