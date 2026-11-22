<?php

namespace App\Domain\TableauDeBord\Support;

use InvalidArgumentException;

/**
 * Les textes de la carte « Écart des prix d'achat » du tableau de bord et ce
 * qu'elle écrit pour un affichage et un mois choisi : la lecture. Tout vient
 * de la maquette validée par l'éditeur le 21/11/2026 (mot pour mot, avec les
 * apostrophes typographiques U+2019 de ses textes), jamais d'une décision de
 * Claude : aucun texte, menu, option, calcul ni état de plus.
 *
 * Deux affichages, retenus par le menu « Afficher : » : « un total par mois »
 * (valeur `avant`, chaque achat comparé au premier achat du mois) et « un seul
 * total pour les 12 mois » (valeur `debut`, calculé à part sur toute la
 * période). lecture() rend, pour un affichage et un mois choisi, la même
 * structure que le relevé de la maquette (`verite.json`), clés dans le même
 * ordre : le titre, le menu, le grand chiffre et sa pastille, la ligne de
 * détail, les légendes, le paragraphe de méthode, le dessin, le tableau du
 * calcul achat par achat et, par mois seulement, le tableau « Voir les
 * chiffres » ; les boutons de l'en-tête (« Barres », « Monter », « Descendre »)
 * sont ceux de l'en-tête commun de la grille, pas de cette lecture. Les
 * nombres s'écrivent par EcritureDesEcarts. Les textes de la page de la
 * maquette qui ne sont pas ceux de la carte (titre, introduction, exemple de
 * l'éditeur, neuf règles) sont dans les fixtures des tests.
 *
 * @phpstan-import-type Calcul from EcartDesPrixDAchat
 * @phpstan-import-type Feuille from DessinDeLEcartDesPrixDAchat
 * @phpstan-import-type Groupe from DessinDeLEcartDesPrixDAchat
 *
 * @phpstan-type Cellule array{t: string, c: string, colspan: int}
 * @phpstan-type LigneDeTableau array{classe: string, cellules: list<Cellule>}
 * @phpstan-type Lien array{cache: bool, texte: string, texteOuvert: string|null}
 * @phpstan-type Chiffre array{moisChoisi: string, grand: array{texte: string, classe: string}, pastille: array{texte: string, classe: string}, detail: string, stats?: array{nomBase: string, base: string, nomCout: string, cout: string}}
 * @phpstan-type Lecture array{mode: string, mois: int, titreCarte: string, croix: array{texte: string, ariaLabel: string}, etiquette: string, options: list<array{value: string, texte: string}>, valeurMenu: string, moisChoisi: string, grand: array{texte: string, classe: string}, pastille: array{texte: string, classe: string}, detail: string, vues: array{moisCachee: bool, periodeCachee: bool}, legendes: array{plus: string, moins: string, net: string}, methode: string, dessin: array{ariaLabel: string, viewBox: string, enfants: list<Feuille|Groupe>}, calcul: array{lien: Lien, titre: string, classeDefiler: string, tete: list<string>, lignes: list<LigneDeTableau>, notes: list<string>}, stats: array{nomBase: string, base: string, nomCout: string, cout: string}|null, chiffres: array{lien: Lien, tete: list<string>|null, lignes: list<LigneDeTableau>|null, note: string|null}}
 */
final class TextesDeLEcartDesPrixDAchat
{
    /** L'affichage « un total par mois » : le défaut, la première option du menu. */
    public const AFFICHAGE_PAR_MOIS = 'avant';

    /** L'affichage « un seul total pour les 12 mois ». */
    public const AFFICHAGE_SUR_DOUZE_MOIS = 'debut';

    public const TITRE_PAR_MOIS = 'Écart des prix d’achat par mois';

    public const TITRE_SUR_DOUZE_MOIS = 'Écart des prix d’achat cumulé sur 12 mois';

    public const TEXTE_DE_LA_CROIX = '×';

    public const LIBELLE_DE_LA_CROIX = 'Retirer ce graphique';

    public const ETIQUETTE_DU_MENU = 'Afficher :';

