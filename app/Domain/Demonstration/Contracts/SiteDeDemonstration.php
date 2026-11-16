<?php

namespace App\Domain\Demonstration\Contracts;

use App\Domain\Demonstration\Exceptions\SiteDeDemonstrationInjoignable;
use App\Domain\Demonstration\Messages\ReponseDuSiteDeDemonstration;

/**
 * Le site de démonstration, vu du site réel (lot DEMO-COPIE) : le seul
 * chemin entre les deux sites, par HTTPS, sans jamais lire la base de
 * l'autre. Le site réel lui envoie ses messages signés (voir
 * MessagesSignes) et lit son état général, signé par lui.
 */
interface SiteDeDemonstration
{
    /**
     * Un site de démonstration est-il réglé pour ce site réel
     * (DEMONSTRATION_URL) ? Sinon, la carte « Ma démonstration » dit qu'il
     * n'est pas lancé, et aucun message ne part.
     */
    public function estLance(): bool;

    /**
     * L'origine exacte du site de démonstration (schéma, hôte, port), où
     * part le billet d'entrée.
     */
    public function origine(): ?string;

    /**
     * Envoie un message signé à POST /interne/messages et rend sa réponse.
     *
     * @param  array{message: string, signature: string}  $enveloppe
     *
     * @throws SiteDeDemonstrationInjoignable
     */
    public function envoyer(array $enveloppe): ReponseDuSiteDeDemonstration;

    /**
     * L'état publié du site de démonstration (GET /interne/etat, C.19) :
     * son JSON et sa signature, que le site réel vérifie (LectureDeLEtat).
     *
     * @return array{etat: string, signature: string}
     *
     * @throws SiteDeDemonstrationInjoignable quand il ne répond pas ou répond autre chose
     */
    public function lireLEtat(): array;
}
