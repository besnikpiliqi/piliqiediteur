<?php

namespace App\Domain\Facturation\Services;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Facturation\Enums\CategorieOperation;
use App\Domain\Facturation\Enums\MotifDeRegularisation;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\ReferenceDeTva;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Support\TableDesMentionsDeTva;
use App\Domain\Tenancy\Support\ValeurRequise;

/**
 * Les mentions fiscales qu'imprime un document, dans l'ordre, que les PDF
 * reprennent telles quelles dans le pied de chaque page (voir PiedDePage)
 * et que l'émission signe au registre fiscal (voir ContenuFiscal) :
 *
 * - sur un ticket, une facture ou un avoir, la catégorie de l'opération
 *   (art. 242 nonies A de l'annexe II au CGI), tirée de la nature de ses
 *   lignes (voir NatureLigne) ;
 * - hors de la vente en France, le texte qui exonère la vente ou la place
 *   hors du champ de la TVA française (« Exonération de TVA, article 262
 *   I du CGI »..., voir TableDesMentionsDeTva::mentionDeLaNature()), selon
 *   la nature lue sur le document : un avoir reprend la nature des
 *   factures qu'il corrige, un devis celle qu'il propose ;
 * - la mention de la franchise (« TVA non applicable, art. 293 B du CGI »,
 *   voir TableDesMentionsDeTva::mentionDeLaFranchise()) quand le document a
 *   été enregistré en franchise en base de TVA (B10) : lue sur le
 *   document, jamais sur le régime actuel de la société, pour qu'un
 *   document réimprimé après la sortie de la franchise la porte toujours ;
 *   un devis la porte aussi, ses prix s'entendant sans TVA ;
 * - hors franchise, sur un ticket, une facture ou un avoir, l'option de
 *   son émetteur pour le paiement de la taxe d'après les débits (voir
 *   ParametresFiscauxSociete) : sans TVA due, elle n'a pas d'objet. Un avoir
 *   rectifie une facture : il en reprend les mentions. L'émetteur d'un
 *   document émis est celui qu'il a signé (voir FigeSonEmetteur) :
 *   réimprimé après un changement de l'option, il garde la mention de
 *   son émission.
 *
 * Le texte d'une mention qui cite un article de la loi est celui de la
 * table datée des mentions au jour du document (voir jourDesMentions() et
 * TableDesMentionsDeTva) : un avoir ou une facture rectificative suit le
 * sien, jamais celui du document qu'il corrige. Un ticket, une facture ou
 * un avoir émis imprime pour toujours les mentions qu'il a signées à son
 * émission, et sa fiche les montre (voir imprimees(), mentionDeLaNature()
 * et motifDuTauxZero()) : son PDF, téléchargé, réimprimé ou joint à un
 * e-mail des années plus tard, cite les articles de son émission.
 *
 * Une facture dit d'abord la date de la livraison ou de la prestation
 * (T49, art. 242 nonies A de l'annexe II au CGI) quand elle n'est pas le
 * jour de son émission (« Date de la livraison ou de la prestation :
 * 12/10/2026 », ou « … : du 03/11/2026 au 07/11/2026 » pour une
 * prestation de plusieurs jours, voir NoteSaNatureDOperation), et un devis
 * la date prévue qu'il note (« Date prévue de la livraison ou de la
 * prestation : … »). Une facture rectificative, après la correction du
 * SIREN de la société ou une exonération non prouvée, dit d'abord quelle
 * facture elle rectifie (« Facture rectificative de la facture n°
 * FAC-LYON-2026-000001 du 15/10/2026 », « Ticket rectificatif du ticket n°
 * … » pour un ticket, voir RegularisationDUneFacture et
 * RegularisationDUneExoneration), puis, toujours, la date de l'opération
 * qu'elle reprend, celle de la facture rectifiée, ou à défaut le jour de
 * son émission (T27.2 : « Date de la livraison ou de la prestation
 * d'origine : 15/10/2026 »). S'y ajoute,
 * sous les lignes de tout document hors franchise en base, l'explication
 * de son calcul (voir calcul()), qui ne se signe pas ; sur l'avoir d'un
 * client comptoir, la mention de son bon d'avoir (voir bonDAvoir()) ; sur
 * un avoir de régularisation, celle de la régularisation, avec le SIREN
 * correct ou la preuve de sortie non reçue (voir regularisation()). Le
 * motif d'un taux de 0 % se répète aussi dans le tableau de la TVA, à côté
 * de ce taux (voir motifDuTauxZero()).
 */
