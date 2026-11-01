<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Actions\ChangerFinExerciceAction;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LogicException;

/**
 * Le changement légal de la date de clôture de l'exercice d'une société,
 * sur procès-verbal (voir ChangerFinExerciceAction) : le nouveau mois de
 * fin d'exercice (--mois), la fin de l'exercice en cours que fixe le
 * procès-verbal (--fin-exercice-en-cours, inchangée pour un effet à
 * l'exercice suivant), le jour de la décision (--decision-du) et
 * l'expert-comptable de la société qui a validé la durée de l'exercice
 * transitoire (--duree-validee-par). Le résumé montre, avant la
 * confirmation, l'exercice en cours et l'exercice suivant qu'aurait la
 * société, avec leur nombre de mois.
 */
#[Signature('editeur:societe:changer-fin-exercice {societe : Identifiant ou uuid de la société} {--mois= : Le nouveau mois de fin d\'exercice, de 1 à 12} {--fin-exercice-en-cours= : La fin de l\'exercice en cours fixée par le procès-verbal (AAAA-MM-JJ)} {--decision-du= : Le jour de la décision (AAAA-MM-JJ)} {--duree-validee-par= : L\'expert-comptable de la société qui a validé la durée de l\'exercice transitoire} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Change légalement la date de clôture de l\'exercice d\'une société, sur procès-verbal')]
class ChangerLaFinDExercice extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        $mois = $this->nouveauMois();

        try {
            $apercu = app(ChangerFinExerciceAction::class)->apercu($societe, $mois, (string) $this->option('fin-exercice-en-cours'));
        } catch (LogicException $refus) {
            return ["Aperçu impossible : {$refus->getMessage()}"];
        }

        $jour = static fn (string $jour): string => Calendrier::afficherJour($jour, 'd/m/Y');

        return [
            'Mois de fin d\'exercice : '.Calendrier::nomDuMois((int) $societe->mois_fin_exercice).' → '.($mois >= 1 && $mois <= 12 ? Calendrier::nomDuMois($mois) : 'inconnu, la procédure le refusera').'.',
            "Exercice en cours : du {$jour($apercu['debut'])} au {$jour($apercu['fin'])} ({$apercu['mois_en_cours']} mois ; fin d'origine le {$jour($apercu['fin_d_origine'])}).",
            "Exercice suivant : du {$jour($apercu['suivant_debut'])} au {$jour($apercu['suivant_fin'])} ({$apercu['mois_suivant']} mois).",
            'Durée validée par : '.(trim((string) $this->option('duree-validee-par')) ?: 'personne, la procédure le refusera').'.',
        ];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $changement = app(ChangerFinExerciceAction::class)->handle(
            $societe,
            $this->nouveauMois(),
            (string) $this->option('fin-exercice-en-cours'),
            (string) $this->option('decision-du'),
            (string) $this->option('duree-validee-par'),
            $editeur,
        );

        return 'Date de clôture de l\'exercice changée : fin d\'exercice en '.mb_strtolower(Calendrier::nomDuMois($changement->nouveau_mois)).', l\'exercice en cours finissant le '.Calendrier::afficherJour($changement->fin_exercice_en_cours_apres, 'd/m/Y').'.';
    }

    private function nouveauMois(): int
    {
        $mois = (string) $this->option('mois');

        return ctype_digit($mois) ? (int) $mois : 0;
    }
}