    /**
     * @var list<array{value: string, texte: string}>
     */
    public const OPTIONS = [
        ['value' => self::AFFICHAGE_PAR_MOIS, 'texte' => 'un total par mois (chaque achat comparé au premier achat du mois)'],
        ['value' => self::AFFICHAGE_SUR_DOUZE_MOIS, 'texte' => 'un seul total pour les 12 mois (calculé à part, sur toute la période)'],
    ];

    public const LEGENDE_DES_HAUSSES = 'Hausses : achats plus chers que le premier achat du mois';

    public const LEGENDE_DES_BAISSES = 'Baisses : achats moins chers que le premier achat du mois';

    public const LEGENDE_DE_L_ECART = 'Écart du mois (hausses + baisses)';

    public const LIBELLE_DU_DESSIN = 'Écart des prix d\'achat sur douze mois';

    public const CADRE_DU_DESSIN = '0 0 520 270';

    public const NOM_DES_HAUSSES = 'Payé en plus (hausses)';

    public const NOM_DES_BAISSES = 'Payé en moins (baisses)';

    public const PASTILLE_SANS_ECART = 'Prix inchangés';

    public const LIEN_DES_CHIFFRES = 'Voir les chiffres';

    public const LIEN_DES_CHIFFRES_OUVERT = 'Masquer les chiffres';

    /** @var list<string> */
    public const ENTETE_DES_CHIFFRES = ['Mois', 'Hausses', 'Baisses', 'Écart du mois', 'Achats comparés'];

    public const NOTE_DES_CHIFFRES = '« Hausses » : la somme des écarts des achats plus chers que le premier achat du mois ; « Baisses » : la somme des écarts des achats moins chers ; leur somme est l’écart du mois. « Achats comparés » : achats qui avaient un premier achat plus tôt dans le même mois. « Somme des douze mois » : l’addition des écarts des mois ; ce n’est pas le total des 12 mois, qui se calcule à part, sur toute la période.';

    public const METHODE_PAR_MOIS = 'Chaque mois se calcule dans le mois, jamais avec un autre mois. Pour chaque produit acheté plusieurs fois dans le mois : le premier achat du mois est le prix de départ du mois ; chaque achat suivant du même produit dans le mois est comparé à lui : (prix HT de l’achat moins prix HT du premier achat du mois) multiplié par la quantité achetée. Le total du mois est la somme des lignes du tableau ; un achat au même prix n’a pas d’écart et n’est pas affiché ; un produit sans écart dans le mois n’est pas affiché. Montants HT des factures d’achat validées.';

    /**
     * Les textes propres à chaque affichage.
     *
     * @var array{avant: array{plus: string, moins: string, lienDuCalcul: string, lienDuCalculOuvert: string, enteteDuCalcul: list<string>}, debut: array{plus: string, moins: string, lienDuCalcul: string, lienDuCalculOuvert: string, enteteDuCalcul: list<string>}}
     */
    private const PAR_AFFICHAGE = [
        self::AFFICHAGE_PAR_MOIS => [
            'plus' => 'Payé plus cher qu’au premier achat du mois',
            'moins' => 'Payé moins cher qu’au premier achat du mois',
            'lienDuCalcul' => 'Voir le calcul du mois choisi, achat par achat',
            'lienDuCalculOuvert' => 'Masquer le calcul du mois choisi',
            'enteteDuCalcul' => ['Produit', 'Prix de départ du mois', 'Achats suivants du mois', 'Qté', 'Écart par pièce', 'Écart'],
        ],
        self::AFFICHAGE_SUR_DOUZE_MOIS => [
            'plus' => 'Payé plus cher qu’au premier achat de l’année',
            'moins' => 'Payé moins cher qu’au premier achat de l’année',
            'lienDuCalcul' => 'Voir le calcul de la période, achat par achat',
            'lienDuCalculOuvert' => 'Masquer le calcul de la période',
            'enteteDuCalcul' => ['Produit', 'Prix de départ de l’année', 'Achats suivants de l’année', 'Qté', 'Écart par pièce', 'Écart'],
        ],
    ];

