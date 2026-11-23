<?php

namespace App\Domain\Demonstration\Enums;

/**
 * Où en est une démonstration, telle que le site réel la connaît (voir
 * Demonstration) : demandée, puis en préparation ou en attente d'une place
 * sur le site de démonstration, puis prête ; en échec tant que le site de
 * démonstration réessaie ; terminée (30 jours, ou échec définitif) ou
 * supprimée (inscription close, révocation, nouveau propriétaire).
 *
 * Le site réel ne passe à un état du site de démonstration que sur sa
 * réponse (voir EtatsDeLaDemonstration) : jamais un état que la
 * démonstration n'a pas confirmé.
 */
enum EtatDeLaDemonstration: string
{
    case Demandee = 'demandee';
    case EnPreparation = 'en_preparation';
    case EnAttenteDUnePlace = 'en_attente_d_une_place';
    case Prete = 'prete';
    case EnEchec = 'en_echec';
    case Terminee = 'terminee';
    case Supprimee = 'supprimee';

    /**
     * Les états d'une démonstration en cours, une seule par société (index
     * unique partiel de la table).
     *
     * @return list<self>
     */
    public static function enCours(): array
    {
        return [self::Demandee, self::EnPreparation, self::EnAttenteDUnePlace, self::Prete];
    }

    /**
     * @return list<string>
     */
    public static function valeursEnCours(): array
    {
        return array_map(static fn (self $etat): string => $etat->value, self::enCours());
    }

    /**
     * Une démonstration vivante, que la carte montre : en cours, ou en
     * échec tant que le site de démonstration réessaie.
     */
    public function estVivante(): bool
    {
        return in_array($this, [...self::enCours(), self::EnEchec], true);
    }

    public function estFinie(): bool
    {
        return $this === self::Terminee || $this === self::Supprimee;
    }
}
