<?php

namespace App\Domain\TableauDeBord\Support;

/**
 * Ce qu'une carte du tableau de bord dessine (voir SeriesDesGraphiques) :
 * ses étiquettes (les mois, les taux, les magasins…) et ses séries, chacune
 * avec son libellé, ses valeurs (en centimes pour un montant, en centièmes
 * de point pour un écart en %, null pour une barre encore à venir) et son
 * unité ; une série en ligne sur l'axe de droite pour le panier moyen ou la
 * TVA à payer ; une ligne en pointillés pour une estimation (la TVA à payer
 * avec les charges à valider). La phrase grise dit les magasins qui ne sont
 * pas comptés (voir Perimetre::phraseDesMagasinsNonComptes()), et une TVA
 * comptée sans les charges de la société.
 *
 * Une carte par période (voir Graphique::estParPeriode()) porte en plus
 * ses barres (voir Barre : leur jeton, leur intitulé, leur niveau), son
 * titre et sa ligne grise au pas de ses barres (« par trimestre »), et, en
 * écart en % sur un an, les deux montants que chaque pourcentage compare.
 *
 * La carte « Écart des prix d'achat » (décision de l'éditeur du 21/11/2026,
 * D76) n'a ni jeu ni valeur par mois : sa série porte, pour ses étiquettes
 * (les douze mois de la période), le calcul de la carte (voir
 * EcartDesPrixDAchat), que la phrase grise des magasins non comptés ne fait
 * jamais disparaître.
 *
 * @phpstan-import-type Calcul from EcartDesPrixDAchat
 *
 * @phpstan-type Jeu array{libelle: string, valeurs: list<int|null>, unite: 'euros'|'nombre'|'pourcent', en_ligne: bool, axe_de_droite: bool, pointilles: bool, role: 'serie'|'comparaison'|'tendance'|'ecart'}
 * @phpstan-type EcartSurUnAn array{unite: 'euros'|'nombre'|'pourcent', lignes: list<array{actuel: int|null, precedent: int|null}>}
 */
final class SerieDuGraphique
{
    public const EUROS = 'euros';

    public const NOMBRE = 'nombre';

    /** Un écart en %, en centièmes de point : 1 250 pour « +12,5 % ». */
    public const POURCENT = 'pourcent';

    /**
     * Ce qu'une série est pour la carte (voir jeu()) : une série de la
     * carte, la même série un an plus tôt (la comparaison), sa tendance, ou
     * l'écart en % sur un an qui remplace les montants. L'écran en tire la
     * couleur et la forme de chacune.
     */
    public const ROLE_SERIE = 'serie';

    public const ROLE_COMPARAISON = 'comparaison';

    public const ROLE_TENDANCE = 'tendance';

    public const ROLE_ECART = 'ecart';

    /**
     * @param  list<string>  $etiquettes
     * @param  list<Jeu>  $jeux
     * @param  array<int, string>  $infoBulles  par rang d'étiquette, une précision (le taux de marge)
     * @param  Calcul|null  $ecartDesPrixDAchat  le calcul de la carte « Écart des prix d'achat », null pour toute autre
     * @param  list<Barre>  $barres  celles d'une carte par période, une par étiquette
     * @param  EcartSurUnAn|null  $ecartSurUnAn  d'une carte par période en écart en % sur un an : pour chaque barre, ce que la période a valu et ce que la même période valait un an plus tôt
     */
    public function __construct(
        public readonly array $etiquettes,
        public readonly array $jeux,
        public readonly ?string $note = null,
        public readonly array $infoBulles = [],
        public readonly ?array $ecartDesPrixDAchat = null,
        public readonly array $barres = [],
        public readonly ?string $titre = null,
        public readonly ?string $descriptif = null,
        public readonly ?array $ecartSurUnAn = null,
    ) {}

    /**
     * Une série d'un montant, d'un nombre ou d'un écart en %.
     *
     * @param  list<int|null>  $valeurs
     * @param  'euros'|'nombre'|'pourcent'  $unite
     * @param  'serie'|'comparaison'|'tendance'|'ecart'  $role
     * @return Jeu
     */
    public static function jeu(string $libelle, array $valeurs, string $unite = self::EUROS, bool $enLigne = false, bool $axeDeDroite = false, bool $pointilles = false, string $role = self::ROLE_SERIE): array
    {
        return ['libelle' => $libelle, 'valeurs' => $valeurs, 'unite' => $unite, 'en_ligne' => $enLigne, 'axe_de_droite' => $axeDeDroite, 'pointilles' => $pointilles, 'role' => $role];
    }

    /**
     * Rien à dessiner : aucune valeur non nulle, ni, en écart en % sur un
     * an, aucun des montants que les pourcentages comparent.
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

        foreach ($this->ecartSurUnAn['lignes'] ?? [] as $ligne) {
            if (($ligne['actuel'] ?? 0) !== 0 || ($ligne['precedent'] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    public function avecLaNote(?string $note): self
    {
        return new self($this->etiquettes, $this->jeux, $note, $this->infoBulles, $this->ecartDesPrixDAchat, $this->barres, $this->titre, $this->descriptif, $this->ecartSurUnAn);
    }
}
