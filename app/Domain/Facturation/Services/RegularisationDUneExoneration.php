<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;

/**
 * La régularisation d'une vente exonérée dont la preuve de sortie n'est
 * jamais venue (T29.3 de l'onglet « À faire vérifier par un
 * professionnel », décision de Claude appliquée ; une réponse d'un
 * professionnel la remplacerait) : un export hors de l'Union (art. 262 I
 * du CGI) ou une livraison vers un DOM (art. 294 du CGI) sans déclaration
 * de sortie (MRN ou CN23) enregistrée 150 jours après sa date, en jours du
 * calendrier du magasin (voir PreuvesRequises::JOURS_AVANT_LA_REGULARISATION),
 * perd son exonération : la vente redevient une vente en France, au même
 * prix TTC, le magasin reversant la TVA sur ce que le client a payé (sauf
 * si le client accepte de payer la TVA en plus, ce qui se fait par un avoir
 * et une nouvelle facture ordinaires). Le rappel du 31e jour reste (voir
 * PreuvesRequises::enRetard()).
 *
 * Elle se fait d'un seul geste (voir
 * RegulariserUneExonerationNonProuveeAction), dans une même transaction
 * signée :
 * - l'avoir de régularisation de la facture (ou du ticket), motif
 *   ExonerationNonProuvee : il crédite ce qui reste à créditer de chaque
 *   ligne (voir RegularisationDUneFacture::lignesDeLAvoir()), sans retour
 *   en stock, sous l'identité actuelle de la société, avec la mention «
 *   Avoir de régularisation : preuve de sortie non reçue pour la facture
 *   n° … » (voir MentionsFiscales::regularisation()) ;
 * - sa facture rectificative, une vente en France, au même client, sans
 *   sortie de stock : chaque ligne reprend, pour ce qu'aucun avoir
 *   ordinaire n'a crédité, la quantité et le TTC payé de la ligne
 *   d'origine (son HT, puisqu'elle était sans TVA), au taux français de
 *   l'article (voir lignesALaTvaFrancaise()), son HT et sa TVA calculés
 *   depuis ce TTC selon la règle des ventes (voir
 *   CalculateurLigne::chiffrerUneVente()) : un export de 500,00 € devient
 *   une vente de 500,00 € TTC à 20 %, soit 83,33 € de TVA (83,333...) et
 *   416,67 € HT.
 *   Le crédit de l'avoir règle d'abord ce qui reste dû de la vente
 *   d'origine, puis cette facture (voir ImputationDuCredit et
 *   AppliquerAvoirsDisponiblesAction) : elle reçoit ce que la vente avait
 *   reçu et reste due de ce que le client y devait encore.
 *
 * Toutes les lectures restent dans le magasin actif (MagasinScope) ; les
 * preuves et la rectificative se lisent dans le magasin de chaque facture
 * (voir BelongsToMagasin::scopeDuMagasinDe()).
 */
class RegularisationDUneExoneration
{
    /**
     * Les natures dont l'exonération tient à une preuve de sortie des
     * biens (déclaration d'export, MRN, ou CN23 d'un colis postal).
     *
     * @var list<NatureOperation>
     */
    public const NATURES = [NatureOperation::ExportHorsUe, NatureOperation::LivraisonDom];

    /**
     * Les preuves qui attestent la sortie des biens.
     *
     * @var list<TypePreuve>
     */
    public const PREUVES_DE_SORTIE = [TypePreuve::Mrn, TypePreuve::Cn23];

    public const MOTIF_DE_L_AVOIR = 'Régularisation de %s %s : preuve de sortie non reçue';

    public const REFUS_NATURE = 'Seul un export hors de l\'Union européenne ou une livraison vers un DOM se régularise faute de preuve de sortie.';

    public const REFUS_NON_EMISE = 'Seul un document émis se régularise.';

    public const REFUS_DEJA_REGULARISEE = 'Cette vente a déjà sa facture rectificative : elle ne se régularise plus.';

    public const REFUS_PREUVE_RECUE = 'La preuve de sortie de cette vente (MRN ou CN23) est enregistrée : son exonération est justifiée.';

