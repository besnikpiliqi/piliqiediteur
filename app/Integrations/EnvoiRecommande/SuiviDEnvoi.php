<?php

namespace App\Integrations\EnvoiRecommande;

use App\Integrations\Identite\ResultatDIdentite;

/**
 * Où en est un pli (voir ServiceEnvoiRecommande::suivre()) : déposé, remis
 * contre signature (ou, pour une lettre recommandée électronique, acceptée
 * par son destinataire, dont le prestataire qualifié donne alors
 * l'identité qu'il a établie), ou revenu à l'expéditeur.
 */
final readonly class SuiviDEnvoi
{
    public const DEPOSE = 'depose';

    public const REMIS = 'remis';

    public const RETOURNE = 'retourne';

    public function __construct(
        public string $statut,
        public ?ResultatDIdentite $identiteDuDestinataire = null,
    ) {}

    /**
     * Lit un statut tel qu'un prestataire l'envoie dans son rappel, et
     * l'identité du destinataire d'une lettre électronique acceptée, réduite
     * à la liste blanche (voir ResultatDIdentite::depuisLaReponse()). Un
     * statut inconnu vaut « déposé » : rien n'est tenu pour remis.
     *
     * @param  array<mixed>  $rappel
     */
    public static function depuisLeRappel(array $rappel): self
    {
        $statut = in_array($rappel['statut'] ?? null, [self::REMIS, self::RETOURNE], true) ? $rappel['statut'] : self::DEPOSE;

        return new self($statut, is_array($rappel['identite'] ?? null) ? ResultatDIdentite::depuisLaReponse($rappel['identite']) : null);
    }
}
