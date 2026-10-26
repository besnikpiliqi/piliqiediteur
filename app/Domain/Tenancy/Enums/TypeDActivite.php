<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le type d'activité d'une société, choisi à son inscription dans un menu
 * sans valeur par défaut (voir InscriptionSociete), confirmé sur la page
 * du lien d'inscription (voir ConfirmerInscriptionController) et
 * définitif dès la création de la société : aucun écran ni aucune
 * procédure de l'éditeur ne le change, d'aucun type vers aucun autre, et
 * la base refuse un type inconnu et toute modification (voir
 * GardesDesTypesDActivite). Une inscription faite avec un mauvais type se
 * clôt faute de code ou par la procédure de l'éditeur (voir
 * MotifCloture::TypeErrone).
 *
 * Il ne fixe que ce qui change les menus et les contrôles du logiciel :
 * - Commerce (« Commerce ou atelier ») : tout le logiciel, articles achetés
 *   et suivis en stock compris ; le choix conseillé en cas de doute ;
 * - Services (« Services, sans vente d'articles ») : services seulement,
 *   avec la caisse de comptoir, sans articles (voir aDesArticles()) ; les
 *   métiers du bâtiment s'y inscrivent, sans règle propre ;
 * - Cabinet (« Cabinet d'expertise comptable ») : sans magasin ni
 *   démonstration (voir aDesMagasins()), son code d'activité 69.20Z
 *   contrôlé au répertoire Sirene avant tout courrier (voir
 *   ControleSirene::exerceLesActivitesComptables()).
 * Ce qui peut commencer ou s'arrêter sans changer les menus (franchise en
 * base, option pour les débits, ventes hors de France) reste un réglage,
 * jamais un type. Une autre entrée ne viendra que par une décision de
 * l'éditeur. L'ordre des cas est celui du menu.
 */
enum TypeDActivite: string implements HasLabel
{
    case Commerce = 'commerce';
    case Services = 'services';
    case Cabinet = 'cabinet';

    public function getLabel(): string
    {
        return match ($this) {
            self::Commerce => 'Commerce ou atelier',
            self::Services => 'Services, sans vente d\'articles',
            self::Cabinet => 'Cabinet d\'expertise comptable',
        };
    }

    /**
     * La ligne d'exemples que le menu montre sous le libellé : deux métiers
     * qui mènent au même comportement partagent une entrée et figurent dans
     * ses exemples ; le menu ne cite jamais la nomenclature d'activités.
     */
    public function exemples(): string
    {
        return match ($this) {
            self::Commerce => 'Boutique (vêtements, cadeaux, bijoux), épicerie, cave, fleuriste, librairie, opticien, grossiste ; réparation de téléphones, d\'ordinateurs, d\'électroménager ou de vélos, garage, cordonnerie, horlogerie ; salon de coiffure ou institut qui vend des produits.',
            self::Services => 'Consultant, développeur, agence de communication, graphiste, photographe, architecte, géomètre, agent immobilier, formateur dont les formations sont soumises à la TVA ou en franchise, coach, nettoyage, taxi ou VTC, déménagement, location de matériel, auto-école, coiffure ou esthétique sans vente de produits ; plombier, chauffagiste, électricien, maçon, peintre, plaquiste, carreleur, menuisier poseur, couvreur, climaticien, entreprise de rénovation.',
            self::Cabinet => 'Société d\'expertise comptable, expert-comptable exerçant seul, association de gestion et de comptabilité, inscrits au tableau de l\'Ordre.',
        };
    }

    /**
     * L'aide de l'entrée choisie, que le formulaire montre sous le menu.
     */
    public function aide(): string
    {
        return match ($this) {
            self::Commerce => 'Vous vendez des articles achetés à vos fournisseurs et suivis en stock, avec ou sans votre main-d\'œuvre et vos services. C\'est le choix le plus complet : prenez-le si vous vendez ne serait-ce que quelques articles. Seules les marchandises que vous revendez s\'enregistrent en facture d\'achat ; vos matières premières, fournitures et frais, pas encore. La vente de ce que vous fabriquez ou préparez vous-même n\'est pas prise en charge.',
            self::Services => 'Vous facturez votre travail, votre temps ou une prestation, sans vendre d\'articles : services, devis, factures, encaissements et caisse de comptoir ; ni articles, ni achats de marchandises, ni stock, ni inventaire. Les métiers du bâtiment s\'inscrivent ici : vos travaux, fournitures comprises, se facturent en lignes de service (fourniture et pose). Si la loi exige une assurance professionnelle pour votre activité (la garantie décennale des travaux du bâtiment, par exemple), renseignez-la dans l\'identité de votre société : '.config('app.name').' l\'imprime sur vos devis et vos factures. Ce choix ne permet pas de vendre des articles.',
            self::Cabinet => 'Réservé aux cabinets inscrits au tableau de l\'Ordre des experts-comptables, dont le code d\'activité est 69.20Z : un espace pour suivre vos clients, sans magasin ni vente. L\'inscription à l\'Ordre est vérifiée.',
        };
    }

    /**
     * Ce que le formulaire rappelle toujours sous le menu, quelle que soit
     * l'entrée choisie.
     */
    public static function rappelDuChoixDefinitif(): string
    {
        $application = config('app.name');

        return "Ce choix est définitif : il décide des menus de {$application} (articles, achats et stock) et ne changera pas, même si votre activité change ensuite. Dans le doute, choisissez « ".self::Commerce->getLabel()." », le plus complet. {$application} ne permet pas de facturer une activité exonérée de TVA (soins, enseignement, assurance, location de logements…).";
    }

    /**
     * Les entrées du menu, dans son ordre, chacune avec son libellé et, en
     * dessous, sa ligne d'exemples, en HTML échappé.
     *
     * @param  list<self>  $types
     * @return array<string, string>
     */
    public static function optionsDuMenu(array $types): array
    {
        return collect($types)
            ->mapWithKeys(fn (self $type): array => [$type->value => '<span class="font-medium">'.e($type->getLabel()).'</span><br><span class="text-sm text-gray-500 dark:text-gray-400">'.e($type->exemples()).'</span>'])
            ->all();
    }

    /**
     * Une société de ce type a-t-elle des magasins, démonstration
     * comprise ? Jamais un cabinet d'expertise comptable : la base refuse
     * elle-même un magasin dans un cabinet (voir GardesDesTypesDActivite).
     */
    public function aDesMagasins(): bool
    {
        return $this !== self::Cabinet;
    }

    /**
     * Une société de ce type a-t-elle des articles : produits, stock,
     * achats de marchandises, avoirs fournisseur, inventaire d'ouverture,
     * barème des prix de vente, vente sans stock, écran des fournisseurs,
     * natures d'opération qui portent sur des biens, et les autorisations
     * de la famille des articles (voir PermissionRegistry::articles()) ?
     * Le commerce seul ; la base refuse les produits, factures d'achat,
     * avoirs fournisseur et inventaires d'ouverture des autres types (voir
     * GardesDesTypesDActivite).
     */
    public function aDesArticles(): bool
    {
        return $this === self::Commerce;
    }
}
