<?php

namespace App\Domain\Facturation\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\ReferenceDeTva;
use InvalidArgumentException;

/**
 * La table datée des mentions de la chaîne TVA (décisions de Claude T40.1
 * et T40.2 du 22/11/2026, D76) : pour chaque texte qui cite un article de
 * la loi (voir ReferenceDeTva) et pour un jour, le texte en vigueur ce
 * jour-là. Seule source de ces textes : aucun autre code n'écrit un article
 * du code de la TVA dans une mention, un écran, un refus ou une
 * notification.
 *
 * Les textes d'origine citent le code général des impôts (CGI). La TVA
 * passe au code des impositions sur les biens et services (CIBS) le
 * 1er janvier 2027 (ordonnance n° 2025-1247 du 17/12/2025, art. 49, modifié
 * par l'ordonnance n° 2026-671 du 27/07/2026, art. 17 ; BOI-RES-TVA-000253,
 * § 1.3), jour où les articles cités du CGI sont abrogés : la version de ce
 * jour cite les articles du CIBS, chacun lu dans sa concordance officielle
 * et dans son texte en vigueur au 01/01/2027 (CONFIRMÉ). Un article dont le
 * numéro du CIBS serait mis en doute n'entre pas dans une version : il garde
 * son texte précédent, cité au CGI au plus tard pour un document émis le
 * DERNIER_JOUR_DES_ARTICLES_DU_CGI (art. 46, II de l'ordonnance n° 2025-1247
 * modifié ; BOI-RES-TVA-000253, § 3.1), jamais au-delà. La mention de
 * l'autoliquidation ne cite aucun article et ne change pas ; l'article
 * 286, I, 3° bis du CGI (caisse sécurisée), maintenu en vigueur, n'est pas
 * dans cette table (voir ModuleFiscal::REFERENCE_LEGALE).
 *
 * Le jour est celui du calendrier du magasin du document : sa date
 * d'émission pour un document émis, aujourd'hui pour un brouillon, le jour
 * de son dernier envoi pour un devis envoyé (voir
 * MentionsFiscales::jourDesMentions()), le jour de la clôture pour
 * l'aperçu de l'e-reporting ; pour un écran, aujourd'hui, au calendrier du
 * magasin dans son panneau, de Paris ailleurs ; une notification se fige à
 * son envoi. Le jour de bascule se franchit ainsi au minuit de chaque
 * territoire : d'abord à La Réunion, le 31/12/2026 à 21 h à Paris, puis en
 * métropole et en Corse, puis aux Antilles, le 01/01/2027 à 5 h à Paris.
 *
 * On n'y fait qu'ajouter : une version, sous son premier jour, ne nomme que
 * les textes qui changent ce jour-là, les autres gardant celui de la
 * version précédente, et le texte d'une version ne se corrige plus une fois
 * son premier jour atteint : une correction est une nouvelle version, datée
 * du jour où elle prend effet. Un document émis ne change donc jamais : il
 * imprime pour toujours les mentions qu'il a signées à son émission (voir
 * MentionsFiscales::imprimees()), et aucun avoir, aucune facture
 * rectificative ni aucun nouvel envoi n'est fait à cause d'une nouvelle
 * version.
 */
final class TableDesMentionsDeTva
{
    /**
     * Le premier jour du code des impositions sur les biens et services.
     */
    public const PREMIER_JOUR_DU_CIBS = '2027-01-01';

    /**
     * Le dernier jour d'émission d'un document qui peut encore citer un
     * article abrogé du CGI (art. 46, II de l'ordonnance n° 2025-1247,
     * modifié par l'ordonnance n° 2026-671) : le filet de T40.2. Dès le
     * lendemain, aucun texte de la table ne cite un article abrogé du CGI.
     */
    public const DERNIER_JOUR_DES_ARTICLES_DU_CGI = '2028-06-30';

