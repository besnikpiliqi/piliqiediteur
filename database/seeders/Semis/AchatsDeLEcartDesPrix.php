<?php

namespace Database\Seeders\Semis;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ArchiverUneFicheAction;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\TableauDeBord\Support\Mois;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use LogicException;

/**
 * Les achats de la carte « Écart des prix d'achat » du tableau de bord (le
 * dix-neuvième graphique ; décision de l'éditeur du 21/11/2026, décisions de
 * Claude D76, B10), semés pour que chaque règle visible de la carte ait une
 * donnée qui la déclenche et une qui ne la déclenche pas, de part et d'autre
 * de sa limite (règle 6 de CLAUDE.md). Chaque achat passe par les vraies
 * actions d'achat (voir GestesDuMagasin::acheter()), au nom de la personne
 * qui l'a saisi, chacun posé au jour de l'histoire que son mois désigne.
 *
 * Chaque cas se place par décalage de mois depuis le mois du semis (« M »),
 * de M-12, le mois qui précède les douze mois de la période « 12 derniers
 * mois », à M : jamais par une date écrite. Un achat du mois M se pose avant
 * le jour du semis (la boucle des jours s'arrête avant-hier) : un semis du
 * 1er du mois laisse le mois M vide, et la carte dit alors « Aucun achat
 * ce mois-ci » : un cas qui doit exister à toute date du semis se pose donc
 * au plus tard au mois M-1, jamais au mois M. Les produits des cas sont
 * nouveaux : les réassorts des Halles, de la République et de Fort-de-France
 * ne changent pas.
 *
 * - « Maison Ferrand Entrepôt » est le laboratoire : tous ses achats sont
 *   ceux de la carte, un mois après l'autre (M-11 aucun achat, M-10 deux
 *   produits achetés une seule fois, M-9 deux produits achetés deux fois au
 *   même prix, M-8 une hausse seule sur un achat comparé, M-7 une baisse
 *   seule sur trois achats, M-6 une hausse et une baisse de même montant,
 *   M-5 « +0,0 % », M-4 « -0,0 % », M-3 un produit offert puis payé (base
 *   nulle) et plusieurs produits masqués, M-2 un seul produit masqué et une
 *   facture corrigée après coup, M-1 un mois ordinaire sans produit masqué,
 *   M un mois ordinaire à écart négatif), avec, sur les douze mois, des
 *   produits achetés une seule fois, d'autres toujours au même prix, un
 *   achat de 1 200 pièces et aucun achat au même prix que son départ ; M-12
 *   porte les achats d'avant la période, à un autre prix que le premier
 *   achat de la période, et la période « Année précédente » les voit comme
 *   ses propres achats (un seul achat au même prix caché, d'une seule
 *   pièce) ; les achats au même prix que leur départ, cachés par la carte,
 *   sont ceux des réassorts des Halles, de la République et de
 *   Fort-de-France.
 * - « Maison Ferrand Éphémère » n'a aucun achat validé (un brouillon, qui ne
 *   compte pas) ; « Maison Ferrand Marché » n'a qu'un produit, acheté une
 *   seule fois.
 * - Dans les magasins des Halles, de la République et de Fort-de-France : un
 *   produit de même nom acheté le même mois à deux prix dans deux magasins,
 *   un produit qui perd 210 € sur les douze mois à la République, une facture
 *   saisie le mois précédent et datée d'un mois à venir, une remise de
 *   ligne, un escompte de facture, un prix à six décimales et un HT de ligne
 *   écrit, un produit renommé entre deux achats, un produit archivé, et un
 *   achat reçu sans date, comme le fait l'API, validé à Fort-de-France le
 *   soir du dernier jour d'un mois, qui compte dans ce mois et non dans le
 *   suivant.
 *
 * Les achats ne se posent que dans une base qui sème la société « Commerce ou
 * atelier » : le poste et les bases modèles du site de démonstration.
 *
 * @phpstan-type LigneDAchat array{0: string, 1: int, 2: string, 3?: array<string, mixed>|null, 4?: array<string, string>}
 * @phpstan-type Geste array{geste: string, magasin: string, fournisseur?: string, lignes?: list<LigneDAchat>, facture?: array<string, string>, sansDate?: bool, dateDeLaFacture?: string|null, heure: string, cle?: string|null, reference?: string, nom?: string}
 */
