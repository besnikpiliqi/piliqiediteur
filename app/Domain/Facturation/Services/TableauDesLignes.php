<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Support\ResultatDePhp;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Illuminate\Support\Number;

/**
 * Le tableau des lignes d'un PDF de vente (points 3 et 11), au modèle M6-b
 * pour un professionnel et P1 pour un particulier (choix de l'éditeur du
 * 14/11/2026) : chaque ligne avec la référence de son produit, entière sur
 * une seule ligne -- « Service » en gris pour un service, qui n'en a pas
 * --, et son article, qui passe à la ligne si la place manque :
 *
 * - une facture, un avoir sur facture ou un devis à un professionnel, et
 *   toute vente sans TVA française, qui se paie hors taxe (voir
 *   ContexteDeVente::documentMontreLePuHt()), montrent, dans l'ordre, la
 *   quantité, le PU HT, la remise (« — » sans remise), le taux de TVA et le
 *   total HT ; le TTC n'apparaît qu'en bas, au récapitulatif par taux et au
 *   montant à régler (modèle M6-b). Le PU HT s'imprime comme partout, celui
 *   d'un article : le PU TTC moins la TVA d'un article arrondie au centime
 *   (voir CalculateurLigne::prixUnitaireHtAffiche(), décision de l'éditeur
 *   du 14/11/2026) : 8,32 € pour 9,99 € TTC à 20 %. Sous l'article, en petit,
 *   le PU HT exact, tel qu'il est enregistré avec ses six décimales, celui
 *   de la facture électronique (voir CalculateurLigne::prixUnitaireAuMillionieme()) ;
 * - un ticket, un avoir sur ticket, une facture, un avoir ou un devis à un
 *   particulier, qui paie le prix affiché TTC, montrent la quantité, le PU
 *   TTC, la remise, le taux de TVA et le total TTC, sans PU HT (modèle P1).
 *
 * La base, la TVA et le TTC de chaque taux sont dans le récapitulatif qui
 * suit (voir documents/pdf/totaux). Le PU TTC s'imprime au centime (voir
 * CalculateurLigne::prixUnitaireAffiche()). Les totaux se calculent depuis
 * les prix TTC, ce qu'explique la note de calcul imprimée sous les lignes
 * (voir MentionsFiscales::calcul()). Un avoir indique la facture de chaque
 * ligne, son numéro entier sur une seule ligne. Rien ne se recalcule :
 * chaque montant est celui de la ligne enregistrée.
 *
 * Le tableau tient toujours dans la largeur utile de la page (voir
 * MiseEnPageA4::LARGEUR_UTILE), pour qu'aucune colonne obligatoire ne
 * sorte de la page. Ses textes sont mesurés avec la police du PDF :
 * chaque colonne qui ne passe jamais à la ligne (le n° de la facture
 * d'origine, la quantité, les prix, la remise, le taux et le total) a la
 * largeur de son plus long texte ; l'article garde au moins
 * LARGEUR_MINIMALE_DE_LA_DESIGNATION ; la référence a la largeur de la plus
 * longue quand elle tient dans ce qui reste. Sinon, chaque référence trop
 * longue pour sa colonne s'imprime, entière sur une ligne, dans un corps
 * réduit juste assez pour y tenir ; les autres gardent le corps du
 * tableau.
 */
