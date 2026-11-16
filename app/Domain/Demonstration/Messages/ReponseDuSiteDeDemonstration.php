<?php

namespace App\Domain\Demonstration\Messages;

/**
 * La réponse du site de démonstration à un message signé : son code HTTP
 * (202 en préparation ou en attente d'une place, 200 prête ou fait, 409 en
 * mise à jour, 410 inconnue ou supprimée, 422 refusé), l'état de la copie
 * à son registre, la génération du lien qu'il connaît et la cause d'un
 * échec ou d'un refus. Le site réel n'en tire que l'état de la carte,
 * l'accusé d'une génération et la cause d'un échec.
 */
final readonly class ReponseDuSiteDeDemonstration
{
    /**
     * Les états de la copie au registre que le site réel lit dans une
     * réponse.
     */
    public const ETATS_LUS = ['en_preparation', 'en_attente_d_une_place', 'prete', 'en_echec', 'supprimee'];

    public function __construct(
        public int $code,
        public ?string $etat,
        public ?int $generation,
        public ?string $cause,
    ) {}

    /**
     * La réponse lue dans un corps JSON, sans rien garder d'inconnu.
     */
    public static function depuis(int $code, mixed $corps): self
    {
        $corps = is_array($corps) ? $corps : [];
        $etat = $corps['etat'] ?? null;
        $generation = $corps['generation'] ?? null;
        $cause = $corps['cause'] ?? null;

        return new self(
            $code,
            is_string($etat) && in_array($etat, self::ETATS_LUS, true) ? $etat : null,
            is_int($generation) && $generation >= 1 ? $generation : null,
            is_string($cause) && $cause !== '' ? mb_substr($cause, 0, 255) : null,
        );
    }

    /**
     * Le message est-il traité pour de bon (fait, refusé ou sans objet) ?
     * Sinon (409 : mise à jour en cours ; autre code), il repart.
     */
    public function estDefinitive(): bool
    {
        return in_array($this->code, [200, 202, 410, 422], true);
    }
}
