<?php

namespace App\Domain\TableauDeBord\Enums;

use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use Filament\Support\Contracts\HasLabel;

/**
 * Le catalogue des graphiques du tableau de bord (décision de l'éditeur du
 * 14/11/2026, modèle « G1 + chiffres de G2 », lot GRAPH) : NOMBRE
 * graphiques, que le menu « Ajouter un graphique » propose à qui voit tous
 * les documents qu'ils comptent (voir sources() et Perimetre). Chacun dit
 * ce qu'il compte (voir ceQuIlCompte()), la ligne grise de sa carte, et
 * les types qu'il permet (voir types()), le premier par défaut.
 *
 * Sept d'entre eux sont par période (voir estParPeriode()) : une barre par
 * mois, par trimestre ou par année selon le menu « Découper par », que la
 * personne compare, cumule, lit en écart sur un an ou lisse par une
 * tendance (choix de l'éditeur du 16/11/2026, lot GRAPH-AN). « Chiffre
 * d'affaires HT par an » n'existe plus : « Découper par : année » le
 * remplace, sur dix ans (décision de Claude du 22/11/2026, D76). « Ventes
 * par vendeur », qui l'a remplacé, compte le chiffre d'affaires HT net de
 * chaque compte qui a vendu, avec le nom figé sur ses ventes.
 *
 * Le dernier, « Écart des prix d'achat » (décision de l'éditeur du
 * 21/11/2026, D76), compare les prix des achats d'un même produit et se lit
 * sur les lignes des factures d'achat validées ; il n'a que la forme
 * « barres » et ne compare pas avec l'année précédente.
 *
 * Tous lisent les montants enregistrés sur les documents émis ou validés,
 * jamais un brouillon, additionnés en centimes, chaque opération dans le
 * jour, le mois et l'année de son magasin (voir SeriesDesGraphiques).
 */
enum Graphique: string implements HasLabel
{
    /**
     * Le nombre de graphiques du catalogue : le seul endroit du code où il
     * s'écrit (un test le rapproche des cas de l'enum) ; tout ce qui le dit
     * le relit (voir count(self::cases())).
     */
    public const NOMBRE = 19;

    case ChiffreDAffairesParMois = 'ca_par_mois';
    case VentesEtAchatsParMois = 'ventes_et_achats_par_mois';
    case TvaAPayerParMois = 'tva_par_mois';
    case MargeBruteParMois = 'marge_par_mois';
    case ChiffreDAffairesParMagasin = 'ca_par_magasin';
    case VentesParTauxDeTva = 'ventes_par_taux';
    case ParticuliersEtProfessionnels = 'particuliers_et_professionnels';
    case EncaissementsParMode = 'encaissements_par_mode';
    case MeilleursProduits = 'meilleurs_produits';
    case MeilleursClients = 'meilleurs_clients';
    case VentesParVendeur = 'ventes_par_vendeur';
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
            self::MargeBruteParMois => 'Marge brute HT par mois',
            self::ChiffreDAffairesParMagasin => 'Chiffre d\'affaires par magasin',
            self::VentesParTauxDeTva => 'Ventes par taux de TVA',
            self::ParticuliersEtProfessionnels => 'Particuliers et professionnels',
            self::EncaissementsParMode => 'Encaissements par mode de paiement',
            self::MeilleursProduits => 'Meilleurs produits',
            self::MeilleursClients => 'Meilleurs clients',
            self::VentesParVendeur => 'Ventes par vendeur',
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
     * Le titre de la carte pour le niveau de ses barres : « par mois » dit
     * « par trimestre », « par année », « par exercice » (quand la société
     * ne clôt pas en décembre) ou « par jour » dans le détail d'un mois. Les
     * graphiques dont le libellé ne dit pas « par mois » gardent le leur.
     */
    public function titre(NiveauDeLaBarre $niveau = NiveauDeLaBarre::Mois, bool $exercices = false): string
    {
        return str_replace('par mois', $niveau->parTerme($exercices), $this->getLabel());
    }

