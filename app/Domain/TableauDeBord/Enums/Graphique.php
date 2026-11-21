<?php

namespace App\Domain\TableauDeBord\Enums;

use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use Filament\Support\Contracts\HasLabel;

/**
 * Le catalogue des graphiques du tableau de bord (décision de l'éditeur du
 * 14/11/2026, modèle « G1 + chiffres de G2 », lot GRAPH) : dix-neuf
 * graphiques, que le menu « Ajouter un graphique » propose à qui voit tous
 * les documents qu'ils comptent (voir sources() et Perimetre). Chacun dit
 * ce qu'il compte (voir ceQuIlCompte()), la ligne grise de sa carte, et
 * les types qu'il permet (voir types()), le premier par défaut.
 *
 * La dix-neuvième, « Écart des prix d'achat » (décision de l'éditeur du
 * 21/11/2026, D76), compare les prix des achats d'un même produit et se lit
 * sur les lignes des factures d'achat validées ; elle n'a que la forme
 * « barres » et ne compare pas avec l'année précédente.
 *
 * Tous lisent les montants enregistrés sur les documents émis ou validés,
 * jamais un brouillon, additionnés en centimes, chaque opération dans le
 * jour, le mois et l'année de son magasin (voir SeriesDesGraphiques).
 */
enum Graphique: string implements HasLabel
{
    case ChiffreDAffairesParMois = 'ca_par_mois';
    case VentesEtAchatsParMois = 'ventes_et_achats_par_mois';
    case TvaAPayerParMois = 'tva_par_mois';
    case ChiffreDAffairesParAn = 'ca_par_an';
    case MargeBruteParMois = 'marge_par_mois';
    case ChiffreDAffairesParMagasin = 'ca_par_magasin';
    case VentesParTauxDeTva = 'ventes_par_taux';
    case ParticuliersEtProfessionnels = 'particuliers_et_professionnels';
    case EncaissementsParMode = 'encaissements_par_mode';
    case MeilleursProduits = 'meilleurs_produits';
    case MeilleursClients = 'meilleurs_clients';
    case AchatsParFournisseur = 'achats_par_fournisseur';
    case FacturesParAnciennete = 'factures_par_anciennete';
    case ValeurDuStock = 'valeur_du_stock';
    case NombreDeVentesEtPanierMoyen = 'ventes_et_panier_moyen';
    case AvoirsParMois = 'avoirs_par_mois';
    case VentesParJourDeLaSemaine = 'ventes_par_jour';
    case ArticlesEtServices = 'articles_et_services';
    case EcartDesPrixDAchat = 'ecart_des_prix_d_achat';

    public function getLabel(): string
    {
        return match ($this) {
            self::ChiffreDAffairesParMois => 'Chiffre d\'affaires HT par mois',
            self::VentesEtAchatsParMois => 'Ventes et achats HT par mois',
            self::TvaAPayerParMois => 'TVA à payer par mois (estimation)',
            self::ChiffreDAffairesParAn => 'Chiffre d\'affaires HT par an',
            self::MargeBruteParMois => 'Marge brute HT par mois',
            self::ChiffreDAffairesParMagasin => 'Chiffre d\'affaires par magasin',
            self::VentesParTauxDeTva => 'Ventes par taux de TVA',
            self::ParticuliersEtProfessionnels => 'Particuliers et professionnels',
            self::EncaissementsParMode => 'Encaissements par mode de paiement',
            self::MeilleursProduits => 'Meilleurs produits',
            self::MeilleursClients => 'Meilleurs clients',
            self::AchatsParFournisseur => 'Achats par fournisseur',
            self::FacturesParAnciennete => 'Factures à encaisser par ancienneté',
            self::ValeurDuStock => 'Valeur du stock au coût moyen',
            self::NombreDeVentesEtPanierMoyen => 'Nombre de ventes et panier moyen',
            self::AvoirsParMois => 'Avoirs émis par mois',
            self::VentesParJourDeLaSemaine => 'Ventes par jour de la semaine',
            self::ArticlesEtServices => 'Articles et services',
            self::EcartDesPrixDAchat => 'Écart des prix d\'achat',
        };
    }

