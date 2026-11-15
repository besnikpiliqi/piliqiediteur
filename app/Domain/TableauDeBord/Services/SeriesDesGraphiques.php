<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Services\TvaDeLaPeriode;
use App\Domain\Charges\Support\MontantsDeTva;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\SourceDesGraphiques;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\CarteDuTableauDeBord;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\TableauDeBord\Support\Perimetre;
use App\Domain\TableauDeBord\Support\SerieDuGraphique;
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
 * opération dans le mois de son magasin. Un mois encore à venir n'a pas
 * de valeur (null). Une société en franchise en base n'a pas de TVA à
 * estimer (voir Perimetre::voit()).
 *
 * @phpstan-import-type DocumentDeVente from VentesDuTableauDeBord
 * @phpstan-import-type LigneDeVente from VentesDuTableauDeBord
 * @phpstan-import-type DocumentDAchat from AchatsDuTableauDeBord
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
            Graphique::ChiffreDAffairesParMois => $calcul->parMois('Chiffre d\'affaires HT', fn (array $mois): array => $calcul->sommeParMois($this->documentsDeVente($calcul), $ids, $mois, fn (array $document): int => $calcul->signe($document) * $document['ht'])),
            Graphique::VentesEtAchatsParMois => $calcul->parMoisMultiple([
                ['Ventes HT', fn (array $mois): array => $calcul->sommeParMois($this->documentsDeVente($calcul), $ids, $mois, fn (array $document): int => $calcul->signe($document) * $document['ht'])],
                ['Achats HT', fn (array $mois): array => $calcul->sommeParMois($this->documentsDAchat($calcul), $ids, $mois, fn (array $document): int => $calcul->signe($document) * $document['ht'])],
            ]),
            Graphique::TvaAPayerParMois => $this->tvaParMois($calcul, $ids),
            Graphique::ChiffreDAffairesParAn => $this->chiffreDAffairesParAn($calcul, $ids),
            Graphique::MargeBruteParMois => $this->margeParMois($calcul, $magasins),
            Graphique::ChiffreDAffairesParMagasin => $this->chiffreDAffairesParMagasin($calcul, $magasins),
            Graphique::VentesParTauxDeTva => $this->ventesParTaux($calcul, $ids),
            Graphique::ParticuliersEtProfessionnels => $this->particuliersEtProfessionnels($calcul, $ids),
            Graphique::EncaissementsParMode => $this->encaissementsParMode($calcul, $ids),
            Graphique::MeilleursProduits => $this->meilleursProduits($calcul, $magasins, $carte->parQuantite()),
            Graphique::MeilleursClients => $this->meilleursClients($calcul, $magasins),
            Graphique::AchatsParFournisseur => $this->achatsParFournisseur($calcul, $magasins),
            Graphique::FacturesParAnciennete => $this->anciennete($calcul, $magasins),
            Graphique::ValeurDuStock => $this->valeurDuStock($calcul, $magasins),
            Graphique::NombreDeVentesEtPanierMoyen => $this->nombreDeVentesEtPanierMoyen($calcul, $ids),
            Graphique::AvoirsParMois => $calcul->parMois('Avoirs HT', fn (array $mois): array => $calcul->sommeParMois($this->avoirs($calcul), $ids, $mois, fn (array $document): int => $document['ht'])),
            Graphique::VentesParJourDeLaSemaine => $this->ventesParJour($calcul, $ids),
            Graphique::ArticlesEtServices => $this->articlesEtServices($calcul, $ids),
        };
    }

    /**
     * La TVA à payer par mois (voir TvaDeLaPeriode) : la TVA collectée, la
     * TVA déductible des achats et, pour qui voit les charges de la
     * société, celle des charges validées, puis la TVA à payer réelle, en
     * ligne, et, avec les charges, son estimation avec les charges à
     * valider, en pointillés ; sans les charges, la note le dit.
     *
     * @param  list<int>  $ids
     */
    private function tvaParMois(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        if ($this->chargesDeLaTva($calcul) === null) {
            return $calcul->parMoisMultiple([
                ['TVA collectée', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->collectee(), $this->tvaDesMois($calcul, $ids, $mois))],
                ['TVA déductible', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->deductible(), $this->tvaDesMois($calcul, $ids, $mois))],
                ['TVA à payer', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->reelle(), $this->tvaDesMois($calcul, $ids, $mois)), true],
            ])->avecLaNote(self::SANS_LES_CHARGES);
        }

        return $calcul->parMoisMultiple([
            ['TVA collectée', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->collectee(), $this->tvaDesMois($calcul, $ids, $mois))],
            ['TVA déductible des achats', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->deductibleDesAchats, $this->tvaDesMois($calcul, $ids, $mois))],
            ['TVA déductible des charges', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->deductibleDesChargesValidees, $this->tvaDesMois($calcul, $ids, $mois))],
            ['TVA à payer', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->reelle(), $this->tvaDesMois($calcul, $ids, $mois)), true],
            ['TVA à payer (estimation)', fn (array $mois): array => array_map(fn (MontantsDeTva $tva): int => $tva->estimee(), $this->tvaDesMois($calcul, $ids, $mois)), true, SerieDuGraphique::EUROS, false, true],
        ]);
    }

    /**
     * La TVA de ces mois, sur ces magasins, avec les charges que compte le
     * périmètre (voir TvaDeLaPeriode::parMois()).
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
     * Les années civiles, ou les exercices quand la période en est un,
     * jusqu'à celle de la période, cinq au plus, depuis la première qui a
     * vendu.
     *
     * @param  list<int>  $ids
     */
    private function chiffreDAffairesParAn(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        $annees = [];

        foreach ($calcul->annees() as [$libelle, $premier, $dernier]) {
            $annees[] = [$libelle, array_sum($calcul->sommeParMois($this->documentsDeVente($calcul), $ids, Mois::du($premier, $dernier), fn (array $document): int => $calcul->signe($document) * $document['ht']))];
        }

        while (count($annees) > 1 && $annees[0][1] === 0) {
            array_shift($annees);
        }

        return new SerieDuGraphique(array_column($annees, 0), [SerieDuGraphique::jeu('Chiffre d\'affaires HT', array_column($annees, 1))]);
    }

    /**
     * La marge brute des articles suivis en stock : leurs ventes HT moins
     * leur coût, retours déduits, chaque mois ; le taux de marge en
     * info-bulle.
     *
     * @param  Collection<int, Magasin>  $magasins
     */
    private function margeParMois(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $historique = $this->historiqueDuStock($calcul);
        $ventes = $calcul->sommerParMagasin($historique['ventes'], CleDuModele::desModeles($magasins), $calcul->tousLesMois());
        $couts = $calcul->sommerParMagasin($historique['couts'], CleDuModele::desModeles($magasins), $calcul->tousLesMois());
        $serie = $calcul->parMois('Marge brute HT', fn (array $mois): array => self::parMois($mois, fn (string $unMois): int => $ventes[$unMois] - $couts[$unMois]));
        $infoBulles = [];

        foreach ($calcul->mois as $rang => $unMois) {
            if ($ventes[$unMois] !== 0) {
                $infoBulles[$rang] = 'Taux de marge : '.CalculDesSeries::pourcentage(CalculDesSeries::diviserArrondi(($ventes[$unMois] - $couts[$unMois]) * 10000, $ventes[$unMois]));
            }
        }

        return new SerieDuGraphique($serie->etiquettes, $serie->jeux, null, $infoBulles);
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function chiffreDAffairesParMagasin(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $valeurs = [];

        foreach ($magasins as $magasin) {
            $valeurs[] = array_sum($calcul->sommeParMois($this->documentsDeVente($calcul), [CleDuModele::de($magasin)], $calcul->mois, fn (array $document): int => $calcul->signe($document) * $document['ht']));
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
     * @param  Collection<int, Magasin>  $magasins
     */
    private function anciennete(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $tranches = $this->factures->anciennete($calcul->facturesAEncaisser($this->factures)->whereIn('magasin_id', $magasins->modelKeys()));

        return new SerieDuGraphique(array_values(self::ANCIENNETES), [SerieDuGraphique::jeu('Restant dû', array_map(fn (string $tranche): int => $tranches[$tranche], array_keys(self::ANCIENNETES)))]);
    }

    /**
     * @param  Collection<int, Magasin>  $magasins
     */
    private function valeurDuStock(CalculDesSeries $calcul, Collection $magasins): SerieDuGraphique
    {
        $valeurs = $calcul->sommerParMagasin($this->historiqueDuStock($calcul)['valeurs'], CleDuModele::desModeles($magasins), $calcul->tousLesMois());

        return $calcul->parMois('Valeur du stock HT', fn (array $mois): array => self::parMois($mois, fn (string $unMois): int => $valeurs[$unMois]));
    }

    /**
     * @param  list<int>  $ids
     */
    private function nombreDeVentesEtPanierMoyen(CalculDesSeries $calcul, array $ids): SerieDuGraphique
    {
        return $calcul->parMoisMultiple([
            ['Nombre de ventes', fn (array $mois): array => $this->nombreDeVentes($calcul, $ids, $mois), false, SerieDuGraphique::NOMBRE],
            ['Panier moyen TTC', fn (array $mois): array => $this->panierMoyen($calcul, $ids, $mois), true, SerieDuGraphique::EUROS, true],
        ]);
    }

    /**
     * Le nombre des ventes (factures et tickets, sans les avoirs) de ces
     * mois.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $mois
     * @return array<string, int>
     */
    private function nombreDeVentes(CalculDesSeries $calcul, array $ids, array $mois): array
    {
        return $calcul->sommeParMois($this->ventesSansLesAvoirs($calcul), $ids, $mois, fn (array $document): int => 1);
    }

    /**
     * Le panier moyen TTC de ces mois : le TTC des ventes divisé par leur
     * nombre, zéro pour un mois sans vente.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $mois
     * @return array<string, int>
     */
    private function panierMoyen(CalculDesSeries $calcul, array $ids, array $mois): array
    {
        $ttc = $calcul->sommeParMois($this->ventesSansLesAvoirs($calcul), $ids, $mois, fn (array $document): int => $document['ttc']);
        $nombres = $this->nombreDeVentes($calcul, $ids, $mois);

        return self::parMois($mois, fn (string $unMois): int => $nombres[$unMois] === 0 ? 0 : CalculDesSeries::diviserArrondi($ttc[$unMois], $nombres[$unMois]));
    }

    /**
     * @return list<DocumentDeVente>
     */
    private function ventesSansLesAvoirs(CalculDesSeries $calcul): array
    {
        return array_values(array_filter($this->documentsDeVente($calcul), fn (array $document): bool => $document['document'] === 'facture'));
    }

    /**
     * La valeur de chacun de ces mois, par mois.
     *
     * @param  list<string>  $mois
     * @param  Closure(string): int  $valeur
     * @return array<string, int>
     */
    private static function parMois(array $mois, Closure $valeur): array
    {
        $valeurs = [];

        foreach ($mois as $unMois) {
            $valeurs[$unMois] = $valeur($unMois);
        }

        return $valeurs;
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
     *
     * @return array{valeurs: array<int, array<string, int>>, ventes: array<int, array<string, int>>, couts: array<int, array<string, int>>}
     */
    private function historiqueDuStock(CalculDesSeries $calcul): array
    {
        if ($calcul->historiqueDuStock !== null) {
            return $calcul->historiqueDuStock;
        }

        $lignesDeLaMarge = [];

        if ($calcul->montre(Graphique::MargeBruteParMois)) {
            $marge = CleDuModele::desModeles($calcul->perimetre->magasinsDe(Graphique::MargeBruteParMois));
            $lignesDeLaMarge = array_values(array_filter($this->lignesDeVente($calcul), fn (array $ligne): bool => $ligne['produit'] !== null && in_array($ligne['magasin'], $marge, true)));
        }

        return $calcul->historiqueDuStock = $this->stock->historique($calcul->perimetre, $calcul->perimetre->magasinsAvec(SourceDesGraphiques::Stock), $calcul->tousLesMois(), $lignesDeLaMarge);
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
}