final class AchatsDeLEcartDesPrix
{
    /**
     * L'heure des achats : avant les tâches de la société (8 h) et les
     * réassorts (8 h 30).
     */
    private const HEURE = '07:00';

    /**
     * Le soir du dernier jour d'un mois en Martinique, à l'heure de Paris le
     * premier jour du mois suivant : l'achat sans date qui y est validé
     * compte dans le mois qui finit.
     */
    private const SOIR_EN_MARTINIQUE = '04:30';

    /**
     * Le premier jour de la boucle des jours du semis : un achat posé plus
     * tôt ne se ferait jamais.
     */
    private const PREMIER_JOUR_DE_LA_BOUCLE = 17;

    /**
     * Le préfixe du numéro de facture de chaque fournisseur.
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'Cirerie Montagne' => 'CM',
        'Verrerie du Perche' => 'VP',
        'Maison Delcourt Textiles' => 'MDT',
        'Emballages Bourdon' => 'EB',
        'Fournitures Lacroix' => 'FL',
        'Paniers de Provence' => 'PP',
        'Grossiste Lumière' => 'GL',
        'Atelier Lin et Laine' => 'ALL',
        'Artisans de la Caraïbe' => 'ADC',
    ];

    /**
     * Les fournisseurs des trois magasins de plus, créés par la
     * propriétaire : leur nom, leur adresse, leur code postal, leur ville.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: string}>>
     */
    private const FOURNISSEURS = [
        'entrepot' => [
            'Cirerie Montagne' => ['12 chemin des Jasmins', '06130', 'Grasse'],
            'Verrerie du Perche' => ['4 rue des Souffleurs', '61400', 'Mortagne-au-Perche'],
            'Maison Delcourt Textiles' => ['88 rue de la Filature', '59100', 'Roubaix'],
            'Emballages Bourdon' => ['21 zone artisanale du Moulin', '76000', 'Rouen'],
        ],
        'ephemere' => [
            'Fournitures Lacroix' => ['7 impasse des Forgerons', '37000', 'Tours'],
        ],
        'marche' => [
            'Paniers de Provence' => ['3 route de Cavaillon', '84300', 'Cavaillon'],
        ],
    ];

    /**
     * Qui saisit les achats de chaque magasin : la propriétaire dans les
     * trois magasins de plus, la responsable ou le responsable dans les
     * autres.
     *
     * @var array<string, string>
     */
    private const AUTEURS = [
        'entrepot' => 'propriétaire commerce',
        'ephemere' => 'propriétaire commerce',
        'marche' => 'propriétaire commerce',
        'halles' => 'Camille Laurent',
        'republique' => 'Hugo Lefebvre',
        'martinique' => 'Joëlle Marie-Sainte',
    ];

    /**
     * Les achats à poser, par jour de l'histoire « AAAA-MM-JJ », dans
     * l'ordre où ils se font : par heure, puis dans l'ordre où ils sont
     * programmés (le tri de PHP est stable).
     *
     * @var array<string, list<Geste>>
     */
    private array $parJour = [];

    /**
     * Les fournisseurs de chaque magasin, par « magasin:nom ».
     *
     * @var array<string, Fournisseur>
     */
    private array $fournisseurs = [];

    /**
     * L'identifiant des factures d'achat que le semis corrige plus tard, par
     * clé.
     *
     * @var array<string, int>
     */
    private array $aCorriger = [];

    private string $moisDuSemis = '';

    /**
     * @param  array<string, GestesDuMagasin>  $gestes  les gestes de chaque magasin que ce semis sème, par clé
     */
    public function __construct(
        private readonly HorlogeDuSemis $horloge,
        private readonly SemisComplet $semis,
        private readonly array $gestes,
    ) {}

