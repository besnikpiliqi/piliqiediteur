<?php

namespace App\Domain\TableauDeBord\Support;

/**
 * Ce qu'une carte du tableau de bord dessine (voir SeriesDesGraphiques) :
 * ses étiquettes (les mois, les taux, les magasins…) et ses séries, chacune
 * avec son libellé, ses valeurs (en centimes pour un montant, null pour un
 * mois encore à venir) et son unité ; une série en ligne sur l'axe de
 * droite pour le panier moyen ou la TVA à payer. La phrase grise dit les
 * magasins qui ne sont pas comptés (voir Perimetre::phraseDesMagasinsNonComptes()).
 *
 * @phpstan-type Jeu array{libelle: string, valeurs: list<int|null>, unite: 'euros'|'nombre', en_ligne: bool, axe_de_droite: bool}
 */
final class SerieDuGraphique
{
    public const EUROS = 'euros';

    public const NOMBRE = 'nombre';

    /**
     * @param  list<string>  $etiquettes
     * @param  list<Jeu>  $jeux
     * @param  array<int, string>  $infoBulles  par rang d'étiquette, une précision (le taux de marge)
     */
    public function __construct(
        public readonly array $etiquettes,
        public readonly array $jeux,
        public readonly ?string $note = null,
        public readonly array $infoBulles = [],
    ) {}

    /**
     * Une série d'un montant ou d'un nombre.
     *
     * @param  list<int|null>  $valeurs
     * @param  'euros'|'nombre'  $unite
     * @return Jeu
     */
    public static function jeu(string $libelle, array $valeurs, string $unite = self::EUROS, bool $enLigne = false, bool $axeDeDroite = false): array
    {
        return ['libelle' => $libelle, 'valeurs' => $valeurs, 'unite' => $unite, 'en_ligne' => $enLigne, 'axe_de_droite' => $axeDeDroite];
    }

    /**
     * Rien à dessiner : aucune valeur non nulle.
     */
    public function estVide(): bool
    {
        foreach ($this->jeux as $jeu) {
            foreach ($jeu['valeurs'] as $valeur) {
                if ($valeur !== null && $valeur !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    public function avecLaNote(?string $note): self
    {
        return new self($this->etiquettes, $this->jeux, $note, $this->infoBulles);
    }
}