    /**
     * Ce que la carte écrit pour un affichage (`avant` ou `debut`) et un mois
     * choisi (de 0 à 11).
     *
     * @param  Calcul  $calcul
     * @return Lecture
     */
    public static function lecture(array $calcul, string $affichage, int $mois): array
    {
        if ($affichage !== self::AFFICHAGE_PAR_MOIS && $affichage !== self::AFFICHAGE_SUR_DOUZE_MOIS) {
            throw new InvalidArgumentException('Affichage inconnu : '.$affichage);
        }

        if ($mois < 0 || $mois >= EcartDesPrixDAchat::NOMBRE_DE_MOIS) {
            throw new InvalidArgumentException('Le mois choisi est un numéro de 0 à 11 : '.$mois);
        }

        $textes = self::PAR_AFFICHAGE[$affichage];
        $surDouzeMois = $affichage === self::AFFICHAGE_SUR_DOUZE_MOIS;
        $chiffre = $surDouzeMois ? self::chiffreDeLaPeriode($calcul) : self::chiffreDuMois($calcul, $mois);
        $detail = $surDouzeMois ? self::calculDeLaPeriode($calcul) : self::calculDuMois($calcul, $mois);

        return [
            'mode' => $affichage,
            'mois' => $mois,
            'titreCarte' => $surDouzeMois ? self::TITRE_SUR_DOUZE_MOIS : self::TITRE_PAR_MOIS,
            'croix' => ['texte' => self::TEXTE_DE_LA_CROIX, 'ariaLabel' => self::LIBELLE_DE_LA_CROIX],
            'etiquette' => self::ETIQUETTE_DU_MENU,
            'options' => self::OPTIONS,
            'valeurMenu' => $affichage,
            'moisChoisi' => $chiffre['moisChoisi'],
            'grand' => $chiffre['grand'],
            'pastille' => $chiffre['pastille'],
            'detail' => $chiffre['detail'],
            'vues' => ['moisCachee' => $surDouzeMois, 'periodeCachee' => ! $surDouzeMois],
            'legendes' => ['plus' => self::LEGENDE_DES_HAUSSES, 'moins' => self::LEGENDE_DES_BAISSES, 'net' => self::LEGENDE_DE_L_ECART],
            'methode' => $surDouzeMois ? self::methodeSurDouzeMois($calcul['periode']) : self::METHODE_PAR_MOIS,
            'dessin' => [
                'ariaLabel' => self::LIBELLE_DU_DESSIN,
                'viewBox' => self::CADRE_DU_DESSIN,
                'enfants' => DessinDeLEcartDesPrixDAchat::noeuds($calcul, $mois),
            ],
            'calcul' => [
                'lien' => ['cache' => false, 'texte' => $textes['lienDuCalcul'], 'texteOuvert' => $textes['lienDuCalculOuvert']],
                'titre' => $detail['titre'],
                'classeDefiler' => $surDouzeMois ? 'defiler long' : 'defiler',
                'tete' => $textes['enteteDuCalcul'],
                'lignes' => $detail['lignes'],
                'notes' => $detail['notes'],
            ],
            'stats' => $chiffre['stats'] ?? null,
            'chiffres' => $surDouzeMois
                ? ['lien' => ['cache' => true, 'texte' => self::LIEN_DES_CHIFFRES, 'texteOuvert' => null], 'tete' => null, 'lignes' => null, 'note' => null]
                : ['lien' => ['cache' => false, 'texte' => self::LIEN_DES_CHIFFRES, 'texteOuvert' => self::LIEN_DES_CHIFFRES_OUVERT]] + self::tableauDesMois($calcul),
        ];
    }

    /**
     * Le paragraphe de méthode des douze mois, avec la période entre
     * parenthèses.
     */
    private static function methodeSurDouzeMois(string $periode): string
    {
        return 'Le total des 12 mois se calcule à part, avec la même méthode que les mois mais sur toute la période ('.$periode.') : pour chaque produit, le premier achat de la période est le prix de départ de l’année ; chaque achat suivant du même produit, de n’importe quel mois, est comparé à lui : (prix HT de l’achat moins prix HT du premier achat de l’année) multiplié par la quantité achetée. Tous ces écarts sont additionnés en un seul total. Ce n’est donc pas la somme des mois, qui se calculent chacun dans leur mois. Un achat au même prix n’a pas d’écart et n’est pas affiché. Montants HT des factures d’achat validées.';
    }