    /**
     * La ligne grise de la carte : ce que le graphique compte.
     */
    public function ceQuIlCompte(): string
    {
        return match ($this) {
            self::ChiffreDAffairesParMois => 'Les ventes HT émises, moins les avoirs émis, mois par mois.',
            self::VentesEtAchatsParMois => 'Les ventes HT nettes des avoirs et les achats de marchandises HT (factures d\'achat validées moins avoirs fournisseur), mois par mois.',
            self::TvaAPayerParMois => 'La TVA collectée des ventes (nette des avoirs), la TVA déductible des achats (nette des avoirs fournisseur) et leur différence. Estimation tirée de vos documents.',
            self::ChiffreDAffairesParAn => 'Les ventes HT nettes des avoirs de chaque année civile, qui est aussi l\'exercice quand il finit en décembre, ou de chaque exercice quand la période choisie est un exercice.',
            self::MargeBruteParMois => 'Les ventes HT des articles moins leur coût moyen d\'achat au moment de la vente, rejoué depuis le grand livre du stock ; le taux de marge en info-bulle.',
            self::ChiffreDAffairesParMagasin => 'La part de chaque magasin dans les ventes HT nettes de la période.',
            self::VentesParTauxDeTva => 'Les ventes HT nettes des avoirs, taux par taux.',
            self::ParticuliersEtProfessionnels => 'Les ventes HT nettes aux particuliers et aux professionnels, selon le type du client figé à l\'émission.',
            self::EncaissementsParMode => 'Les encaissements TTC par mode de paiement, contre-passations déduites ; le crédit d\'avoir utilisé à part.',
            self::MeilleursProduits => 'Les 10 produits ou services au plus fort chiffre d\'affaires HT net, ou les plus vendus en quantité.',
            self::MeilleursClients => 'Les 10 clients au plus fort chiffre d\'affaires HT net, sans le client comptoir.',
            self::AchatsParFournisseur => 'Les 10 fournisseurs aux plus forts achats HT nets (factures d\'achat validées moins avoirs fournisseur).',
            self::FacturesParAnciennete => 'Ce qui reste dû à ce jour : à échoir, puis en retard de 1 à 30 jours, de 31 à 60 jours et de plus de 60 jours, en jours du calendrier du magasin.',
            self::ValeurDuStock => 'La valeur HT du stock au coût moyen à la fin de chaque mois, rejouée depuis le grand livre du stock.',
            self::NombreDeVentesEtPanierMoyen => 'Le nombre de tickets et de factures émis, et leur montant moyen TTC (axe de droite).',
            self::AvoirsParMois => 'Le montant HT des avoirs émis, mois par mois.',
            self::VentesParJourDeLaSemaine => 'Le chiffre d\'affaires HT net moyen de chaque jour, du lundi au dimanche, sur la période.',
            self::ArticlesEtServices => 'La part des articles et des services dans les ventes HT nettes.',
            self::EcartDesPrixDAchat => TextesDeLEcartDesPrixDAchat::METHODE_PAR_MOIS,
        };
    }