class MentionsFiscales
{
    private const OPTION_DEBITS = 'Option pour le paiement de la taxe d\'après les débits';

    public const RECTIFICATIVE = 'Facture rectificative de la facture n° %s du %s';

    public const TICKET_RECTIFICATIF = 'Ticket rectificatif du ticket n° %s du %s';

    public const DATE_DE_L_OPERATION = 'Date de la livraison ou de la prestation : %s';

    public const DATE_DE_L_OPERATION_D_ORIGINE = 'Date de la livraison ou de la prestation d\'origine : %s';

    public const DATE_PREVUE_DE_L_OPERATION = 'Date prévue de la livraison ou de la prestation : %s';

    public const REGULARISATION = 'Avoir de régularisation émis après correction de l\'identité du vendeur (SIREN correct : %s)';

    public const REGULARISATION_DE_L_EXONERATION = 'Avoir de régularisation : preuve de sortie non reçue pour %s n° %s';

    private const CALCUL_VENTE = 'Montants calculés depuis les prix TTC : pour chaque ligne, la TVA est le TTC × taux ÷ (1 + taux), arrondie une fois au centime, et le HT est le TTC moins cette TVA ; un centime peut être ajusté sur une ligne pour que la TVA de chaque taux soit celle de son TTC total, calculée de même.';

    private const CALCUL_RETOUR = 'Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur leur TTC (TVA = TTC × taux ÷ (1 + taux), arrondie une fois au centime ; HT = TTC moins cette TVA), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime ; un centime peut être ajusté sur une ligne rendue en partie pour rapprocher la TVA de chaque taux de celle de son TTC total, calculée de même.';

    private const CALCUL_SANS_TVA = 'Vente sans TVA française : chaque produit, et chaque service à son prix de référence, se vend à son prix catalogue TTC moins la TVA française de son taux (pour chaque ligne, cette TVA est le TTC × taux ÷ (1 + taux), arrondie une fois au centime ; un centime peut être ajusté sur une ligne pour que, pour chaque taux, elle soit celle du TTC total du taux, calculée de même) ; un autre service, à son prix sans TVA.';

    private const CALCUL_RETOUR_SANS_TVA = 'Vente sans TVA française : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité, sans dépasser le HT de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime.';

    private const PU_HT_D_UN_ARTICLE = 'Le PU HT affiché est celui d\'un article : prix TTC moins sa TVA arrondie au centime (9,99 € TTC à 20 % : 8,32 € HT). La quantité × ce prix peut différer de quelques centimes du total HT de la ligne, calculé sur toute la quantité.';

    private const PU_HT_EXACT = 'Le PU HT exact, écrit sous chaque article, est celui de la facture électronique : la quantité × ce prix retombe sur le total HT de la ligne à quelques centimes près.';