    /**
     * La ligne grise de la carte : ce que le graphique compte, au pas de
     * ses barres (« mois par mois », « trimestre par trimestre », « année
     * par année », « jour par jour »).
     */
    public function ceQuIlCompte(NiveauDeLaBarre $niveau = NiveauDeLaBarre::Mois, bool $exercices = false): string
    {
        $pas = $niveau->pas($exercices);

        return match ($this) {
            self::ChiffreDAffairesParMois => "Les ventes HT émises, moins les avoirs émis, {$pas}.",
            self::VentesEtAchatsParMois => "Les ventes HT nettes des avoirs et les achats de marchandises HT (factures d'achat validées moins avoirs fournisseur), {$pas}.",
            self::TvaAPayerParMois => 'La TVA collectée des ventes (nette des avoirs), la TVA déductible des achats (nette des avoirs fournisseur) et leur différence. Estimation tirée de vos documents.',
            self::MargeBruteParMois => 'Les ventes HT des articles moins leur coût moyen d\'achat au moment de la vente, rejoué depuis le grand livre du stock ; le taux de marge en info-bulle.',
            self::ChiffreDAffairesParMagasin => 'La part de chaque magasin dans les ventes HT nettes de la période.',
            self::VentesParTauxDeTva => 'Les ventes HT nettes des avoirs, taux par taux.',
            self::ParticuliersEtProfessionnels => 'Les ventes HT nettes aux particuliers et aux professionnels, selon le type du client figé à l\'émission.',
            self::EncaissementsParMode => 'Les encaissements TTC par mode de paiement, contre-passations déduites ; le crédit d\'avoir utilisé à part.',
            self::MeilleursProduits => 'Les 10 produits ou services au plus fort chiffre d\'affaires HT net, ou les plus vendus en quantité.',
            self::MeilleursClients => 'Les 10 clients au plus fort chiffre d\'affaires HT net, sans le client comptoir.',
            self::VentesParVendeur => 'Les 10 comptes qui ont vendu au plus fort chiffre d\'affaires HT net des avoirs, avec le nom que chaque vente a gardé ; seulement les magasins où vous voyez toutes les ventes de l\'équipe.',
            self::AchatsParFournisseur => 'Les 10 fournisseurs aux plus forts achats HT nets (factures d\'achat validées moins avoirs fournisseur).',
            self::FacturesParAnciennete => 'Ce qui reste dû à ce jour : à échoir, puis en retard de 1 à 30 jours, de 31 à 60 jours et de plus de 60 jours, en jours du calendrier du magasin.',
            self::ValeurDuStock => 'La valeur HT du stock au coût moyen à la fin de chaque '.$niveau->terme($exercices).', rejouée depuis le grand livre du stock.',
            self::NombreDeVentesEtPanierMoyen => 'Le nombre de tickets et de factures émis, et leur montant moyen TTC (axe de droite).',
            self::AvoirsParMois => "Le montant HT des avoirs émis, {$pas}.",
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
            self::NombreDeVentesEtPanierMoyen => [TypeDeGraphique::Barres, TypeDeGraphique::Lignes],
            self::EcartDesPrixDAchat => [TypeDeGraphique::Barres],
            self::ChiffreDAffairesParMagasin, self::MeilleursProduits, self::MeilleursClients, self::VentesParVendeur, self::AchatsParFournisseur => $classement,
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
     * Un graphique par période : une barre par mois, par trimestre ou par
     * année selon le menu « Découper par », que la personne compare avec
     * l'année précédente, cumule, lit en écart en % sur un an et lisse par
     * une tendance. Les autres cartes répartissent la période choisie.
     */
    public function estParPeriode(): bool
    {
        return in_array($this, [self::ChiffreDAffairesParMois, self::VentesEtAchatsParMois, self::TvaAPayerParMois, self::MargeBruteParMois, self::ValeurDuStock, self::NombreDeVentesEtPanierMoyen, self::AvoirsParMois], true);
    }

    /**
     * Le cumul depuis le début de l'année, ou de l'exercice, a un sens pour
     * ce graphique : celui d'un flux (des ventes, des achats, une TVA), non
     * celui d'un stock, dont chaque barre est une valeur de fin de période.
     */
    public function accepteLeCumul(): bool
    {
        return $this->estParPeriode() && $this !== self::ValeurDuStock;
    }

    /**
     * Les listes de documents que le clic sur un jour de ce graphique
     * ouvre, filtrées sur ce jour (voir ListesDesJours) ; aucune pour un
     * graphique qui n'est pas par période, ni pour la valeur du stock, qui
     * n'est la somme d'aucun document du jour.
     *
     * @return list<ListeDeDocuments>
     */
    public function listesDuJour(bool $societeADesArticles): array
    {
        $ventes = [ListeDeDocuments::Factures, ListeDeDocuments::Avoirs];
        $achats = [ListeDeDocuments::FacturesAchat, ListeDeDocuments::AvoirsFournisseur];

        return match ($this) {
            self::ChiffreDAffairesParMois, self::MargeBruteParMois, self::NombreDeVentesEtPanierMoyen => $ventes,
            self::VentesEtAchatsParMois => [...$ventes, ...$achats],
            self::TvaAPayerParMois => $societeADesArticles ? [...$ventes, ...$achats] : $ventes,
            self::AvoirsParMois => [ListeDeDocuments::Avoirs],
            default => [],
        };
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
            self::ChiffreDAffairesParMois, self::ChiffreDAffairesParMagasin, self::VentesParTauxDeTva, self::VentesParVendeur,
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
