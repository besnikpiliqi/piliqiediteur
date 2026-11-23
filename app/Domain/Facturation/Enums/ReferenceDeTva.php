<?php

namespace App\Domain\Facturation\Enums;

/**
 * Chaque texte de la chaîne TVA qui cite un article de la loi, et que la
 * table datée des mentions donne pour un jour (voir TableDesMentionsDeTva) :
 * les mentions qu'imprime une vente exonérée ou placée hors du champ de la
 * TVA française, celle d'une vente en franchise en base, et les articles
 * que citent les écrans, les refus et les notifications. Aucun autre code
 * n'écrit un article du code de la TVA dans un texte montré ou imprimé.
 */
enum ReferenceDeTva: string
{
    /**
     * La mention d'un export hors de l'Union expédié par le magasin.
     */
    case MentionDeLExportHorsUe = 'mention_export_hors_ue';

    /**
     * La mention d'une livraison de biens vers un département d'outre-mer.
     */
    case MentionDeLaLivraisonVersUnDom = 'mention_livraison_dom';

    /**
     * La mention d'une livraison intracommunautaire à un professionnel.
     */
    case MentionDeLaLivraisonIntracommunautaire = 'mention_livraison_intracommunautaire';

    /**
     * La mention d'une prestation à un professionnel d'un autre État membre,
     * qui autoliquide la TVA de son pays : « Autoliquidation », sans article
     * (art. 242 nonies A, I, 13° de l'annexe II au CGI).
     */
    case MentionDeLAutoliquidation = 'mention_autoliquidation';

    /**
     * La mention d'une prestation à un professionnel établi hors de
     * l'Union, hors du champ de la TVA française.
     */
    case MentionDeLaPrestationHorsUe = 'mention_prestation_hors_ue';

    /**
     * La mention qu'imprime toute vente en franchise en base.
     */
    case MentionDeLaFranchise = 'mention_franchise';

    /**
     * L'article de la franchise en base, tel que les aides et l'aperçu de
     * l'e-reporting le citent (« art. 293 B du CGI »).
     */
    case ArticleDeLaFranchise = 'article_franchise';

    /**
     * L'article de l'assujetti unique (groupe TVA), que citent la case des
     * paramètres fiscaux de la société et la page d'inscription.
     */
    case ArticleDeLAssujettiUnique = 'article_assujetti_unique';

    /**
     * Les articles des services dont le lieu est fixé par la loi (immeuble,
     * transport de personnes, location courte d'un moyen de transport,
     * restauration, accès à une manifestation), que citent le refus d'une
     * prestation hors de France et l'aide de la fiche d'un service.
     */
    case ArticlesDuLieuFixeParLaLoi = 'articles_lieu_fixe_par_la_loi';

    /**
     * L'article de la location de logements exonérée, que cite
     * l'avertissement du code d'activité.
     */
    case ArticleDeLaLocationDeLogements = 'article_location_de_logements';

    /**
     * Les articles des ventes à distance au-delà de 10 000 € par an, que
     * cite l'avertissement du code d'activité.
     */
    case ArticlesDesVentesADistance = 'articles_ventes_a_distance';

    /**
     * La cession d'un magasin entier, qui n'est pas une vente soumise à la
     * TVA, telle que l'avis de la cession la nomme, avec ses articles.
     */
    case TransmissionDUneActiviteEconomique = 'transmission_d_une_activite_economique';

    /**
     * La mention qu'imprime une vente de cette nature : aucune pour une
     * vente en France.
     */
    public static function deLaNature(NatureOperation $nature): ?self
    {
        return match ($nature) {
            NatureOperation::VenteEnFrance => null,
            NatureOperation::ExportHorsUe => self::MentionDeLExportHorsUe,
            NatureOperation::LivraisonDom => self::MentionDeLaLivraisonVersUnDom,
            NatureOperation::LivraisonIntracommunautaire => self::MentionDeLaLivraisonIntracommunautaire,
            NatureOperation::PrestationUeAutoliquidation => self::MentionDeLAutoliquidation,
            NatureOperation::PrestationHorsUe => self::MentionDeLaPrestationHorsUe,
        };
    }
}