    /**
     * Les fournisseurs des trois magasins de plus, et le programme de tous
     * les achats, rangé par jour de l'histoire.
     */
    public function preparer(): void
    {
        if (! $this->semis->seme('commerce')) {
            return;
        }

        $this->moisDuSemis = Mois::duJour(Calendrier::ajouterJours($this->horloge->jour(SemisComplet::JOURS - 1), 1));

        foreach (self::FOURNISSEURS as $magasin => $fournisseurs) {
            foreach ($fournisseurs as $nom => [$adresse, $codePostal, $ville]) {
                $this->fournisseurs["{$magasin}:{$nom}"] = $this->gestes($magasin)->fiche($this->auteur($magasin), Fournisseur::class, ['nom' => $nom, 'adresse' => $adresse, 'code_postal' => $codePostal, 'ville' => $ville, 'pays' => 'FR']);
            }
        }

        $this->programmerLEntrepot();
        $this->programmerLEphemereEtLeMarche();
        $this->programmerLesHalles();
        $this->programmerLaRepublique();
        $this->programmerFortDeFrance();

        foreach ($this->parJour as &$achats) {
            usort($achats, fn (array $un, array $autre): int => $un['heure'] <=> $autre['heure']);
        }
    }

    /**
     * Les achats de ce jour de l'histoire, chacun à son heure.
     */
    public function auJour(int $rang): void
    {
        $jour = $this->horloge->jour($rang);

        foreach ($this->parJour[$jour] ?? [] as $geste) {
            $this->horloge->aller($rang, $this->stringDe($geste, 'heure'));
            $this->poser($geste, $jour);
        }
    }