    /**
     * Les textes d'origine, ceux du code général des impôts : un texte pour
     * chaque référence.
     *
     * @var array<value-of<ReferenceDeTva>, non-empty-string>
     */
    private const TEXTES_D_ORIGINE = [
        ReferenceDeTva::MentionDeLExportHorsUe->value => 'Exonération de TVA, article 262 I du CGI',
        ReferenceDeTva::MentionDeLaLivraisonVersUnDom->value => 'Exonération de TVA en application de l\'article 294 du Code général des impôts',
        ReferenceDeTva::MentionDeLaLivraisonIntracommunautaire->value => 'Exonération de TVA, article 262 ter I du CGI',
        ReferenceDeTva::MentionDeLAutoliquidation->value => 'Autoliquidation',
        ReferenceDeTva::MentionDeLaPrestationHorsUe->value => 'TVA non applicable – article 259-1° du CGI',
        ReferenceDeTva::MentionDeLaFranchise->value => 'TVA non applicable, art. 293 B du CGI',
        ReferenceDeTva::ArticleDeLaFranchise->value => 'art. 293 B du CGI',
        ReferenceDeTva::ArticleDeLAssujettiUnique->value => 'art. 256 C du CGI',
        ReferenceDeTva::ArticlesDuLieuFixeParLaLoi->value => 'art. 259 A du CGI',
        ReferenceDeTva::ArticleDeLaLocationDeLogements->value => 'art. 261 D du CGI',
        ReferenceDeTva::ArticlesDesVentesADistance->value => 'art. 259 D du CGI',
        ReferenceDeTva::TransmissionDUneActiviteEconomique->value => 'transmission d\'universalité, art. 257 bis du CGI',
    ];

    /**
     * Les versions datées, de la plus ancienne à la plus récente, chacune
     * sous son premier jour (« AAAA-MM-JJ ») avec les seuls textes qui
     * changent ce jour-là.
     *
     * Le 01/01/2027, les articles du CIBS (table des mentions du
     * 22/11/2026, articles CONFIRMÉS) : la franchise en base, L. 233-3
     * (293 B, I, al. 1) ; l'export, L. 213-12, 2° et L. 213-14 (262 I) ; la
     * livraison vers un DOM, L. 211-7 (294), avec l'exonération de
     * l'exportation (BOI-TVA-GEO-20-40, § 60 et § 69) ; la livraison
     * intracommunautaire, L. 213-12, 1° et L. 213-13 (262 ter I) ; la
     * prestation hors de l'Union, L. 211-92 (259, 1°) ; l'assujetti unique,
     * L. 234-2 (256 C, I) ; le lieu fixé par la loi, L. 211-75, L. 211-81,
     * L. 211-85, L. 211-87 et L. 211-89 (259 A) ; la location de logements,
     * L. 231-21 (261 D) ; les ventes à distance, L. 232-16 et L. 232-18
     * (259 D) ; la transmission d'une activité économique autonome,
     * L. 211-107 et L. 211-108 (257 bis).
     *
     * @var array<non-empty-string, array<value-of<ReferenceDeTva>, non-empty-string>>
     */
    private const VERSIONS = [
        self::PREMIER_JOUR_DU_CIBS => [
            ReferenceDeTva::MentionDeLExportHorsUe->value => 'Exonération de TVA, articles L. 213-12, 2° et L. 213-14 du CIBS',
            ReferenceDeTva::MentionDeLaLivraisonVersUnDom->value => 'Exonération de TVA, articles L. 211-7, L. 213-12, 2° et L. 213-14 du CIBS',
            ReferenceDeTva::MentionDeLaLivraisonIntracommunautaire->value => 'Exonération de TVA, articles L. 213-12, 1° et L. 213-13 du CIBS',
            ReferenceDeTva::MentionDeLaPrestationHorsUe->value => 'TVA non applicable – article L. 211-92 du CIBS',
            ReferenceDeTva::MentionDeLaFranchise->value => 'TVA non applicable, art. L. 233-3 du CIBS',
            ReferenceDeTva::ArticleDeLaFranchise->value => 'art. L. 233-3 du CIBS',
            ReferenceDeTva::ArticleDeLAssujettiUnique->value => 'art. L. 234-2 du CIBS',
            ReferenceDeTva::ArticlesDuLieuFixeParLaLoi->value => 'art. L. 211-75, L. 211-81, L. 211-85, L. 211-87 et L. 211-89 du CIBS',
            ReferenceDeTva::ArticleDeLaLocationDeLogements->value => 'art. L. 231-21 du CIBS',
            ReferenceDeTva::ArticlesDesVentesADistance->value => 'art. L. 232-16 et L. 232-18 du CIBS',
            ReferenceDeTva::TransmissionDUneActiviteEconomique->value => 'transmission d\'une activité économique autonome, art. L. 211-107 et L. 211-108 du CIBS',
        ],
    ];

