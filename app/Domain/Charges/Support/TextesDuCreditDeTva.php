<?php

namespace App\Domain\Charges\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\EtatDuCreditDeTva;
use App\Domain\Charges\Enums\StatutDuReport;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Models\CreditDeTva;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;

/**
 * Les phrases du crédit de TVA et de son report (lot TVA27, maquette
 * « Message TVA avant PILIQI » approuvée par l'éditeur le 18/11/2026),
 * écrites une seule fois pour tous les écrans qui les montrent (le tableau
 * de bord, la page des charges, l'« À faire », la liste d'étapes) : du
 * côté de la société (« votre comptable ») ou de son comptable (« le
 * comptable »).
 */
final class TextesDuCreditDeTva
{
    public const SANS_LES_REMBOURSEMENTS = 'Sans les remboursements de crédit de TVA demandés : ils se noteront avec l\'état de TVA.';

    /**
     * La note de la TVA d'un périmètre qui ne compte pas toute la société :
     * le crédit d'une déclaration ne se partage pas entre ses magasins.
     */
    public const SANS_LE_REPORT = 'Sans le report du crédit de TVA, qui ne se calcule que sur toute la société, tous ses magasins et ses charges compris : chaque mois montre sa seule TVA.';

    public const TITRE_DE_LA_SAISIE = 'Votre crédit de TVA';

    public const TITRE_DE_L_ETAPE = 'Votre TVA avant PILIQI : avez-vous un crédit de TVA ?';

    /**
     * « -1 250,00 € (déclaration de novembre 2026) » ; « aucun crédit
     * (déclaration du 4e trimestre 2026) » pour un solde nul.
     */
    public static function soldeDeclare(CreditDeTva $credit): string
    {
        $declaration = '(déclaration '.self::deLaPeriode($credit->cadence, $credit->periode).')';

        return $credit->solde === 0 ? 'aucun crédit '.$declaration : self::euros($credit->solde).' '.$declaration;
    }

    /**
     * « de novembre 2026 » ou « du 4e trimestre 2026 ».
     */
    public static function deLaPeriode(CadenceDeLaTva $cadence, string $periode): string
    {
        return ($cadence === CadenceDeLaTva::Mensuelle ? 'de ' : 'du ').$cadence->libelle($periode);
    }

    /**
     * L'état d'une déclaration, comme le lit la société ou son comptable.
     */
    public static function etat(EtatDuCreditDeTva $etat, bool $pourLeComptable): string
    {
        return match ($etat) {
            EtatDuCreditDeTva::Verifie => 'Vérifié par le comptable',
            EtatDuCreditDeTva::AVerifier => $pourLeComptable ? 'À vérifier' : 'À vérifier par votre comptable',
            EtatDuCreditDeTva::NonVerifie => 'Non vérifié par un comptable',
        };
    }

    /**
     * La phrase grise du graphique de la TVA qui dit où en est le crédit de
     * la déclaration qui sert d'ancre.
     */
    public static function noteDeLAncre(?CreditDeTva $ancre, ?EtatDuCreditDeTva $etat, bool $pourLeComptable): string
    {
        if ($ancre === null || $etat === null) {
            return $pourLeComptable ? 'Crédit de TVA de la dernière déclaration : non déclaré.' : 'Crédit de TVA de votre dernière déclaration : non déclaré.';
        }

        return match ($etat) {
            EtatDuCreditDeTva::Verifie => ($pourLeComptable ? 'Crédit de départ vérifié par le comptable le ' : 'Crédit de départ vérifié par votre comptable le ').Calendrier::deParis()->afficher($ancre->verifie_le ?? $ancre->saisi_le, 'd/m/Y').'.',
            EtatDuCreditDeTva::AVerifier => $pourLeComptable ? 'Crédit de départ saisi par la société, à vérifier.' : 'Crédit de départ saisi, en attente de la vérification de votre comptable.',
            EtatDuCreditDeTva::NonVerifie => 'Crédit de départ saisi, non vérifié par un comptable.',
        };
    }