    /**
     * Les mentions du document, calculées depuis ses colonnes, son émetteur
     * et la table datée des mentions au jour du document (voir
     * jourDesMentions()) : celles que l'émission signe (voir ContenuFiscal),
     * et celles qu'imprime un brouillon ou un devis (voir imprimees()).
     *
     * @param  Emetteur  $emetteur  celui qu'imprime le document : signé une fois émis (voir FigeSonEmetteur::emetteur()), figé au dernier envoi d'un devis (voir Devis::emetteur()), la société et le magasin d'aujourd'hui pour un brouillon et pour le document qu'on émet (voir ContenuFiscal)
     * @return list<string>
     */
    public function pour(Facture|Avoir|Devis $document, Emetteur $emetteur): array
    {
        $jour = $this->jourDesMentions($document);
        $mentions = [];

        if ($document instanceof Facture && $document->estUneRectificative()) {
            $rectifiee = $document->factureRectifiee()->firstOrFail();
            $mentions[] = sprintf($rectifiee->serie() === TypeDocument::TicketCaisse ? self::TICKET_RECTIFICATIF : self::RECTIFICATIVE, $rectifiee->numero, $rectifiee->magasin->calendrier()->afficher(ValeurRequise::de($rectifiee->date_emission, 'la date d\'émission de la facture rectifiée'), 'd/m/Y'));

            if (($date = $document->dateDeLOperation()) !== null) {
                $mentions[] = sprintf(self::DATE_DE_L_OPERATION_D_ORIGINE, $date);
            }
        } elseif ($document instanceof Facture && $document->date_operation_fin !== null && $document->dateDeLOperationDiffereDu($jour)) {
            $mentions[] = sprintf(self::DATE_DE_L_OPERATION, $document->dateDeLOperation());
        } elseif ($document instanceof Devis && ($date = $document->dateDeLOperation()) !== null) {
            $mentions[] = sprintf(self::DATE_PREVUE_DE_L_OPERATION, $date);
        }

        if (! $document instanceof Devis) {
            $mentions[] = 'Catégorie de l\'opération : '.CategorieOperation::depuisNatures($document->lignes->map(fn (LigneFacture|LigneAvoir $ligne): NatureLigne => $ligne->nature))->getLabel();
        }

        $mentionDeLaNature = TableDesMentionsDeTva::mentionDeLaNature($document->nature_operation, $jour);

        if ($mentionDeLaNature !== null) {
            $mentions[] = $mentionDeLaNature;
        }

        if ($document->franchise_en_base) {
            $mentions[] = TableDesMentionsDeTva::mentionDeLaFranchise($jour);
        } elseif (! $document instanceof Devis && $emetteur->optionDebitsTva) {
            $mentions[] = self::OPTION_DEBITS;
        }

        return $mentions;
    }

    /**
     * Les mentions qu'imprime le document : celles qu'un ticket, une
     * facture ou un avoir émis a signées à son émission, telles quelles
     * pour toujours (voir FigeSonEmetteur::mentionsSignees()), même quand
     * la table datée des mentions a reçu depuis une nouvelle version ; pour
     * un brouillon ou un devis, celles de son jour (voir pour()).
     *
     * @param  Emetteur  $emetteur  celui qu'imprime le document (voir pour())
     * @return list<string>
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function imprimees(Facture|Avoir|Devis $document, Emetteur $emetteur): array
    {
        return self::estEmis($document) ? $document->mentionsSignees() : $this->pour($document, $emetteur);
    }

    /**
     * Le jour dont la table datée des mentions donne les textes du document,
     * au calendrier de son magasin : sa date d'émission pour un ticket, une
     * facture ou un avoir, ce qui est aussi le jour qu'il signe à son
     * émission ; le jour de son dernier envoi pour un devis envoyé (voir
     * EmettreDevisAction), qui se relit ainsi tel qu'il est parti, même
     * consulté ou réimprimé plus tard ; aujourd'hui pour un brouillon, qui
     * s'imprime comme il s'émettrait, et pour un devis modifié depuis son
     * envoi, qui repartira à son prochain envoi.
     */
    public function jourDesMentions(Facture|Avoir|Devis $document): string
    {
        $calendrier = $document->magasin->calendrier();
        $emission = $document instanceof Devis && $document->emetteur_fige === null ? null : $document->date_emission;

        return $emission === null ? $calendrier->aujourdhui() : $calendrier->jourDe($emission);
    }

