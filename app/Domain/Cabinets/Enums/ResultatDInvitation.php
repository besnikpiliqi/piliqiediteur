<?php

namespace App\Domain\Cabinets\Enums;

/**
 * Ce qu'a fait le geste « Inviter un client » d'un cabinet (voir
 * InviterUneEntrepriseAction) : une invitation envoyée à un SIREN libre,
 * ou déjà envoyée à la même adresse ; une demande de rattachement envoyée
 * à une société déjà inscrite, ou déjà en cours ; rien, le lien étant déjà
 * actif.
 */
enum ResultatDInvitation: string
{
    case InvitationEnvoyee = 'invitation_envoyee';
    case InvitationDejaEnvoyee = 'invitation_deja_envoyee';
    case DemandeEnvoyee = 'demande_envoyee';
    case DemandeDejaEnvoyee = 'demande_deja_envoyee';
    case DejaLie = 'deja_lie';

    /**
     * Ce que l'écran du cabinet en dit, à cette adresse.
     */
    public function message(string $email): string
    {
        return match ($this) {
            self::InvitationEnvoyee => "Invitation envoyée à {$email}.",
            self::InvitationDejaEnvoyee => "Une invitation est déjà ouverte pour ce SIREN à {$email} : elle n'est pas renvoyée.",
            self::DemandeEnvoyee => 'Cette entreprise est déjà inscrite sur '.config('app.name').' : une demande de rattachement est envoyée à cette adresse, et son propriétaire la voit dans son espace.',
            self::DemandeDejaEnvoyee => 'Une demande de rattachement est déjà en cours pour ce SIREN : son propriétaire la voit dans son espace.',
            self::DejaLie => 'Cette entreprise est déjà votre cliente.',
        };
    }
}