    /**
     * L'Entrepôt : un mois après l'autre, chaque état de la carte.
     */
    private function programmerLEntrepot(): void
    {
        $bougieAmbre = $this->nouveau('Bougie parfumée ambre 180 g', '19.90');
        $bougieSantal = $this->nouveau('Bougie parfumée santal 180 g', '19.90');
        $photophore = $this->nouveau('Photophore en verre soufflé', '14.90');
        $vase = $this->nouveau('Vase cylindre 25 cm', '29.00');
        $plaid = $this->nouveau('Plaid chiné gris', '59.00');
        $housse = $this->nouveau('Housse de coussin en lin', '24.00');
        $coffret = $this->nouveau('Coffret de trois bougies', '79.00');
        $sachet = $this->nouveau('Sachet de lavande', '4.50');
        $torchon = $this->nouveau('Torchon en coton (lot de 3)', '15.00');
        $ruban = $this->nouveau('Ruban de satin 25 mm', '4.90');
        $diffuseur = $this->nouveau('Diffuseur rotin 100 ml', '12.90');
        $savon = $this->nouveau('Savon de Marseille 300 g', '4.50');
        $tasse = $this->nouveau('Tasse émaillée', '9.90');
        $boite = $this->nouveau('Boîte cadeau carrée', '2.90');
        $presentoir = $this->nouveau('Présentoir en chêne', '89.00');
        $cirerie = 'Cirerie Montagne';
        $verrerie = 'Verrerie du Perche';
        $textiles = 'Maison Delcourt Textiles';
        $emballages = 'Emballages Bourdon';

        // M-12 : avant la période, à un autre prix que le premier achat de
        // la période ; dans la période « Année précédente », un seul achat
        // au même prix que son départ, d'une seule pièce, et un produit qui
        // n'a pas d'écart.
        $this->acheter('entrepot', -12, 20, $emballages, [['PRESENTOIR', 1, '45.00', $presentoir]]);
        $this->acheter('entrepot', -12, 22, $cirerie, [['BOUGIE-AMBRE', 60, '7.20', $bougieAmbre]]);
        $this->acheter('entrepot', -12, 23, $emballages, [['PRESENTOIR', 1, '45.00']]);
        $this->acheter('entrepot', -12, 25, $cirerie, [['BOUGIE-AMBRE', 60, '7.20']]);
        $this->acheter('entrepot', -12, 27, $emballages, [['PRESENTOIR', 1, '48.00']]);

        // M-11 : aucun achat.
        // M-10 : des achats, mais aucun produit deux fois.
        $this->acheter('entrepot', -10, 6, $verrerie, [['PHOTOPHORE', 40, '6.20', $photophore]]);
        $this->acheter('entrepot', -10, 15, $verrerie, [['VASE-CYL-25', 24, '11.50', $vase]]);

        // M-9 : deux produits achetés deux fois au même prix, tous deux masqués.
        $this->acheter('entrepot', -9, 4, $cirerie, [['BOUGIE-SANTAL', 60, '5.40', $bougieSantal]]);
        $this->acheter('entrepot', -9, 4, $verrerie, [['TASSE-EMAIL', 36, '3.10', $tasse]]);
        $this->acheter('entrepot', -9, 18, $cirerie, [['BOUGIE-SANTAL', 60, '5.40']]);
        $this->acheter('entrepot', -9, 18, $verrerie, [['TASSE-EMAIL', 36, '3.10']]);

        // M-8 : une hausse sur un seul achat comparé, sans baisse.
        $this->acheter('entrepot', -8, 5, $cirerie, [['BOUGIE-AMBRE', 80, '5.20']]);
        $this->acheter('entrepot', -8, 19, $cirerie, [['BOUGIE-AMBRE', 80, '5.50']]);

        // M-7 : une baisse sur trois achats, sans hausse.
        $this->acheter('entrepot', -7, 3, $verrerie, [['VASE-CYL-25', 20, '11.80']]);
        $this->acheter('entrepot', -7, 12, $verrerie, [['VASE-CYL-25', 20, '11.20']]);
        $this->acheter('entrepot', -7, 24, $verrerie, [['VASE-CYL-25', 20, '10.90']]);

        // M-6 : une hausse et une baisse de même montant, écart net nul.
        $this->acheter('entrepot', -6, 7, $textiles, [['PLAID-GRIS', 10, '32.00', $plaid]]);
        $this->acheter('entrepot', -6, 8, $textiles, [['HOUSSE-LIN', 20, '8.00', $housse]]);
        $this->acheter('entrepot', -6, 14, $textiles, [['PLAID-GRIS', 10, '33.00']]);
        $this->acheter('entrepot', -6, 16, $textiles, [['HOUSSE-LIN', 20, '7.50']]);

        // M-5 et M-4 : un écart d'un centime sur 100 pièces, positif puis
        // négatif : le signe de l'écart n'est pas celui du pourcentage arrondi.
        // Aucun achat de l'Entrepôt n'a le prix de son départ sur les douze mois
        // (les réassorts des autres magasins en ont : voir la carte de chacun).
        $this->acheter('entrepot', -5, 6, $cirerie, [['COFFRET-3', 100, '40.00', $coffret]]);
        $this->acheter('entrepot', -5, 20, $cirerie, [['COFFRET-3', 100, '40.01']]);
        $this->acheter('entrepot', -4, 5, $cirerie, [['COFFRET-3', 100, '40.02']]);
        $this->acheter('entrepot', -4, 22, $cirerie, [['COFFRET-3', 100, '40.01']]);

        // M-3 : un produit offert puis payé (base nulle, pourcentage « — »),
        // et trois produits achetés une seule fois, masqués, dont 1 200 pièces.
        $this->acheter('entrepot', -3, 4, $cirerie, [['SACHET-LAV', 50, '0.00', $sachet]]);
        $this->acheter('entrepot', -3, 10, $textiles, [['TORCHON-3', 24, '4.10', $torchon]]);
        $this->acheter('entrepot', -3, 12, $emballages, [['BOITE-CADEAU', 1200, '0.85', $boite]]);
        $this->acheter('entrepot', -3, 18, $cirerie, [['SACHET-LAV', 50, '2.00']]);
        $this->acheter('entrepot', -3, 21, $emballages, [['RUBAN-25', 30, '1.60', $ruban]]);

        // M-2 : un seul produit masqué, et une facture saisie à 4,01 € au lieu
        // de 4,10 €, corrigée deux jours après : la carte lit le prix corrigé.
        $this->acheter('entrepot', -2, 3, $cirerie, [['DIFF-ROTIN', 48, '3.90', $diffuseur]]);
        $this->acheter('entrepot', -2, 8, $cirerie, [['SAVON-300', 100, '1.45', $savon]]);
        $this->acheter('entrepot', -2, 17, $cirerie, [['DIFF-ROTIN', 48, '4.01']], cle: 'diffuseur-corrige');
        $this->corriger('entrepot', -2, 19, $cirerie, [['DIFF-ROTIN', 48, '4.10']], 'diffuseur-corrige', -2, 17);

        // M-1 : un mois ordinaire, trois produits acheminés deux fois, aucun masqué.
        $this->acheter('entrepot', -1, 5, $cirerie, [['BOUGIE-AMBRE', 80, '5.25']]);
        $this->acheter('entrepot', -1, 8, $verrerie, [['PHOTOPHORE', 40, '6.25']]);
        $this->acheter('entrepot', -1, 12, $emballages, [['BOITE-CADEAU', 1200, '0.86']]);
        $this->acheter('entrepot', -1, 21, $cirerie, [['BOUGIE-AMBRE', 80, '5.35']]);
        $this->acheter('entrepot', -1, 23, $verrerie, [['PHOTOPHORE', 40, '6.40']]);
        $this->acheter('entrepot', -1, 26, $emballages, [['BOITE-CADEAU', 1200, '0.88']]);

        // M : un mois ordinaire à écart négatif.
        $this->acheter('entrepot', 0, 3, $verrerie, [['VASE-CYL-25', 24, '11.20']]);
        $this->acheter('entrepot', 0, 4, $textiles, [['PLAID-GRIS', 10, '33.50']]);
        $this->acheter('entrepot', 0, 9, $verrerie, [['VASE-CYL-25', 24, '10.60']]);
        $this->acheter('entrepot', 0, 10, $textiles, [['PLAID-GRIS', 10, '32.80']]);
    }