    /**
     * La mention qu'imprime la nature d'opération du document (voir
     * TableDesMentionsDeTva::mentionDeLaNature()), que sa fiche montre sous
     * la nature : celle qu'un ticket, une facture ou un avoir émis a
     * signée, celle de son jour pour un brouillon ou un devis ; aucune pour
     * une vente en France.
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function mentionDeLaNature(Facture|Avoir|Devis $document): ?string
    {
        $reference = ReferenceDeTva::deLaNature($document->nature_operation);

        if ($reference === null) {
            return null;
        }

        return self::estEmis($document)
            ? self::signeeParmi($document, TableDesMentionsDeTva::textesDe($reference))
            : TableDesMentionsDeTva::texte($reference, $this->jourDesMentions($document));
    }

    /**
     * Le motif qu'imprime, à côté de son taux de 0 %, le tableau de la TVA
     * d'un document (point 11) : le texte qui exonère la vente ou la place
     * hors du champ de la TVA française, sinon la franchise en base, lus
     * comme ses mentions (voir imprimees()) : signés pour un document émis,
     * du jour du document sinon ; aucun pour une vente en France, où un taux
     * de 0 % n'a pas de texte à citer.
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    public function motifDuTauxZero(Facture|Avoir|Devis $document): ?string
    {
        $mentionDeLaNature = $this->mentionDeLaNature($document);

        if ($mentionDeLaNature !== null || ! $document->franchise_en_base) {
            return $mentionDeLaNature;
        }

        return self::estEmis($document)
            ? self::signeeParmi($document, TableDesMentionsDeTva::textesDe(ReferenceDeTva::MentionDeLaFranchise))
            : TableDesMentionsDeTva::mentionDeLaFranchise($this->jourDesMentions($document));
    }

    /**
     * Le document a-t-il signé ses mentions : un ticket, une facture ou un
     * avoir numéroté ? Un devis ne se signe jamais au registre fiscal.
     *
     * @phpstan-assert-if-true Facture|Avoir $document
     */
    private static function estEmis(Facture|Avoir|Devis $document): bool
    {
        return ! $document instanceof Devis && $document->numero !== null;
    }

    /**
     * La première des mentions que le document émis a signées qui est l'un
     * de ces textes, tous ceux qu'une référence de la table a eus : ce que
     * le document imprime pour cette référence depuis son émission, ou rien
     * s'il ne l'a pas signée.
     *
     * @param  list<string>  $textes
     *
     * @throws DocumentSansEnregistrementSigneException
     */
    private static function signeeParmi(Facture|Avoir $document, array $textes): ?string
    {
        foreach ($document->mentionsSignees() as $mention) {
            if (in_array($mention, $textes, true)) {
                return $mention;
            }
        }

        return null;
    }

    /**
     * La mention que l'avoir d'un client comptoir imprime quand il lui
     * reste du crédit après son émission, une fois imputé sur les tickets
     * qu'il corrige : son numéro vaut bon d'avoir (voir
     * EmettreFactureAction). Calculée et signée à l'émission (voir
     * ContenuFiscal), elle se relit ensuite dans le contenu signé (voir
     * Avoir::mentionDuBonDAvoir()) : le montant du bon reste celui de son
     * émission, même une fois utilisé. Null pour tout autre avoir.
     */
    public function bonDAvoir(Avoir $avoir): ?string
    {
        $credit = $avoir->creditEnCentimes();

        if (! $avoir->client?->estComptoir() || $credit <= 0) {
            return null;
        }

        return 'Ce n° vaut bon d\'avoir de '.app(CalculateurLigne::class)->enEuros($credit).' à présenter lors d\'un prochain achat.';
    }

    /**
     * La mention qu'imprime l'avoir de régularisation d'une facture : après
     * la correction du SIREN de la société, ce SIREN correct, celui de la
     * société à l'émission de l'avoir (voir RegularisationDUneFacture) ;
     * pour une exonération non prouvée, la preuve de sortie non reçue pour
     * la facture ou le ticket qu'il régularise (voir
     * RegularisationDUneExoneration). Calculée et signée à l'émission (voir
     * ContenuFiscal), elle se relit ensuite dans le contenu signé (voir
     * Avoir::mentionDeRegularisation()). Null pour tout autre avoir.
     */
    public function regularisation(Avoir $avoir): ?string
    {
        return match ($avoir->motif_regularisation) {
            null => null,
            MotifDeRegularisation::SirenCorrige => sprintf(self::REGULARISATION, $avoir->magasin->societe->siren),
            MotifDeRegularisation::ExonerationNonProuvee => sprintf(
                self::REGULARISATION_DE_L_EXONERATION,
                ($facture = $avoir->factureRegularisee())->serie() === TypeDocument::TicketCaisse ? 'le ticket' : 'la facture',
                $facture->numero,
            ),
        };
    }