final readonly class TableauDesLignes
{
    /**
     * Le corps du texte des lignes, en points (les 10 px du corps de la
     * page, voir documents/pdf/styles).
     */
    public const CORPS = 7.5;

    /**
     * Le corps des titres des colonnes, en points, en capitales grasses.
     */
    public const CORPS_DES_TITRES = 6.0;

    /**
     * La marge intérieure de chaque case, à gauche comme à droite, en
     * points.
     */
    public const MARGE_DE_CASE = 4.5;

    /**
     * La place que la désignation garde au moins, en points : une vingtaine
     * de caractères par ligne, avant que la référence ne soit réduite.
     */
    private const LARGEUR_MINIMALE_DE_LA_DESIGNATION = 100.0;

    /**
     * Ce que chaque colonne mesurée garde en plus de son plus long texte,
     * en points, contre l'arrondi des positions.
     */
    private const JEU = 1.0;

    /**
     * L'écart, en points, en deçà duquel deux largeurs calculées sont
     * égales : celui des additions de nombres à virgule.
     */
    private const ECART_DE_CALCUL = 0.001;

    /**
     * @param  list<array{titre: string, montant: bool, largeur: float}>  $colonnes  dans l'ordre, chacune avec sa largeur en points, marges comprises (leur somme est la largeur utile de la page)
     * @param  list<array{reference: string, service: bool, designation: string, puHtExact: string|null, factureDOrigine: string|null, montants: list<string>, corpsDeLaReference: float|null}>  $lignes  le texte de chaque case (montants : la quantité, le prix, la remise, le taux et le total), « Service » à la place de la référence d'un service, le PU HT exact écrit sous l'article d'un document qui montre le PU HT et, pour une référence réduite, son corps en points, arrondi au dixième inférieur comme dompdf l'écrit
     */
    public function __construct(
        public array $colonnes,
        public array $lignes,
    ) {}

    /**
     * Le tableau du document, mesuré avec les polices de dompdf.
     */
    public static function pour(Facture|Avoir|Devis $document): self
    {
        $calculateur = app(CalculateurLigne::class);
        $enTtc = ! ContexteDeVente::documentMontreLePuHt($document);
        $avecFactureDOrigine = $document instanceof Avoir;
        $pourcentage = fn (mixed $valeur): string => CalculateurLigne::tauxEnTexte(SaisieDecimale::enCentiemes(ValeurSimple::simple($valeur)));

        $titres = [
            ['titre' => 'Réf.', 'montant' => false],
            ['titre' => 'Article', 'montant' => false],
            ...($avecFactureDOrigine ? [['titre' => 'Facture d\'origine', 'montant' => false]] : []),
            ['titre' => 'Qté', 'montant' => true],
            ['titre' => $enTtc ? 'PU TTC' : 'PU HT', 'montant' => true],
            ['titre' => 'Rem.', 'montant' => true],
            ['titre' => 'TVA', 'montant' => true],
            ['titre' => $enTtc ? 'Total TTC' : 'Total HT', 'montant' => true],
        ];

        $lignes = array_values($document->lignes->map(fn (LigneFacture|LigneAvoir|LigneDevis $ligne): array => [
            'reference' => $ligne->nature === NatureLigne::Service ? 'Service' : ($ligne->reference ?? ''),
            'service' => $ligne->nature === NatureLigne::Service,
            'designation' => $ligne->description,
            'puHtExact' => $enTtc ? null : 'PU HT exact : '.$calculateur->prixUnitaireAuMillionieme($calculateur->enMillioniemes($ligne->prix_unitaire_ht)),
            'factureDOrigine' => $ligne instanceof LigneAvoir ? ($ligne->ligneFacture?->facture->numero ?? '—') : null,
            'montants' => [
                ResultatDePhp::texte(Number::format($ligne->quantite, locale: 'fr'), 'Le format de la quantité'),
                $enTtc ? $calculateur->prixUnitaireAffiche($calculateur->enMillioniemes($ligne->prix_unitaire_ttc)) : $calculateur->prixUnitaireHtAfficheEnEuros($calculateur->enCentiemes($ligne->prix_unitaire_ttc), $calculateur->enCentiemes($ligne->taux_tva_du_prix)),
                $calculateur->enCentiemes($ligne->remise_pourcentage) === 0 ? '—' : $pourcentage($ligne->remise_pourcentage),
                $pourcentage($ligne->taux_tva),
                $calculateur->enEuros($calculateur->enCentiemes($enTtc ? $ligne->montant_ttc : $ligne->montant_ht)),
            ],
        ])->all());

        return self::mesure($titres, $lignes, app(Dompdf::class)->getFontMetrics());
    }

    /**
     * La largeur de chaque colonne en pourcentage de la largeur utile, que
     * la vue écrit (dompdf ne lit qu'une largeur en pourcentage dans un
     * tableau à disposition fixe).
     *
     * @return list<float>
     */
    public function pourcentages(): array
    {
        return array_map(fn (array $colonne): float => round($colonne['largeur'] / MiseEnPageA4::LARGEUR_UTILE * 100, 4), $this->colonnes);
    }

    /**
     * Les largeurs des colonnes et le corps de chaque référence : chaque
     * colonne qui ne passe jamais à la ligne prend la largeur de son plus
     * long texte, titre compris ; la référence prend la sienne tant que la
     * désignation garde sa place minimale (ou, faute de place, la moitié
     * de ce qui reste), la désignation tout le reste.
     *
     * @param  list<array{titre: string, montant: bool}>  $titres
     * @param  list<array{reference: string, service: bool, designation: string, puHtExact: string|null, factureDOrigine: string|null, montants: list<string>}>  $lignes
     */
    private static function mesure(array $titres, array $lignes, FontMetrics $polices): self
    {
        $normale = ValeurRequise::de($polices->getFont('DejaVu Sans'), 'la police DejaVu Sans de Dompdf');
        $grasse = ValeurRequise::de($polices->getFont('DejaVu Sans', 'bold'), 'la police DejaVu Sans grasse de Dompdf');
        $largeurDuTexte = fn (string $texte): float => $polices->getTextWidth($texte, $normale, self::CORPS);
        $largeurDeColonne = fn (int $rang, array $textes): float => max([
            $polices->getTextWidth(mb_convert_case($titres[$rang]['titre'], MB_CASE_UPPER, 'UTF-8'), $grasse, self::CORPS_DES_TITRES),
            ...array_map($largeurDuTexte, ValeursLues::textes($textes)),
        ]) + 2 * self::MARGE_DE_CASE + self::JEU;

        $largeurs = [];
        foreach (array_slice(array_keys($titres), 2) as $rang) {
            $largeurs[$rang] = $largeurDeColonne($rang, array_map(fn (array $ligne): string => self::casesQuiNePassentPasALaLigne($ligne)[$rang - 2], $lignes));
        }

        $espace = MiseEnPageA4::LARGEUR_UTILE - array_sum($largeurs);
        $largeurs[0] = min(
            $largeurDeColonne(0, array_column($lignes, 'reference')),
            max($espace - self::LARGEUR_MINIMALE_DE_LA_DESIGNATION, $espace / 2),
        );
        $largeurs[1] = $espace - $largeurs[0];
        ksort($largeurs);
        $placeDUneReference = $largeurs[0] - 2 * self::MARGE_DE_CASE - self::JEU;

        return new self(
            array_map(fn (array $titre, float $largeur): array => [...$titre, 'largeur' => $largeur], $titres, $largeurs),
            array_map(function (array $ligne) use ($largeurDuTexte, $placeDUneReference): array {
                $largeurDeLaReference = $largeurDuTexte($ligne['reference']);

                // La place d'une référence qui donne sa largeur à la colonne
                // est cette largeur, retrouvée après une addition et une
                // soustraction de nombres à virgule : un millième de point
                // d'écart n'est pas un manque de place.
                return [...$ligne, 'corpsDeLaReference' => $largeurDeLaReference > $placeDUneReference + self::ECART_DE_CALCUL
                    ? floor(self::CORPS * $placeDUneReference / $largeurDeLaReference * 10) / 10
                    : null];
            }, $lignes),
        );
    }

    /**
     * Les cases d'une ligne qui ne passent jamais à la ligne, dans l'ordre
     * des colonnes à partir de la troisième : le n° de la facture
     * d'origine d'un avoir, puis les montants.
     *
     * @param  array{factureDOrigine: string|null, montants: list<string>}  $ligne
     * @return list<string>
     */
    private static function casesQuiNePassentPasALaLigne(array $ligne): array
    {
        return [...($ligne['factureDOrigine'] === null ? [] : [$ligne['factureDOrigine']]), ...$ligne['montants']];
    }
}
