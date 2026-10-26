<?php

namespace App\Integrations\EnvoiRecommande;

/**
 * Où en est un courrier recommandé (voir ServiceEnvoiRecommande::suivre())
 * : déposé, remis contre signature au destinataire, ou revenu à
 * l'expéditeur faute d'avoir été retiré.
 */
final readonly class SuiviDEnvoi
{
    public const DEPOSE = 'depose';

    public const REMIS = 'remis';

    public const RETOURNE = 'retourne';

    public function __construct(public string $statut) {}

    /**
     * Lit un statut tel qu'un prestataire l'envoie dans son rappel. Un
     * statut inconnu vaut « déposé » : rien n'est tenu pour remis.
     *
     * @param  array<mixed>  $rappel
     */
    public static function depuisLeRappel(array $rappel): self
    {
        return new self(in_array($rappel['statut'] ?? null, [self::REMIS, self::RETOURNE], true) ? $rappel['statut'] : self::DEPOSE);
    }
}
