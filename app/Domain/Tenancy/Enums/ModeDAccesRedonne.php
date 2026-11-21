<?php

namespace App\Domain\Tenancy\Enums;

/**
 * Comment l'accès d'un compte a été redonné (voir RedonnerLAccesAction et
 * users.acces_redonne_mode) : en présence de la personne, qui a tapé
 * elle-même son nouveau mot de passe sur l'écran de qui le redonne ; en
 * son absence, son mot de passe redevenant son identifiant ; ou, pour un
 * expert-comptable de la société seulement, par un nouveau lien envoyé à
 * son adresse, où il choisit son mot de passe (son identifiant n'ouvre
 * jamais son compte).
 */
enum ModeDAccesRedonne: string
{
    case Presence = 'presence';
    case Absence = 'absence';
    case Lien = 'lien';

    /**
     * La fin de la phrase montrée à la personne à sa connexion suivante
     * (« Votre accès a été redonné le … par … »).
     */
    public function phraseAuCompte(): string
    {
        return match ($this) {
            self::Presence => 'en votre présence.',
            self::Absence => 'en votre absence.',
            self::Lien => 'par un lien envoyé à votre adresse.',
        };
    }

    /**
     * La façon dont l'accès a été redonné, dite au propriétaire et sur la
     * fiche du compte.
     */
    public function phraseAuProprietaire(): string
    {
        return match ($this) {
            self::Presence => 'en sa présence',
            self::Absence => 'en son absence',
            self::Lien => 'par un lien envoyé à son adresse',
        };
    }
}