    /**
     * L'explication du calcul d'un document, que les PDF impriment sous ses
     * lignes et que sa fiche affiche (décision de l'éditeur du 14/11/2026,
     * modèles M6-b et P1) : toute vente soumise à la TVA française se
     * calcule ligne par ligne depuis ses prix TTC, la TVA de chaque ligne
     * arrondie une fois, son HT le TTC moins cette TVA, un centime pouvant
     * être ajusté sur une ligne pour que la TVA de chaque taux soit celle de
     * son TTC total, calculée de même (voir
     * CalculateurLigne::chiffrerUneVente()) ; un avoir rend le calcul de la
     * vente sur les articles rendus, dans la limite de la ligne, et un
     * centime ajusté sur une ligne rendue en partie rapproche la TVA de
     * chaque taux de celle de son TTC total (voir
     * CalculateurLigne::chiffrerUnAvoir()). Une vente sans TVA française
     * (K3, voir ContexteDeVente::documentRetireLaTvaDuPrix()) dit que ses
     * produits, et ses services à leur prix de référence, se comptent au
     * prix de leur catalogue moins la TVA de leur taux, et ses autres
     * services à leur prix sans TVA. Aucune n'écrit que la TVA d'un taux
     * égale sa base HT x le taux : elle s'en écarte d'un demi-centime x (1 +
     * taux) au plus dans une vente (9,99 € TTC à 20 % : 1,67 € de TVA pour
     * 8,32 € HT, et 8,32 x 20 % = 1,664 €).
     *
     * Partout où le document montre le PU HT, la note ajoute que ce PU HT
     * est celui d'un article, son prix TTC moins sa TVA arrondie au centime
     * (voir CalculateurLigne::prixUnitaireHtAffiche()), et que la quantité
     * x ce prix peut différer de quelques centimes du total HT de la ligne :
     * sur la fiche de toute vente, qui montre la colonne « PU HT » (voir
     * FactureResource::lignesEntry()), et sur le PDF d'un document au modèle
     * M6-b -- facture, avoir sur facture ou devis à un professionnel, vente
     * sans TVA française (voir ContexteDeVente::documentMontreLePuHt()) --,
     * qui ajoute encore que le PU HT exact écrit en petit sous chaque
     * article est celui de la facture électronique. Le PDF d'un ticket, d'un
     * avoir sur ticket, d'une facture, d'un avoir ou d'un devis d'un
     * particulier, qui ne montre que des prix TTC (modèle P1), n'imprime que
     * l'explication du calcul. Rien en franchise en base, où le HT est le
     * prix TTC, sans TVA. Une explication, pas une mention : elle ne se
     * signe pas avec le document (voir ContenuFiscal). La page du lien d'un
     * devis, qui montre ses lignes comme son PDF sans le PU HT exact (seul
     * le PDF l'écrit), en a la note sans la phrase du PU HT exact.
     *
     * @param  bool  $surLePdf  la note du PDF du document, ou de la page du lien d'un devis, et non celle de sa fiche
     * @param  bool  $avecLePuHtExact  avec $surLePdf, le document écrit le PU HT exact sous chaque article : faux sur la page du lien d'un devis
     */
    public function calcul(Facture|Avoir|Devis $document, bool $surLePdf = false, bool $avecLePuHtExact = true): ?string
    {
        if ($document->franchise_en_base) {
            return null;
        }

        $calcul = ContexteDeVente::documentRetireLaTvaDuPrix($document)
            ? ($document instanceof Avoir ? self::CALCUL_RETOUR_SANS_TVA : self::CALCUL_SANS_TVA)
            : ($document instanceof Avoir ? self::CALCUL_RETOUR : self::CALCUL_VENTE);

        if ($surLePdf && ! ContexteDeVente::documentMontreLePuHt($document)) {
            return $calcul;
        }

        return implode(' ', [$calcul, self::PU_HT_D_UN_ARTICLE, ...($surLePdf && $avecLePuHtExact ? [self::PU_HT_EXACT] : [])]);
    }
}
