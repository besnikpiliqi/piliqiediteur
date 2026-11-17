<?php

namespace App\Domain\Demonstration\Taches;

use App\Console\Commands\CloreInscriptionsSansCode;
use App\Console\Commands\ClotureAutomatiqueCaisse;
use App\Console\Commands\ConstruireLesModelesDeLaDemonstration;
use App\Console\Commands\EffacerPiecesVerification;
use App\Console\Commands\EffacerTelechargementsDeDocuments;
use App\Console\Commands\ExpirerDevis;
use App\Console\Commands\ExpirerInvitationsDesCabinets;
use App\Console\Commands\LireLEtatDeLaDemonstration;
use App\Console\Commands\MarquerFacturesEnRetard;
use App\Console\Commands\MarquerLesFinsDesDemonstrations;
use App\Console\Commands\MesurerLesCopiesDeLaDemonstration;
use App\Console\Commands\NettoyerLaDemonstration;
use App\Console\Commands\PasserLesTachesDesCopies;
use App\Console\Commands\PurgerActionsTraitees;
use App\Console\Commands\PurgerDemandesInscription;
use App\Console\Commands\PurgerInvitationsDesCabinets;
use App\Console\Commands\PurgerJournalAudit;
use App\Console\Commands\PurgerLesArchivesDeLaDemonstration;
use App\Console\Commands\PurgerLesMessagesRecusDeLaDemonstration;
use App\Console\Commands\PurgerLesTravauxEchouesDeLaDemonstration;
use App\Console\Commands\PurgerTraces;
use App\Console\Commands\PurgerVerifications;
use App\Console\Commands\RattacherLesExpertsComptables;
use App\Console\Commands\RelancerFacturesImpayees;
use App\Console\Commands\RelancerLesMessagesDeLaDemonstration;
use App\Console\Commands\RelancerTransmissionsPdp;
use App\Console\Commands\ReprendreLesCopiesDeLaDemonstration;
use App\Console\Commands\ReverifierSirene;
use App\Console\Commands\SuivreEnvoisRecommandes;
use App\Console\Commands\SupprimerLesCopiesEchues;
use App\Console\Commands\SurveillerLaFileDAttente;
use App\Console\Commands\VerifierIntegriteFiscale;
use App\Console\Commands\VerifierJournalAudit;
use App\Console\Commands\VerifierLIsolation;

/**
 * Chaque tâche planifiée de bootstrap/app.php, rangée selon le site qui la
 * fait passer (lot DEMO-COPIE, C.13) ; un test échoue si une tâche n'est
 * pas rangée, ou n'est pas planifiée là où sa liste le dit.
 *
 * - DE_CHAQUE_COPIE : les tâches du calendrier des magasins et des
 *   sociétés, que le site réel planifie chaque heure, et que le site de
 *   démonstration fait passer dans chaque copie par
 *   demonstration:taches-d-une-copie, jamais par son planificateur : les
 *   relances y sont capturées, la plateforme y est la fausse.
 * - DE_LA_NUIT_DES_COPIES : la vérification du registre fiscal, en mode
 *   incrémental, chaque nuit, dans chaque copie visitée depuis sa dernière
 *   vérification ; au site réel, chaque nuit.
 * - DE_LA_CONSTRUCTION_D_UN_MODELE : le contrôle de l'isolation et celui
 *   des chaînes du journal d'audit, que la construction d'un modèle passe
 *   sur sa base de travail, zéro problème exigé avant sa mise en service
 *   (voir ConstruireUnModele) ; au site réel, chaque nuit.
 * - DU_SITE_DE_DEMONSTRATION : ce que le planificateur du site de
 *   démonstration fait passer, et rien d'autre.
 * - DU_SITE_REEL_SEUL : ce que le site réel planifie en plus des tâches de
 *   chaque copie, jamais le site de démonstration (aucune trace n'y est
 *   gardée, C.17 ; ni inscription, ni courrier, ni répertoire Sirene réel).
 */
final class TachesDesSites
{
    /**
     * @var list<class-string>
     */
    public const DE_CHAQUE_COPIE = [
        ClotureAutomatiqueCaisse::class,
        MarquerFacturesEnRetard::class,
        ExpirerDevis::class,
        RelancerFacturesImpayees::class,
        ExpirerInvitationsDesCabinets::class,
        PurgerInvitationsDesCabinets::class,
        RattacherLesExpertsComptables::class,
        EffacerTelechargementsDeDocuments::class,
        RelancerTransmissionsPdp::class,
        PurgerActionsTraitees::class,
    ];

    /**
     * @var list<class-string>
     */
    public const DE_LA_NUIT_DES_COPIES = [VerifierIntegriteFiscale::class];

    /**
     * @var list<class-string>
     */
    public const DE_LA_CONSTRUCTION_D_UN_MODELE = [VerifierLIsolation::class, VerifierJournalAudit::class];

    /**
     * @var list<class-string>
     */
    public const DU_SITE_DE_DEMONSTRATION = [
        ConstruireLesModelesDeLaDemonstration::class,
        NettoyerLaDemonstration::class,
        ReprendreLesCopiesDeLaDemonstration::class,
        SupprimerLesCopiesEchues::class,
        PurgerLesArchivesDeLaDemonstration::class,
        PasserLesTachesDesCopies::class,
        MesurerLesCopiesDeLaDemonstration::class,
        PurgerLesMessagesRecusDeLaDemonstration::class,
        PurgerLesTravauxEchouesDeLaDemonstration::class,
        SurveillerLaFileDAttente::class,
    ];

    /**
     * @var list<class-string>
     */
    public const DU_SITE_REEL_SEUL = [
        MarquerLesFinsDesDemonstrations::class,
        RelancerLesMessagesDeLaDemonstration::class,
        LireLEtatDeLaDemonstration::class,
        VerifierIntegriteFiscale::class,
        VerifierJournalAudit::class,
        VerifierLIsolation::class,
        PurgerTraces::class,
        PurgerJournalAudit::class,
        PurgerVerifications::class,
        EffacerPiecesVerification::class,
        PurgerDemandesInscription::class,
        CloreInscriptionsSansCode::class,
        ReverifierSirene::class,
        SuivreEnvoisRecommandes::class,
        SurveillerLaFileDAttente::class,
    ];

    /**
     * Les tâches que le site réel planifie.
     *
     * @return list<class-string>
     */
    public static function duSiteReel(): array
    {
        return array_values(array_unique([...self::DE_CHAQUE_COPIE, ...self::DU_SITE_REEL_SEUL]));
    }
}