    private static function classeDeLEcart(int $valeur): string
    {
        return $valeur > 0 ? 'plus' : ($valeur < 0 ? 'moins' : '');
    }

    /**
     * @return Cellule
     */
    private static function cellule(string $texte, string $classe = '', int $colspan = 1): array
    {
        return ['t' => $texte, 'c' => $classe, 'colspan' => $colspan];
    }

    /**
     * @param  list<Cellule>  $cellules
     * @return LigneDeTableau
     */
    private static function ligne(array $cellules, string $classe = ''): array
    {
        return ['classe' => $classe, 'cellules' => $cellules];
    }

    /**
     * « 6 déc. » : le jour de l'achat et le mois court.
     *
     * @param  Calcul  $calcul
     * @param  array{k: int, j: int, q: int, c: int}  $achat
     */
    private static function dateDeLAchat(array $calcul, array $achat): string
    {
        return $achat['j'].' '.$calcul['courts'][$achat['k']];
    }

    /**
     * « 6 déc. : 8 × 19,54 € » : le jour, la quantité et le prix d'un achat.
     *
     * @param  Calcul  $calcul
     * @param  array{k: int, j: int, q: int, c: int}  $achat
     */
    private static function achatEcrit(array $calcul, array $achat): string
    {
        return self::dateDeLAchat($calcul, $achat).' : '.$achat['q'].' × '.EcritureDesEcarts::prix($achat['c']);
    }

    /**
     * Le grand chiffre et sa pastille : la classe vient du signe de l'écart.
     *
     * @param  'avant'|'debut'  $affichage
     * @return array{grand: array{texte: string, classe: string}, pastille: array{texte: string, classe: string}}
     */
    private static function grandChiffreEtPastille(string $affichage, int $ecart): array
    {
        $textes = self::PAR_AFFICHAGE[$affichage];
        $grand = ['texte' => EcritureDesEcarts::signe($ecart), 'classe' => 'grand'];
        $pastille = ['texte' => self::PASTILLE_SANS_ECART, 'classe' => 'pastille neutre'];

        if ($ecart > 0) {
            $grand['classe'] .= ' plus';
            $pastille = ['texte' => $textes['plus'], 'classe' => 'pastille plus'];
        } elseif ($ecart < 0) {
            $grand['classe'] .= ' moins';
            $pastille = ['texte' => $textes['moins'], 'classe' => 'pastille moins'];
        }

        return ['grand' => $grand, 'pastille' => $pastille];
    }

    /**
     * La phrase des produits sans écart : « 4 produits n’ont pas d’écart et
     * ne sont pas comptés (2 achetés une seule fois, 2 toujours au même
     * prix). » ; au singulier la maquette écrit « ne est pas compté », sans
     * élision, texte reproduit tel quel. Vide quand il n'y en a aucun.
     *
     * @param  array{uneFois: int, memePrix: int}  $periode
     */
    private static function phraseDesProduitsSansEcart(array $periode): string
    {
        $sans = $periode['uneFois'] + $periode['memePrix'];

        if ($sans === 0) {
            return '';
        }

        $detail = [];

        if ($periode['uneFois'] > 0) {
            $detail[] = $periode['uneFois'].' acheté'.($periode['uneFois'] > 1 ? 's' : '').' une seule fois';
        }

        if ($periode['memePrix'] > 0) {
            $detail[] = $periode['memePrix'].' toujours au même prix';
        }

        return $sans.' produit'.($sans > 1 ? 's' : '').' '.($sans > 1 ? 'n’ont' : 'n’a').' pas d’écart et ne '.($sans > 1 ? 'sont' : 'est').' pas compté'.($sans > 1 ? 's' : '').' ('.implode(', ', $detail).').';
    }

