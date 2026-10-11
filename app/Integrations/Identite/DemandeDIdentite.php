<?php

namespace App\Integrations\Identite;

/**
 * Ce qu'une vérification d'identité à distance reçoit à son démarrage
 * (voir VerificationIdentite::demarrer()) : le texte que la personne
 * confirme sur l'écran de consentement du prestataire (« Vous confirmez
 * être {nom} et prendre le contrôle du compte PILIQI de {société}... »),
 * l'adresse où le prestataire la ramène, et le jeton (state) qui lie la
 * session du prestataire à la session PILIQI qui l'a ouverte, vérifié au
 * retour.
 */
final readonly class DemandeDIdentite
{
    public function __construct(
        public string $texteDeConsentement,
        public string $urlDeRetour,
        public string $state,
    ) {}
}
