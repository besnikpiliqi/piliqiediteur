<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * L'organe qui a décidé le changement légal de la date de clôture de
 * l'exercice d'une société, que le procès-verbal nomme (voir
 * ChangerFinExerciceAction ; décision T28.1 de l'onglet « À faire vérifier
 * par un professionnel ») : l'assemblée générale extraordinaire, une
 * décision des associés, l'organe que prévoient les statuts, l'associé
 * unique ou l'entrepreneur individuel lui-même.
 */
enum OrganeDeLaDecision: string implements HasLabel
{
    case AssembleeGeneraleExtraordinaire = 'assemblee_generale_extraordinaire';
    case DecisionDesAssocies = 'decision_des_associes';
    case OrganePrevuParLesStatuts = 'organe_prevu_par_les_statuts';
    case AssocieUnique = 'associe_unique';
    case EntrepreneurIndividuel = 'entrepreneur_individuel';

    public function getLabel(): string
    {
        return match ($this) {
            self::AssembleeGeneraleExtraordinaire => 'Assemblée générale extraordinaire',
            self::DecisionDesAssocies => 'Décision des associés',
            self::OrganePrevuParLesStatuts => 'Organe prévu par les statuts',
            self::AssocieUnique => 'Associé unique',
            self::EntrepreneurIndividuel => 'Entrepreneur individuel',
        };
    }
}