    /**
     * @param  Calcul  $calcul
     * @return Chiffre
     */
    private static function chiffreDuMois(array $calcul, int $rang): array
    {
        $mois = $calcul['calculs'][$rang];
        $moisChoisi = 'Écart total du mois : '.$mois['m'];

        if ($mois['n'] === 0) {
            $aucun = $mois['premiers'] === [];

            return [
                'moisChoisi' => $moisChoisi,
                'grand' => ['texte' => '—', 'classe' => 'grand'],
                'pastille' => ['texte' => $aucun ? 'Aucun achat ce mois-ci' : 'Aucun achat comparable ce mois-ci', 'classe' => 'pastille neutre'],
                'detail' => $aucun ? 'Rien n’a été acheté : pas d’écart à calculer.' : 'Aucun produit acheté deux fois dans le mois : pas d’écart à calculer.',
            ];
        }

        $detail = 'Hausses '.EcritureDesEcarts::signe($mois['hausses']).', baisses '.EcritureDesEcarts::signe(-$mois['baisses']).'. '
            .EcritureDesEcarts::pourcentage($mois['ecart'], $mois['base']).' sur '
            .($mois['n'] > 1 ? 'les '.$mois['n'].' achats comparés, qui valent ' : 'l’achat comparé, qui vaut ')
            .EcritureDesEcarts::euros($mois['base']).' au prix du premier achat du mois.';

        return ['moisChoisi' => $moisChoisi] + self::grandChiffreEtPastille(self::AFFICHAGE_PAR_MOIS, $mois['ecart']) + ['detail' => $detail];
    }

    /**
     * @param  Calcul  $calcul
     * @return Chiffre
     */
    private static function chiffreDeLaPeriode(array $calcul): array
    {
        $periode = $calcul['calculDeLaPeriode'];
        $parties = [];

        if ($periode['visibles'] > 0) {
            $parties[] = EcritureDesEcarts::pourcentage($periode['ecart'], $periode['base']).' par rapport au prix du premier achat de l’année.';
            $parties[] = ($periode['visibles'] > 1 ? 'Total des '.$periode['visibles'].' produits avec écart' : 'Total du produit avec écart').' : '.EcritureDesEcarts::euros($periode['montant']).' HT.';
        } else {
            $phrase = self::phraseDesProduitsSansEcart($periode);
            $parties[] = $phrase === '' ? 'Aucun achat à comparer sur les 12 mois.' : $phrase;
        }

        return [
            'moisChoisi' => 'Écart sur les 12 mois, depuis le premier achat de chaque produit : '.$calcul['periode'],
        ] + self::grandChiffreEtPastille(self::AFFICHAGE_SUR_DOUZE_MOIS, $periode['ecart']) + [
            'detail' => implode(' ', $parties),
            'stats' => [
                'nomBase' => self::NOM_DES_HAUSSES,
                'base' => EcritureDesEcarts::signe($periode['hausses']),
                'nomCout' => self::NOM_DES_BAISSES,
                'cout' => EcritureDesEcarts::signe(-$periode['baisses']),
            ],
        ];
    }

    /**
     * Le tableau « Voir les chiffres » : une ligne par mois et la somme des
     * douze mois.
     *
     * @param  Calcul  $calcul
     * @return array{tete: list<string>, lignes: list<LigneDeTableau>, note: string}
     */
    private static function tableauDesMois(array $calcul): array
    {
        $somme = $calcul['sommeDesMois'];
        $lignes = [];

        foreach ($calcul['calculs'] as $mois) {
            $cellules = [self::cellule($mois['m'])];

            if ($mois['premiers'] === []) {
                array_push($cellules, self::cellule('—', 'tiret'), self::cellule('—', 'tiret'), self::cellule('—', 'tiret'));
            } else {
                array_push(
                    $cellules,
                    self::cellule($mois['hausses'] > 0 ? EcritureDesEcarts::signe($mois['hausses']) : '—', $mois['hausses'] > 0 ? 'plus' : 'tiret'),
                    self::cellule($mois['baisses'] > 0 ? EcritureDesEcarts::signe(-$mois['baisses']) : '—', $mois['baisses'] > 0 ? 'moins' : 'tiret'),
                    self::cellule(EcritureDesEcarts::signe($mois['ecart']), self::classeDeLEcart($mois['ecart'])),
                );
            }

            $cellules[] = self::cellule((string) $mois['n']);
            $lignes[] = self::ligne($cellules);
        }

        $lignes[] = self::ligne([
            self::cellule('Somme des douze mois'),
            self::cellule(EcritureDesEcarts::signe($somme['hausses']), 'plus'),
            self::cellule(EcritureDesEcarts::signe(-$somme['baisses']), 'moins'),
            self::cellule(EcritureDesEcarts::signe($somme['ecart']), self::classeDeLEcart($somme['ecart'])),
            self::cellule((string) $somme['n']),
        ], 'total');

        return ['tete' => self::ENTETE_DES_CHIFFRES, 'lignes' => $lignes, 'note' => self::NOTE_DES_CHIFFRES];
    }