    /**
     * Les types que la carte propose, le premier par défaut.
     *
     * @return non-empty-list<TypeDeGraphique>
     */
    public function types(): array
    {
        $parMois = [TypeDeGraphique::Barres, TypeDeGraphique::Lignes, TypeDeGraphique::Aires];
        $repartition = [TypeDeGraphique::Anneau, TypeDeGraphique::Camembert, TypeDeGraphique::Barres, TypeDeGraphique::BarresCouchees];
        $classement = [TypeDeGraphique::BarresCouchees, TypeDeGraphique::Barres, TypeDeGraphique::Anneau, TypeDeGraphique::Camembert];

        return match ($this) {
            self::ChiffreDAffairesParMois, self::VentesEtAchatsParMois, self::TvaAPayerParMois, self::MargeBruteParMois, self::ValeurDuStock, self::AvoirsParMois => $parMois,
            self::ChiffreDAffairesParAn, self::NombreDeVentesEtPanierMoyen => [TypeDeGraphique::Barres, TypeDeGraphique::Lignes],
            self::EcartDesPrixDAchat => [TypeDeGraphique::Barres],
            self::ChiffreDAffairesParMagasin, self::MeilleursProduits, self::MeilleursClients, self::AchatsParFournisseur => $classement,
            self::VentesParTauxDeTva, self::ParticuliersEtProfessionnels, self::EncaissementsParMode, self::ArticlesEtServices => $repartition,
            self::FacturesParAnciennete => [TypeDeGraphique::Barres, TypeDeGraphique::Anneau, TypeDeGraphique::Camembert, TypeDeGraphique::BarresCouchees],
            self::VentesParJourDeLaSemaine => [TypeDeGraphique::Barres, TypeDeGraphique::Lignes, TypeDeGraphique::Radar],
        };
    }

    public function typeParDefaut(): TypeDeGraphique
    {
        return $this->types()[0];
    }

    /**
     * Un graphique par mois, qui ajoute la même période un an plus tôt
     * quand la case « Comparer avec l'année précédente » est cochée.
     */
    public function estParMois(): bool
    {
        return in_array($this, [self::ChiffreDAffairesParMois, self::VentesEtAchatsParMois, self::TvaAPayerParMois, self::MargeBruteParMois, self::ValeurDuStock, self::NombreDeVentesEtPanierMoyen, self::AvoirsParMois], true);
    }

    /**
     * Les documents qu'il compte, dans une société qui a des articles ou
     * non : la TVA à payer ne compte les achats que là où ils existent.
     *
     * @return non-empty-list<SourceDesGraphiques>
     */
    public function sources(bool $societeADesArticles): array
    {
        return match ($this) {
            self::ChiffreDAffairesParMois, self::ChiffreDAffairesParAn, self::ChiffreDAffairesParMagasin, self::VentesParTauxDeTva,
            self::ParticuliersEtProfessionnels, self::MeilleursProduits, self::NombreDeVentesEtPanierMoyen, self::VentesParJourDeLaSemaine,
            self::ArticlesEtServices => [SourceDesGraphiques::Ventes],
            self::VentesEtAchatsParMois => [SourceDesGraphiques::Ventes, SourceDesGraphiques::Achats],
            self::TvaAPayerParMois => $societeADesArticles ? [SourceDesGraphiques::Ventes, SourceDesGraphiques::Achats] : [SourceDesGraphiques::Ventes],
            self::MargeBruteParMois => [SourceDesGraphiques::Ventes, SourceDesGraphiques::Stock],
            self::EncaissementsParMode => [SourceDesGraphiques::Paiements],
            self::MeilleursClients => [SourceDesGraphiques::Ventes, SourceDesGraphiques::Clients],
            self::AchatsParFournisseur, self::EcartDesPrixDAchat => [SourceDesGraphiques::Achats],
            self::FacturesParAnciennete => [SourceDesGraphiques::Factures],
            self::ValeurDuStock => [SourceDesGraphiques::Stock],
            self::AvoirsParMois => [SourceDesGraphiques::Avoirs],
        };
    }

    /**
     * N'existe que dans une société qui a des articles : il compte des
     * achats, du stock, ou la part des articles dans les ventes.
     */
    public function exigeDesArticles(): bool
    {
        return in_array($this, [self::VentesEtAchatsParMois, self::MargeBruteParMois, self::AchatsParFournisseur, self::ValeurDuStock, self::ArticlesEtServices, self::EcartDesPrixDAchat], true);
    }

    /**
     * Compare plusieurs magasins : jamais dans le panneau d'un magasin, qui
     * ne laisse deviner aucun autre magasin.
     */
    public function compareLesMagasins(): bool
    {
        return $this === self::ChiffreDAffairesParMagasin;
    }
}
