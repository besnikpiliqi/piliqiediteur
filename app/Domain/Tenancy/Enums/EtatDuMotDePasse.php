<?php

namespace App\Domain\Tenancy\Enums;

/**
 * Où en est le mot de passe d'un compte (voir User::etatDuMotDePasse()) :
 * choisi par la personne, ou en attente de l'être. Aucune valeur ne sert à
 * se connecter : seul le mot de passe choisi ouvre un compte pour de bon ;
 * un mot de passe provisoire ne sert qu'une fois, à le choisir (voir
 * MotsDePasseProvisoires).
 */
enum EtatDuMotDePasse: string
{
    /**
     * La personne a choisi son mot de passe : elle seule le connaît.
     */
    case Choisi = 'choisi';

    /**
     * Un mot de passe provisoire tiré au hasard attend sa première
     * connexion : il vaut encore et n'a pas servi.
     */
    case ProvisoireValable = 'provisoire_valable';

    /**
     * Le mot de passe provisoire n'a pas servi et ne vaut plus : son délai
     * est passé. Il se redonne par « Redonner l'accès ».
     */
    case ProvisoireExpire = 'provisoire_expire';

    /**
     * Le mot de passe provisoire a servi à une connexion qui n'a pas fini
     * le choix du mot de passe : il ne sert plus, et l'accès se redonne.
     */
    case ProvisoireUtilise = 'provisoire_utilise';

    /**
     * Aucun mot de passe utilisable : un collaborateur d'un cabinet ou un
     * expert-comptable de la société qui n'a pas encore choisi le sien (en
     * présence de qui crée son compte, ou par son lien).
     */
    case Aucun = 'aucun';

    public function estEnAttente(): bool
    {
        return $this !== self::Choisi;
    }

    /**
     * Le badge de la liste de l'équipe, de la liste des membres et de la
     * liste des collaborateurs.
     */
    public function libelle(): string
    {
        return match ($this) {
            self::Choisi => 'Mot de passe choisi',
            self::ProvisoireValable => 'Mot de passe provisoire à utiliser',
            self::ProvisoireExpire => 'Mot de passe provisoire expiré',
            self::ProvisoireUtilise => 'Choix du mot de passe inachevé',
            self::Aucun => 'Mot de passe à choisir',
        };
    }

    /**
     * Ce que la personne qui gère le compte doit faire, écrit sur la fiche
     * du compte.
     */
    public function phraseDeLaFiche(): string
    {
        return match ($this) {
            self::Choisi => 'La personne a choisi son mot de passe.',
            self::ProvisoireValable => 'La personne n\'a pas encore choisi son mot de passe : son mot de passe provisoire, remis en main propre, ne sert qu\'une fois et jusqu\'à sa date d\'expiration.',
            self::ProvisoireExpire => 'Le mot de passe provisoire n\'a pas servi et ne vaut plus : redonnez l\'accès à la personne.',
            self::ProvisoireUtilise => 'Le mot de passe provisoire a servi, mais la personne n\'a pas fini de choisir le sien : redonnez-lui l\'accès.',
            self::Aucun => 'Aucun mot de passe : la personne le choisit en présence de qui lui redonne l\'accès (ou par son lien, pour un expert-comptable de la société).',
        };
    }
}