    /**
     * Le calcul du mois choisi, achat par achat : seuls les produits qui ont
     * un écart dans le mois s'écrivent.
     *
     * @param  Calcul  $calcul
     * @return array{titre: string, lignes: list<LigneDeTableau>, notes: list<string>}
     */
    private static function calculDuMois(array $calcul, int $rang): array
    {
        $mois = $calcul['calculs'][$rang];
        $titre = 'Comment '.$mois['m'].' est calculé, achat par achat';

        if ($mois['premiers'] === []) {
            return [
                'titre' => $titre,
                'lignes' => [self::ligne([self::cellule('Aucun achat validé ce mois-là.', 'vide', 6)])],
                'notes' => ['Un produit non acheté ce mois-là n’apparaît pas.'],
            ];
        }

        $lignes = [];
        $masques = 0;
        $affiches = 0;

        foreach ($mois['premiers'] as $premier) {
            $avecEcart = array_values(array_filter($mois['pas'], fn (array $pas): bool => $pas['i'] === $premier['i'] && $pas['ecart'] !== 0));

            if ($avecEcart === []) {
                $masques++;

                continue;
            }

            $affiches++;
            $nom = $calcul['produits'][$premier['i']];
            $lignes[] = self::ligne([
                self::cellule($nom),
                self::cellule(self::achatEcrit($calcul, $premier['e'])),
                self::cellule('—', 'tiret'),
                self::cellule((string) $premier['e']['q']),
                self::cellule('—', 'tiret'),
                self::cellule('—', 'tiret'),
            ]);

            foreach ($avecEcart as $pas) {
                $ecartParPiece = $pas['e']['c'] - $pas['ref']['c'];
                $lignes[] = self::ligne([
                    self::cellule($nom),
                    self::cellule('', 'tiret'),
                    self::cellule(self::achatEcrit($calcul, $pas['e'])),
                    self::cellule((string) $pas['e']['q']),
                    self::cellule(EcritureDesEcarts::signe($ecartParPiece), self::classeDeLEcart($ecartParPiece)),
                    self::cellule(EcritureDesEcarts::signe($pas['ecart']), self::classeDeLEcart($pas['ecart'])),
                ]);
            }
        }

        if ($affiches === 0) {
            $lignes[] = self::ligne([self::cellule('Aucun produit avec un écart ce mois-ci.', 'vide', 6)]);
        }

        $lignes[] = self::ligne([
            self::cellule('Total du mois, '.$mois['m']),
            self::cellule(''),
            self::cellule(''),
            self::cellule(''),
            self::cellule(''),
            self::cellule(EcritureDesEcarts::signe($mois['ecart']), self::classeDeLEcart($mois['ecart'])),
        ], 'total');

        $masquesEcrits = $masques > 0
            ? ' ('.$masques.($masques > 1 ? ' produits sans écart sont masqués' : ' produit sans écart est masqué').').'
            : '.';

        return [
            'titre' => $titre,
            'lignes' => $lignes,
            'notes' => ['Écart d’un achat = (prix de l’achat − prix du premier achat du mois) × quantité achetée : chaque « + » ou « − » est la différence entre deux achats du même produit dans le mois, tous deux visibles ici. Le total est la somme des lignes ; il est égal au chiffre du graphique. Seuls les produits qui ont un écart dans le mois apparaissent'.$masquesEcrits],
        ];
    }

