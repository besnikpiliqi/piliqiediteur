<?php

namespace App\Domain\Demonstration\Enums;

/**
 * Pourquoi une démonstration a pris fin (voir TerminerLaDemonstrationAction)
 * : ses 30 jours, la clôture de l'inscription de la société, sa
 * révocation, un nouveau propriétaire qui a saisi le code du courrier, ou
 * l'échec définitif de sa création. Le journal d'audit de la société
 * l'inscrit avec « Démonstration terminée ».
 */
enum CauseDeLaFin: string
{
    case TrenteJours = 'trente_jours';
    case Cloture = 'cloture';
    case Revocation = 'revocation';
    case NouveauProprietaire = 'nouveau_proprietaire';
    case Echec = 'echec';

    public function libelle(): string
    {
        return match ($this) {
            self::TrenteJours => '30 jours',
            self::Cloture => 'inscription close',
            self::Revocation => 'société révoquée',
            self::NouveauProprietaire => 'nouveau propriétaire',
            self::Echec => 'échec de la création',
        };
    }

    /**
     * La démonstration est-elle supprimée aussitôt (archivée puis supprimée
     * sur le site de démonstration), ou terminée ?
     */
    public function supprimeAussitot(): bool
    {
        return in_array($this, [self::Cloture, self::Revocation, self::NouveauProprietaire], true);
    }
}