    /**
     * Le texte de cette référence en vigueur ce jour (« AAAA-MM-JJ ») : celui
     * de la dernière version dont le premier jour est atteint qui le nomme,
     * sinon le texte d'origine.
     *
     * @throws InvalidArgumentException pour un jour qui n'est pas « AAAA-MM-JJ »
     */
    public static function texte(ReferenceDeTva $reference, string $jour): string
    {
        return self::texteDans(self::TEXTES_D_ORIGINE, self::VERSIONS, $reference, $jour);
    }

    /**
     * La mention qu'imprime une vente de cette nature émise ce jour : aucune
     * pour une vente en France.
     *
     * @throws InvalidArgumentException pour un jour qui n'est pas « AAAA-MM-JJ »
     */
    public static function mentionDeLaNature(NatureOperation $nature, string $jour): ?string
    {
        $reference = ReferenceDeTva::deLaNature($nature);

        return $reference === null ? null : self::texte($reference, $jour);
    }

    /**
     * La mention qu'imprime une vente en franchise en base émise ce jour.
     *
     * @throws InvalidArgumentException pour un jour qui n'est pas « AAAA-MM-JJ »
     */
    public static function mentionDeLaFranchise(string $jour): string
    {
        return self::texte(ReferenceDeTva::MentionDeLaFranchise, $jour);
    }

    /**
     * Le motif de la franchise en base dans l'aperçu de l'e-reporting de la
     * journée de ce jour (voir EReportingDesParticuliers).
     *
     * @throws InvalidArgumentException pour un jour qui n'est pas « AAAA-MM-JJ »
     */
    public static function motifDeLaFranchise(string $jour): string
    {
        return 'Franchise en base de TVA ('.self::texte(ReferenceDeTva::ArticleDeLaFranchise, $jour).')';
    }

    /**
     * Tous les textes que cette référence a eus, d'origine puis dans chaque
     * version, sans doublon : ceux qu'un document émis a pu signer (voir
     * MentionsFiscales::mentionDeLaNature()).
     *
     * @return list<string>
     */
    public static function textesDe(ReferenceDeTva $reference): array
    {
        $textes = [self::TEXTES_D_ORIGINE[$reference->value]];

        foreach (self::VERSIONS as $textesDeLaVersion) {
            if (array_key_exists($reference->value, $textesDeLaVersion)) {
                $textes[] = $textesDeLaVersion[$reference->value];
            }
        }

        return array_values(array_unique($textes));
    }

    /**
     * Le premier jour de chaque version datée, dans leur ordre : les jours
     * où des textes changent, de part et d'autre desquels le semis pose les
     * documents de chaque mention (voir DocumentsDesCas).
     *
     * @return list<string>
     */
    public static function premiersJoursDesVersions(): array
    {
        return array_keys(self::VERSIONS);
    }

    /**
     * Le texte de cette référence ce jour dans une table donnée par ses
     * textes d'origine et ses versions : celle de PILIQI (voir texte()), ou
     * une autre pour contrôler la règle de la table, qu'une version ne
     * change que les textes qu'elle nomme.
     *
     * @param  array<string, string>  $textesDOrigine  un texte pour chaque référence
     * @param  array<string, array<string, string>>  $versions  par premier jour, de la plus ancienne à la plus récente, les textes qui changent
     *
     * @throws InvalidArgumentException pour un jour qui n'est pas « AAAA-MM-JJ », ou une référence sans texte d'origine
     */
    public static function texteDans(array $textesDOrigine, array $versions, ReferenceDeTva $reference, string $jour): string
    {
        $jourValide = Calendrier::jourValide($jour) ?? throw new InvalidArgumentException("{$jour} n'est pas un jour « AAAA-MM-JJ » : la table des mentions se lit au jour du document.");
        $texte = $textesDOrigine[$reference->value] ?? throw new InvalidArgumentException("La référence {$reference->value} n'a pas de texte d'origine dans la table des mentions.");

        foreach ($versions as $premierJour => $textes) {
            if (strcmp((string) $premierJour, $jourValide) > 0) {
                break;
            }

            $texte = $textes[$reference->value] ?? $texte;
        }

        return $texte;
    }
}