    public const REFUS_TROP_TOT = 'Cette vente ne se régularise qu\'à partir du %s, 150 jours après sa date, si sa preuve de sortie manque encore.';

    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly RegularisationDUneFacture $regularisation,
    ) {}

    /**
     * Pourquoi cette facture ne se régularise pas ce jour (AAAA-MM-JJ,
     * calendrier de son magasin) ; null quand rien ne l'empêche : elle
     * n'est pas un export ni une livraison vers un DOM, pas émise, déjà
     * régularisée, sa preuve de sortie est enregistrée, la régularisation
     * du SIREN de la société la retient (voir
     * RegularisationDUneFacture::refusDUnAvoirOrdinaire()), des avoirs ont
     * déjà crédité toutes ses lignes, ou ses 150 jours ne sont pas passés.
     */
    public function refus(Facture $facture, string $aujourdhui): ?string
    {
        return match (true) {
            ! in_array($facture->nature_operation, self::NATURES, true) => self::REFUS_NATURE,
            $facture->numero === null || $facture->date_emission === null => self::REFUS_NON_EMISE,
            $facture->rectificative()->exists() => self::REFUS_DEJA_REGULARISEE,
            $facture->preuvesOperation()->whereIn('type', self::PREUVES_DE_SORTIE)->exists() => self::REFUS_PREUVE_RECUE,
            ($refus = $this->regularisation->refusDUnAvoirOrdinaire($facture)) !== null => $refus,
            $this->regularisation->lignesDeLAvoir($facture) === [] => RegularisationDUneFacture::REFUS_RIEN_A_REGULARISER,
            ($premierJour = self::premierJour($facture)) > $aujourdhui => sprintf(self::REFUS_TROP_TOT, Calendrier::afficherJour($premierJour, 'd/m/Y')),
            default => null,
        };
    }

    /**
     * Le premier jour (AAAA-MM-JJ, calendrier de son magasin) où cette
     * facture émise se régularise si sa preuve manque encore : le 150e
     * jour qui suit sa date (une facture du 2 octobre 2026 le 1er mars
     * 2027).
     */
    public static function premierJour(Facture $facture): string
    {
        return Calendrier::ajouterJours(
            $facture->magasin->calendrier()->jourDe(ValeurRequise::de($facture->date_emission, 'la date d\'émission de la facture')),
            PreuvesRequises::JOURS_AVANT_LA_REGULARISATION,
        );
    }

    /**
     * Restreint les factures à celles qui se régularisent ce jour (voir
     * refus()), en une seule requête : émises, d'export ou de livraison
     * vers un DOM, datées d'au moins 150 jours dans le calendrier de leur
     * magasin, sans preuve de sortie, avec une ligne encore à créditer et
     * sans facture rectificative ; les preuves, les lignes et la
     * rectificative se lisent dans le magasin de chaque facture. Le filtre
     * de la liste des factures et l'« À faire » du tableau de bord du
     * magasin (voir ChosesAFaireDuMagasin) s'y lisent.
     *
     * @template TFacture of Facture
     *
     * @param  Builder<TFacture>  $factures
     * @return Builder<TFacture>
     */
    public function aRegulariser(Builder $factures, Calendrier $calendrier, string $aujourdhui): Builder
    {
        $magasin = $factures->qualifyColumn('magasin_id');

        return $factures
            ->emises()
            ->whereIn($factures->qualifyColumn('nature_operation'), self::NATURES)
            ->where($factures->qualifyColumn('date_emission'), '<', $calendrier->debutDuJour(Calendrier::ajouterJours($aujourdhui, 1 - PreuvesRequises::JOURS_AVANT_LA_REGULARISATION)))
            ->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->duMagasinDe($magasin)->creditables())
            ->whereDoesntHave('preuvesOperation', fn (Builder $preuves): Builder => $preuves->duMagasinDe($magasin)->whereIn('type', self::PREUVES_DE_SORTIE))
            ->whereDoesntHave('rectificative', fn (Builder $rectificatives): Builder => $rectificatives->duMagasinDe($magasin));
    }

    /**
     * Les lignes de la facture rectificative, dans leur ordre : chaque
     * ligne de la facture, pour ce qu'aucun avoir ordinaire n'en a crédité
     * (voir RegularisationDUneFacture::lignesDeLaRectificative()), au même
     * TTC, celui que le client a payé, à la TVA française :
     * - le taux est celui que comprenait le prix de la ligne (le taux de la
     *   fiche du produit, ou du service vendu à son prix de référence, au
     *   jour de la vente) ; un service vendu à un autre prix, déjà sans
     *   TVA, prend le taux de sa fiche, sinon celui du territoire du
     *   magasin ;
     * - le HT et la TVA se calculent depuis ce TTC, ligne par ligne et
     *   taux par taux, comme toute vente (voir
     *   CalculateurLigne::chiffrerUneVente()) ;
     * - le prix unitaire payé (le PU HT de la ligne d'origine, au
     *   millionième) n'est en général pas un prix au centime : le PU HT
     *   enregistré est le HT de la ligne ÷ (la quantité × (100 % - la
     *   remise gardée)), au millionième (voir
     *   CalculateurLigne::prixUnitaireHtDuMontant()), si bien que la
     *   quantité × ce PU HT, remise déduite, retombe sur le HT de la ligne à
     *   moins de deux centimes près jusqu'à 10 000 articles, comme le dit la
     *   note de calcul d'une facture à un professionnel (voir
     *   MentionsFiscales::calcul()) : 10 000 articles payés 83 333,33 € font
     *   69 444,44 € HT, à 6,944444 € ; le PU TTC est ce prix payé, au
     *   centime (8,33 €), que seul un ticket imprime, avec la note qui dit
     *   que la quantité × le prix unitaire affiché peut différer du total de
     *   la ligne (décision de Claude, D76 : la ligne garde le TTC payé, et
     *   jamais une seconde ligne du même produit ne la complète) ; une
     *   remise de 100 % ne laisse aucun HT, et le PU HT est alors la
     *   conversion du PU TTC.
     * Aucune quand tout a été crédité.
     *
     * @return list<array{produit_id: int|null, service_id: int|null, nature: NatureLigne, description: string, reference: string|null, quantite: int, prix_unitaire_ht: string, prix_unitaire_ttc: string, taux_tva: string, taux_tva_du_prix: string, remise_pourcentage: string, montant_ht: string, montant_tva: string, montant_ttc: string, ordre: int}>
     */
    public function lignesALaTvaFrancaise(Facture $facture): array
    {
        $restantes = $this->regularisation->lignesDeLaRectificative($facture);
        $services = Service::query()->whereKey(array_values(array_filter(array_column($restantes, 'service_id'))))->get()->keyBy('id');
        $tauxParDefaut = $this->calculateur->enCentiemes($facture->magasin->tauxTvaParDefaut());
        $taux = array_map(fn (array $ligne): int => match (true) {
            $ligne['service_id'] !== null && $this->calculateur->enCentiemes($ligne['taux_tva_du_prix']) === 0 => ($tauxDuService = $services->get($ligne['service_id'])?->taux_tva) === null ? $tauxParDefaut : $this->calculateur->enCentiemes($tauxDuService),
            default => $this->calculateur->enCentiemes($ligne['taux_tva_du_prix']),
        }, $restantes);
        $chiffre = $this->calculateur->chiffrerUneVente(array_map(fn (array $ligne, int $tauxDeLaLigne): array => [
            'prix_unitaire_ttc' => $this->calculateur->enCentiemes($ligne['montant_ttc']),
            'quantite' => 1,
            'remise_pourcentage' => 0,
            'taux_tva_du_prix' => $tauxDeLaLigne,
            'taux_tva' => $tauxDeLaLigne,
        ], $restantes, $taux));

        $lignes = [];

        foreach ($restantes as $index => $ligne) {
            $prixUnitaireTtc = $this->calculateur->prixUnitaireAuCentime($this->calculateur->enMillioniemes($ligne['prix_unitaire_ht']));
            $montants = $chiffre['lignes'][$index];
            $prixUnitaireHt = $this->calculateur->prixUnitaireHtDuMontant($montants['montant_ht'], $ligne['quantite'], $this->calculateur->enCentiemes($ligne['remise_pourcentage']))
                ?? $this->calculateur->prixUnitaireHtExact($prixUnitaireTtc, $taux[$index]);

            $lignes[] = [
                'produit_id' => $ligne['produit_id'],
                'service_id' => $ligne['service_id'],
                'nature' => $ligne['nature'],
                'description' => $ligne['description'],
                'reference' => $ligne['reference'],
                'quantite' => $ligne['quantite'],
                'prix_unitaire_ht' => $this->calculateur->enDecimalAuMillionieme($prixUnitaireHt),
                'prix_unitaire_ttc' => $this->calculateur->enDecimal($prixUnitaireTtc),
                'taux_tva' => $this->calculateur->enDecimal($taux[$index]),
                'taux_tva_du_prix' => $this->calculateur->enDecimal($taux[$index]),
                'remise_pourcentage' => ValeursLues::texte($ligne['remise_pourcentage']),
                'montant_ht' => $this->calculateur->enDecimal($montants['montant_ht']),
                'montant_tva' => $this->calculateur->enDecimal($montants['montant_tva']),
                'montant_ttc' => $this->calculateur->enDecimal($montants['montant_ttc']),
                'ordre' => $index,
            ];
        }

        return $lignes;
    }
}