    /**
     * L'Éphémère n'a qu'un brouillon d'achat, qui ne compte pas ; le Marché
     * n'a qu'un produit, acheté une seule fois.
     */
    private function programmerLEphemereEtLeMarche(): void
    {
        $this->acheter('ephemere', -2, 10, 'Fournitures Lacroix', [['LANTERNE-ZINC', 12, '22.00', $this->nouveau('Lanterne de jardin en zinc', '54.00')]], brouillon: true);
        $this->acheter('marche', -4, 14, 'Paniers de Provence', [['PANIER-OSIER', 12, '9.80', $this->nouveau('Panier en osier', '24.90')]]);
    }

    /**
     * Les Halles : un produit renommé entre ses deux achats, une théière
     * que la République achète aussi, les cinq façons d'écrire un prix (une
     * remise de ligne, un escompte de facture, six décimales, un HT de ligne
     * écrit, un prix franc) et une facture datée du mois à venir.
     */
    private function programmerLesHalles(): void
    {
        $grossiste = 'Grossiste Lumière';
        $this->acheter('halles', -9, 10, $grossiste, [['PLATEAU-BAMBOU', 12, '6.50', $this->nouveau('Plateau en bambou', '14.90')]]);
        $this->renommer('halles', -8, 2, 'PLATEAU-BAMBOU', 'Plateau en bambou clair');
        $this->acheter('halles', -8, 20, $grossiste, [['PLATEAU-BAMBOU', 12, '6.80']]);

        $this->acheter('halles', -5, 9, $grossiste, [['THEIERE-FONTE', 6, '18.00', $this->nouveau('Théière en fonte 1 l', '39.00')]]);
        $this->acheter('halles', -5, 23, $grossiste, [['THEIERE-FONTE', 6, '18.50']]);

        // Cinq achats d'un set de table au même coût net de 12,00 € ou presque :
        // le prix écrit diffère, le coût net de la ligne seul compte, arrondi
        // une seule fois au centime.
        $this->acheter('halles', -4, 5, $grossiste, [['SET-TABLE', 10, '12.00', $this->nouveau('Set de table en coton (lot de 4)', '29.00')]]);
        $this->acheter('halles', -4, 9, $grossiste, [['SET-TABLE', 10, '15.00', null, ['remise_pourcentage' => '20']]]);
        $this->acheter('halles', -4, 13, $grossiste, [['SET-TABLE', 10, '12.50']], facture: ['escompte_pourcentage' => '4']);
        $this->acheter('halles', -4, 17, $grossiste, [['SET-TABLE', 10, '12.004950']]);
        $this->acheter('halles', -4, 21, $grossiste, [['SET-TABLE', 10, '12.0040', null, ['montant_ht' => '120.05']]]);
        $this->acheter('halles', -4, 25, $grossiste, [['SET-TABLE', 10, '12.40']]);

        // La boule de Noël, commandée le 10 du mois qui précède le semis, est
        // facturée d'avance : la seconde facture, saisie le 14 de ce mois,
        // porte le 10 du mois qui suit le semis. Les deux se posent dans un
        // mois révolu, que la boucle des jours atteint quel que soit le jour
        // du semis : le cas « achat daté d'un mois à venir » existe toujours,
        // alors que le mois M reste vide un semis du 1er du mois.
        $this->acheter('halles', -1, 10, $grossiste, [['BOULE-NOEL', 30, '8.00', $this->nouveau('Boule de Noël en verre (boîte de 6)', '19.90')]]);
        $this->acheter('halles', -1, 14, $grossiste, [['BOULE-NOEL', 30, '8.40']], dateDeLaFacture: Mois::decaler($this->moisDuSemis, 1).'-10');
    }

