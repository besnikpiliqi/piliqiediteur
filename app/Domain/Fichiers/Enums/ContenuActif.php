<?php

namespace App\Domain\Fichiers\Enums;

/**
 * Ce qu'un PDF reçu ne peut pas contenir (décision de l'éditeur du
 * 07/11/2026, Q-FI1 = a, voir AnalyseurDePdf) : chaque contenu est une
 * raison de refuser le fichier, que le message nomme.
 */
enum ContenuActif: string
{
    /** /JavaScript, /JS. */
    case JavaScript = 'javascript';

    /** /Launch. */
    case LancementDeProgramme = 'lancement_de_programme';

    /** /SubmitForm, /ImportData. */
    case EnvoiDeFormulaire = 'envoi_de_formulaire';

    /** /RichMedia, et les actions et annotations de son, de film ou d'écran. */
    case ContenuMultimedia = 'contenu_multimedia';

    /** /XFA. */
    case FormulaireDynamique = 'formulaire_dynamique';

    /** /GoToR, /GoToE. */
    case LienVersUnAutreFichier = 'lien_vers_un_autre_fichier';

    /** /OpenAction ou /AA autre qu'une simple destination d'affichage. */
    case ActionAutomatique = 'action_automatique';

    /** /EmbeddedFile(s), /FileAttachment, autre qu'un seul XML de Factur-X. */
    case FichierJoint = 'fichier_joint';

    public function libelle(): string
    {
        return match ($this) {
            self::JavaScript => 'du JavaScript',
            self::LancementDeProgramme => 'le lancement d\'un programme',
            self::EnvoiDeFormulaire => 'un formulaire qui envoie ou importe des données',
            self::ContenuMultimedia => 'un contenu multimédia',
            self::FormulaireDynamique => 'un formulaire dynamique (XFA)',
            self::LienVersUnAutreFichier => 'un lien qui ouvre un autre fichier',
            self::ActionAutomatique => 'une action automatique à l\'ouverture',
            self::FichierJoint => 'un fichier joint',
        };
    }
}
