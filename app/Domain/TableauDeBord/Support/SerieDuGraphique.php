<?php

namespace App\Domain\TableauDeBord\Support;

/**
 * Ce qu'une carte du tableau de bord dessine (voir SeriesDesGraphiques) :
 * ses étiquettes (les mois, les taux, les magasins…) et ses séries, chacune
 * avec son libellé, ses valeurs (en centimes pour un montant, null pour un
 * mois encore à venir) et son unité ; une série en ligne sur l'axe de
 * droite pour le panier moyen ou la TVA à payer ; une ligne en pointillés
 * pour une estimation (la TVA à payer avec les charges à valider). La
 * phrase grise dit les magasins qui ne sont pas comptés (voir
 * Perimetre::phraseDesMagasinsNonComptes()), et une TVA comptée sans les
 * charges de la société.
 *
 * La carte « Écart des prix d'achat » (décision de l'éditeur du 21/11/2026,
 * D76) n'a ni jeu ni valeur par mois : sa série porte, pour ses étiquettes
 * (les douze mois de la période), le calcul de la carte (voir
 * EcartDesPrixDAchat), que la phrase grise des magasins non comptés ne fait
 * jamais disparaître.
 *
 * @phpstan-import-type Calcul from EcartDesPrixDAchat
 *
 * @phpstan-type Jeu array{libelle: string, valeurs: list<int|null>, unite: 'euros'|'nombre', en_ligne: bool, axe_de_droite: bool, pointilles: bool}
 */
final class SerieDuGraphique
{
    public const EUROS = 'euros';

    public const NOMBRE = 'nombre';

    /**
     * @param  list<string>  $etiquettes
     * @param  list<Jeu>  $jeux
     * @param  array<int, string>  $infoBulles  par rang d'étiquette, une précision (le taux de marge)
     * @param  Calcul|null  $ecartDesPrixDAchat  le calcul de la carte « Écart des prix d'achat », null pour toute autre
     */
    public function __construct(
        public readonly array $etiquettes,
        public readonly array $jeux,
        public readonly ?string $note = null,
        public readonly array $infoBulles = [],
        public readonly ?array $ecartDesPrixDAchat = null,
    ) {}

    /**
     * Une série d'un montant ou d'un nombre.
     *
     * @param  list<int|null>  $valeurs
     * @param  'euros'|'nombre'  $unite
     * @return Jeu
     */
    public static function jeu(string $libelle, array $valeurs, string $unite = self::EUROS, bool $enLigne = false, bool $axeDeDroite = false, bool $pointilles = false): array
    {
        return ['libelle' => $libelle, 'valeurs' => $valeurs, 'unite' => $unite, 'en_ligne' => $enLigne, 'axe_de_droite' => $axeDeDroite, 'pointilles' => $pointilles];
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
        return new self($this->etiquettes, $this->jeux, $note, $this->infoBulles, $this->ecartDesPrixDAchat);
    }
}