    /**
     * La République : la théière des Halles à d'autres prix, une lampe dont
     * le prix baisse de 210 € sur les douze mois, un cadre photo archivé.
     */
    private function programmerLaRepublique(): void
    {
        $atelier = 'Atelier Lin et Laine';
        $this->acheter('republique', -9, 8, $atelier, [['LAMPE-CERAMIQUE', 40, '10.00', $this->nouveau('Lampe de chevet en céramique', '34.00')]]);
        $this->acheter('republique', -4, 12, $atelier, [['LAMPE-CERAMIQUE', 60, '8.50']]);
        $this->acheter('republique', -1, 14, $atelier, [['LAMPE-CERAMIQUE', 60, '8.00']]);

        $this->acheter('republique', -8, 6, $atelier, [['CADRE-1318', 24, '3.20', $this->nouveau('Cadre photo 13 × 18', '9.90')]]);
        $this->acheter('republique', -6, 9, $atelier, [['CADRE-1318', 24, '3.40']]);
        $this->archiver('republique', -3, 2, 'CADRE-1318');

        $this->acheter('republique', -5, 10, $atelier, [['THEIERE-FONTE', 6, '21.00', $this->nouveau('Théière en fonte 1 l', '45.00')]]);
        $this->acheter('republique', -5, 24, $atelier, [['THEIERE-FONTE', 6, '20.00']]);
    }

    /**
     * Fort-de-France : un savon acheté le 10 d'un mois, puis une seconde
     * fois par un achat reçu sans date, validé à 23 h 30 en Martinique le
     * dernier jour de ce mois (le premier du suivant, à 4 h 30 à Paris) :
     * rangé au jour de sa validation dans le calendrier de la Martinique, il
     * compte dans le mois qui finit.
     */
    private function programmerFortDeFrance(): void
    {
        $artisans = 'Artisans de la Caraïbe';
        $this->acheter('martinique', -7, 10, $artisans, [['SAVON-COCO', 60, '2.40', $this->nouveau('Savon de coco artisanal', '4.50', '8.5')]]);
        $this->acheter('martinique', -6, 1, $artisans, [['SAVON-COCO', 60, '2.60']], heure: self::SOIR_EN_MARTINIQUE, sansDate: true);
    }

    /**
     * Un achat à poser ce jour : une facture d'achat validée, ou un
     * brouillon, de ces lignes.
     *
     * @param  list<array{0: string, 1: int, 2: string, 3?: array<string, mixed>|null, 4?: array<string, string>}>  $lignes  la référence, la quantité, le prix unitaire HT écrit, la fiche du produit s'il naît, ce que la ligne ajoute (remise, HT écrit)
     * @param  array<string, string>  $facture  ce que la facture ajoute (escompte)
     */
    private function acheter(string $magasin, int $mois, int $jour, string $fournisseur, array $lignes, array $facture = [], bool $brouillon = false, bool $sansDate = false, ?string $dateDeLaFacture = null, string $heure = self::HEURE, ?string $cle = null): void
    {
        $this->ajouter($mois, $jour, [
            'geste' => $brouillon ? 'brouillon' : 'acheter',
            'magasin' => $magasin,
            'fournisseur' => $fournisseur,
            'lignes' => $lignes,
            'facture' => $facture,
            'sansDate' => $sansDate,
            'dateDeLaFacture' => $dateDeLaFacture,
            'heure' => $heure,
            'cle' => $cle,
        ]);
    }