    /**
     * La pastille de la carte de la TVA qui dit où en est le crédit de la
     * déclaration qui sert d'ancre, et sa couleur.
     *
     * @return array{texte: string, couleur: string}
     */
    public static function pastilleDeLAncre(?EtatDuCreditDeTva $etat, bool $pourLeComptable): array
    {
        return match ($etat) {
            null => ['texte' => 'estimation : crédit de TVA non déclaré', 'couleur' => 'warning'],
            EtatDuCreditDeTva::Verifie => ['texte' => 'crédit de départ vérifié', 'couleur' => 'success'],
            EtatDuCreditDeTva::AVerifier => ['texte' => $pourLeComptable ? 'crédit de départ à vérifier' : 'crédit de départ à vérifier par votre comptable', 'couleur' => 'info'],
            EtatDuCreditDeTva::NonVerifie => ['texte' => 'crédit de départ non vérifié par un comptable', 'couleur' => 'warning'],
        };
    }

    /**
     * Le calcul d'une période de la chaîne (maquette « Message TVA avant
     * PILIQI ») : « TVA du mois 2 240,00 € − crédit reporté 1 250,00 € =
     * 990,00 € à payer. », ou le crédit qui reste à reporter ; sans crédit
     * déclaré ni crédit venu des périodes d'avant, « TVA du mois 1 842,30 €
     * − crédit reporté 0,00 € (non déclaré). », le crédit à reporter
     * s'ajoutant quand la période en laisse un ; la période de la
     * déclaration qui sert de point de départ dit le crédit déclaré.
     */
    public static function calculDeLaPeriode(CadenceDeLaTva $cadence, string $periode, SoldeDeLaPeriode $solde, StatutDuReport $statut): string
    {
        if ($statut === StatutDuReport::Declaree) {
            return 'Déclaration '.self::deLaPeriode($cadence, $periode).' : '.($solde->creditAReporter > 0 ? self::euros($solde->creditAReporter).' de crédit à reporter déclaré.' : 'aucun crédit à reporter déclaré.');
        }

        $calcul = 'TVA du '.$cadence->nomDeLaPeriode().' '.self::euros($solde->tva).' − crédit reporté '.self::euros($solde->creditReporte);

        if ($statut === StatutDuReport::SansCreditDeclare && $solde->creditReporte === 0) {
            return $calcul.' (non déclaré)'.($solde->creditAReporter > 0 ? ' = '.self::euros($solde->creditAReporter).' de crédit à reporter' : '').'.';
        }

        return $calcul.' = '.($solde->creditAReporter > 0 ? self::euros($solde->creditAReporter).' de crédit à reporter' : self::euros($solde->aPayer ?? 0).' à payer').'.';
    }

    /**
     * « Saisi par Claire Martin (propriétaire) le 05/12/2026. », puis, une
     * fois vérifié, « Vérifié par Paul Durand (cabinet MARTIN EXPERTISE
     * COMPTABLE) le 14/12/2026. », avec le motif d'une correction : qui a
     * fait chaque geste et quand, sous son nom figé, qui dit déjà, pour un
     * comptable, son cabinet ou sa déclaration (voir
     * FigeLeNomDeSesAuteurs).
     *
     * @return list<string>
     */
    public static function gestes(CreditDeTva $credit): array
    {
        $paris = Calendrier::deParis();
        $gestes = ['Saisi par '.self::auteur($credit->saisi_par_nom, $credit->saisi_comme).' le '.$paris->afficher($credit->saisi_le, 'd/m/Y').'.'];

        if ($credit->estVerifie() && $credit->verifie_le !== null) {
            $gestes[] = ($credit->motif === null ? 'Vérifié par ' : 'Corrigé par ').self::auteur($credit->verifie_par_nom, $credit->verifie_comme).' le '.$paris->afficher($credit->verifie_le, 'd/m/Y').($credit->motif === null ? '.' : ', motif : '.$credit->motif);
        }

        return $gestes;
    }

    /**
     * Le nom figé de qui a fait un geste, suivi de « (propriétaire) » pour
     * le propriétaire de la société ; celui d'un comptable nomme déjà son
     * cabinet ou sa déclaration.
     */
    private static function auteur(?string $nom, ?ValideePar $comme): string
    {
        return ($nom ?? '').($comme === ValideePar::Proprietaire ? ' (propriétaire)' : '');
    }

    /**
     * Ce montant en centimes, écrit en euros comme partout dans PILIQI
     * (« -1 250,00 € », voir CalculateurLigne::enEuros()).
     */
    public static function euros(int $centimes): string
    {
        return app(CalculateurLigne::class)->enEuros($centimes);
    }
}