    /**
     * Le calcul des douze mois, achat par achat : pour chaque produit avec
     * écart, son départ, ses achats à écart, son total ; puis le total des
     * produits avec écart et les cinq notes.
     *
     * @param  Calcul  $calcul
     * @return array{titre: string, lignes: list<LigneDeTableau>, notes: list<string>}
     */
    private static function calculDeLaPeriode(array $calcul): array
    {
        $periode = $calcul['calculDeLaPeriode'];
        $lignes = [];

        foreach ($periode['lignes'] as $produit) {
            if (! $produit['affiche']) {
                continue;
            }

            $lignes[] = self::ligne([
                self::cellule($produit['nom']),
                self::cellule(self::achatEcrit($calcul, $produit['ref'])),
                self::cellule('—', 'tiret'),
                self::cellule(EcritureDesEcarts::entier($produit['ref']['q'])),
                self::cellule('—', 'tiret'),
                self::cellule('—', 'tiret'),
            ]);

            foreach ($produit['pas'] as $pas) {
                if ($pas['ecart'] === 0) {
                    continue;
                }

                $ecartParPiece = $pas['e']['c'] - $pas['ref']['c'];
                $lignes[] = self::ligne([
                    self::cellule($produit['nom']),
                    self::cellule('', 'tiret'),
                    self::cellule(self::achatEcrit($calcul, $pas['e'])),
                    self::cellule(EcritureDesEcarts::entier($pas['e']['q'])),
                    self::cellule(EcritureDesEcarts::signe($ecartParPiece), self::classeDeLEcart($ecartParPiece)),
                    self::cellule(EcritureDesEcarts::signe($pas['ecart']), self::classeDeLEcart($pas['ecart'])),
                ]);
            }

            $lignes[] = self::ligne([
                self::cellule('Total '.$produit['nom']),
                self::cellule('départ compris', 'mention'),
                self::cellule(EcritureDesEcarts::euros($produit['montant'])),
                self::cellule(EcritureDesEcarts::entier($produit['qte'])),
                self::cellule(''),
                self::cellule(EcritureDesEcarts::signe($produit['net']), self::classeDeLEcart($produit['net'])),
            ], 'soustotal');
        }

        $egaux = $periode['egaux'];

        $lignes[] = self::ligne([
            self::cellule('Total des 12 mois, produits avec écart'),
            self::cellule('départ compris', 'mention'),
            self::cellule(EcritureDesEcarts::euros($periode['montant'])),
            self::cellule(EcritureDesEcarts::entier($periode['qte'])),
            self::cellule(''),
            self::cellule(EcritureDesEcarts::signe($periode['ecart']), self::classeDeLEcart($periode['ecart'])),
        ], 'total');

        $phraseSansEcart = self::phraseDesProduitsSansEcart($periode);
        $baseDuPourcentage = $periode['base'];
        $memePrixEcrit = $egaux['n'] > 0
            ? ' (ici : '.$egaux['n'].' achat'.($egaux['n'] > 1 ? 's' : '').', '.EcritureDesEcarts::entier($egaux['qte']).' pièce'.($egaux['qte'] > 1 ? 's' : '').', '.EcritureDesEcarts::euros($egaux['montant']).')'
            : '';

        return [
            'titre' => 'Comment les 12 mois ('.$calcul['periode'].') sont calculés, achat par achat',
            'lignes' => $lignes,
            'notes' => [
                'Écart d’un achat = (prix de l’achat − prix de départ de l’année) × quantité achetée ; le prix de départ est le premier achat du produit dans la période, quel que soit son mois.',
                'Hausses '.EcritureDesEcarts::signe($periode['hausses']).', baisses '.EcritureDesEcarts::signe(-$periode['baisses']).', total '.EcritureDesEcarts::signe($periode['ecart']).', soit '.EcritureDesEcarts::pourcentage($periode['ecart'], $baseDuPourcentage).' : l’écart divisé par ce que les mêmes achats auraient coûté au prix de départ (le total des prix moins l’écart : '.EcritureDesEcarts::euros($baseDuPourcentage).'). Ce total se calcule à part : ce n’est pas la somme des mois.',
                'Ces totaux ne comptent que les produits qui ont un écart : ce n’est pas le total de tous les achats des 12 mois.'.($phraseSansEcart === '' ? '' : ' '.$phraseSansEcart),
                'Ligne de total : le total des prix additionne la quantité × le prix HT de chaque achat affiché, prix de départ compris ; le total des quantités additionne leurs quantités ; l’écart total, leurs écarts. Vérification, produit par produit : total des prix − total des quantités × prix de départ = écart total.',
                'Un achat au même prix que le prix de départ n’est pas affiché et n’entre dans aucun total'.$memePrixEcrit.'.',
            ],
        ];
    }
}