    /**
     * La correction, ce jour, d'une facture d'achat validée par ces lignes ;
     * la facture a été saisie le jour donné, dont la date reste la sienne.
     *
     * @param  list<array{0: string, 1: int, 2: string}>  $lignes
     */
    private function corriger(string $magasin, int $mois, int $jour, string $fournisseur, array $lignes, string $cle, int $moisDeLaFacture, int $jourDeLaFacture): void
    {
        $this->ajouter($mois, $jour, [
            'geste' => 'corriger',
            'magasin' => $magasin,
            'fournisseur' => $fournisseur,
            'lignes' => $lignes,
            'cle' => $cle,
            'dateDeLaFacture' => $this->date($moisDeLaFacture, $jourDeLaFacture),
            'heure' => self::HEURE,
        ]);
    }

    private function renommer(string $magasin, int $mois, int $jour, string $reference, string $nom): void
    {
        $this->ajouter($mois, $jour, ['geste' => 'renommer', 'magasin' => $magasin, 'reference' => $reference, 'nom' => $nom, 'heure' => self::HEURE]);
    }

    private function archiver(string $magasin, int $mois, int $jour, string $reference): void
    {
        $this->ajouter($mois, $jour, ['geste' => 'archiver', 'magasin' => $magasin, 'reference' => $reference, 'heure' => self::HEURE]);
    }

    /**
     * La fiche d'un produit qui naît d'un achat.
     *
     * @return array<string, mixed>
     */
    private function nouveau(string $nom, string $prixDeVenteTtc, string $tauxDeTva = '20'): array
    {
        return ['nom' => $nom, 'prix_vente_ttc' => $prixDeVenteTtc, 'taux_tva' => $tauxDeTva, 'suivi_stock' => true, 'seuil_alerte' => 5];
    }

    /**
     * @param  Geste  $geste
     */
    private function ajouter(int $mois, int $jour, array $geste): void
    {
        $this->parJour[$this->date($mois, $jour)][] = $geste;
    }

    /**
     * Le jour « AAAA-MM-JJ » de ce quantième du mois du semis décalé de
     * $mois mois : un achat qui tomberait avant le début de la boucle des
     * jours ne se ferait jamais et ferait manquer la naissance de son produit.
     *
     * @throws LogicException quand ce jour est avant le début de la boucle des jours
     */
    private function date(int $mois, int $jour): string
    {
        $date = Mois::decaler($this->moisDuSemis, $mois).'-'.sprintf('%02d', $jour);

        if ($date < $this->horloge->jour(self::PREMIER_JOUR_DE_LA_BOUCLE)) {
            throw new LogicException("L'achat du {$date} précède le début de la boucle des jours du semis.");
        }

        return $date;
    }

    /**
     * @param  Geste  $geste
     */
    private function poser(array $geste, string $jour): void
    {
        $magasin = $this->stringDe($geste, 'magasin');
        $gestes = $this->gestes($magasin);
        $auteur = $this->auteur($magasin);

        match ($geste['geste']) {
            'acheter' => $this->poserUnAchat($gestes, $auteur, $geste, $jour, true),
            'brouillon' => $this->poserUnAchat($gestes, $auteur, $geste, $jour, false),
            'corriger' => $this->corrigerUnAchat($gestes, $auteur, $geste),
            'renommer' => $gestes->dans(function () use ($gestes, $auteur, $geste): void {
                $produit = $gestes->produit($this->stringDe($geste, 'reference'));
                app(ModifierUneFicheAction::class)->handle($auteur, $produit, FicheLue::de($produit)->valeurs(), ['nom' => $this->stringDe($geste, 'nom')]);
            }),
            'archiver' => $gestes->dans(fn () => app(ArchiverUneFicheAction::class)->archiver($auteur, $gestes->produit($this->stringDe($geste, 'reference')))),
            default => throw new LogicException('Geste d\'achat inconnu : '.$this->stringDe($geste, 'geste')),
        };
    }

