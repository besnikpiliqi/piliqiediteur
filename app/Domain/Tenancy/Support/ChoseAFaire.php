<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Enums\UrgenceAFaire;

/**
 * Une ligne de l'« À faire » du tableau de bord d'un magasin (voir
 * ChosesAFaireDuMagasin) ou de l'espace de la société (voir
 * ChosesAFaireDeLaSociete::delaSociete()) : ce qui attend, combien, dit en une phrase
 * claire, et où cela se fait d'un clic : la fiche quand il n'y a qu'un
 * document, sinon la liste filtrée qui montre exactement ces documents,
 * chacun avec sa raison (décision de l'éditeur du 26/10/2026, règle 1).
 *
 * Elle est « à surveiller » quand la personne la voit sans pouvoir la
 * faire elle-même : son bouton ne dit alors que « Voir » (règle 2).
 * Ses lignes détaillent, quand il y a lieu, les documents qu'elle compte
 * (les factures à encaisser les plus anciennes, chaque avoir non
 * utilisé), chacune avec son lien quand la personne peut l'ouvrir.
 */
final class ChoseAFaire
{
    /**
     * @param  string  $cle  ce qu'elle compte, stable pour les tests et le filtre de la liste (« a_encaisser », « preuves »…)
     * @param  string  $groupe  le sujet, écrit à gauche de la ligne (« Ventes », « Devis », « Caisse »…)
     * @param  string|null  $date  le jour qui la classe dans son urgence, « AAAA-MM-JJ » du calendrier du magasin, la plus ancienne d'abord ; null en dernier
     * @param  string|null  $precision  une seconde phrase, plus petite (le restant dû, ce qui empêche le geste…)
     * @param  list<array{libelle: string, url: string|null, description: string|null, montant: string|null, date: string|null, date_en_rouge: bool, badge: string|null}>  $lignes
     * @param  array{libelle: string, url: string}|null  $lienDesLignes  le lien vers toute la liste, sous les lignes, quand elles n'en montrent qu'une partie
     * @param  int|null  $cible  l'identifiant de ce sur quoi agit son bouton, quand le geste se fait sur place, sans lien (« C'est vérifié » d'un écart au répertoire Sirene)
     */
    public function __construct(
        public readonly string $cle,
        public readonly string $groupe,
        public readonly UrgenceAFaire $urgence,
        public readonly int $nombre,
        public readonly string $phrase,
        public readonly ?string $url,
        public readonly string $bouton,
        public readonly bool $aSurveiller,
        public readonly ?string $date = null,
        public readonly ?string $precision = null,
        public readonly array $lignes = [],
        public readonly ?array $lienDesLignes = null,
        public readonly ?int $cible = null,
    ) {}

    /**
     * L'ordre de l'« À faire » : par urgence, puis par date, la plus
     * ancienne d'abord, une chose sans date après celles qui en ont une,
     * puis par clé, pour un ordre toujours le même.
     */
    public static function comparer(self $une, self $autre): int
    {
        return [$une->urgence->rang(), $une->date === null ? 1 : 0, $une->date ?? '', $une->cle]
            <=> [$autre->urgence->rang(), $autre->date === null ? 1 : 0, $autre->date ?? '', $autre->cle];
    }
}