    /**
     * @param  Geste  $geste
     */
    private function poserUnAchat(GestesDuMagasin $gestes, User $auteur, array $geste, string $jour, bool $valider): void
    {
        $lignes = $this->lignes($gestes, $geste);
        $fournisseur = $this->fournisseur($this->stringDe($geste, 'magasin'), $this->stringDe($geste, 'fournisseur'));
        $numero = $this->numero($fournisseur, $jour);
        $date = is_string($geste['dateDeLaFacture'] ?? null) ? $geste['dateDeLaFacture'] : $jour;
        $attributs = ($geste['sansDate'] ?? false) === true
            ? ['source' => SourceFactureAchat::Api, 'numero_fournisseur' => $numero]
            : ['date_facture' => $date, 'numero_fournisseur' => $numero];
        $attributs = [...$attributs, ...($geste['facture'] ?? [])];

        if (! $valider) {
            $gestes->dans(fn (): FactureAchat => app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, $lignes, $attributs));

            return;
        }

        $facture = $gestes->acheter($auteur, $fournisseur, $lignes, $attributs);

        if (is_string($geste['cle'] ?? null)) {
            $this->aCorriger[$geste['cle']] = $facture->id;
        }
    }

    /**
     * @param  Geste  $geste
     */
    private function corrigerUnAchat(GestesDuMagasin $gestes, User $auteur, array $geste): void
    {
        $cle = $this->stringDe($geste, 'cle');
        $identifiant = $this->aCorriger[$cle] ?? throw new LogicException("Aucune facture d'achat semée n'a la clé {$cle}.");
        $fournisseur = $this->fournisseur($this->stringDe($geste, 'magasin'), $this->stringDe($geste, 'fournisseur'));

        $gestes->dans(fn (): FactureAchat => app(ModifierFactureAchatAction::class)->handle(
            $auteur,
            FactureAchat::query()->findOrFail($identifiant),
            $fournisseur,
            $this->lignes($gestes, $geste),
            ['date_facture' => $this->stringDe($geste, 'dateDeLaFacture'), 'numero_fournisseur' => $this->numero($fournisseur, $this->stringDe($geste, 'dateDeLaFacture'))],
        ));
    }

    /**
     * Les lignes d'un achat telles que l'action d'achat les reçoit : le
     * produit par sa fiche quand il naît, par son identifiant sinon.
     *
     * @param  Geste  $geste
     * @return list<array<string, mixed>>
     */
    private function lignes(GestesDuMagasin $gestes, array $geste): array
    {
        $lignes = [];

        foreach ($geste['lignes'] ?? [] as $ligne) {
            [$reference, $quantite, $prix] = $ligne;
            $fiche = $ligne[3] ?? null;
            $ajout = $ligne[4] ?? [];
            $lignes[] = [
                ...($fiche === null ? ['produit_id' => $gestes->produit($reference)->id] : ['produit' => [...$fiche, 'reference' => $reference]]),
                'quantite' => $quantite,
                'prix_unitaire_ht' => $prix,
                ...$ajout,
            ];
        }

        return $lignes;
    }

    private function fournisseur(string $magasin, string $nom): Fournisseur
    {
        return $this->fournisseurs["{$magasin}:{$nom}"] ??= $this->gestes($magasin)->dans(fn (): Fournisseur => Fournisseur::query()->where('nom', $nom)->sole());
    }

    private function gestes(string $magasin): GestesDuMagasin
    {
        return $this->gestes[$magasin] ?? throw new LogicException("Le magasin {$magasin} n'est pas semé.");
    }

    private function auteur(string $magasin): User
    {
        return $this->semis->compte(self::AUTEURS[$magasin] ?? throw new LogicException("Personne ne saisit les achats du magasin {$magasin}."));
    }

    /**
     * Le numéro de la facture de ce fournisseur datée de ce jour : une seule
     * facture par fournisseur et par jour.
     */
    private function numero(Fournisseur $fournisseur, string $jour): string
    {
        return (self::PREFIXES[(string) $fournisseur->nom] ?? throw new LogicException("Le fournisseur {$fournisseur->nom} n'a pas de préfixe de facture.")).'-'.str_replace('-', '', $jour);
    }

    /**
     * @param  array<string, mixed>  $geste
     */
    private function stringDe(array $geste, string $cle): string
    {
        $valeur = $geste[$cle] ?? null;

        return is_string($valeur) ? $valeur : throw new LogicException("Le geste d'achat n'a pas de {$cle}.");
    }
}
