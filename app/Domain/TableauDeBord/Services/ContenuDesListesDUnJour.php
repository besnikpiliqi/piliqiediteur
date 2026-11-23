<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\ListeDeDocuments;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\ListeDUnJour;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Ce que dit la fenêtre qu'un clic sur un jour d'un graphique par période
 * ouvre (choix de l'éditeur du 16/11/2026, lot GRAPH-AN ; maquette
 * 01-graph-an.html du 22/11/2026) : pour chaque magasin que le graphique
 * compte (voir ListesDesJours), chaque liste de documents que la personne y
 * voit en entier, avec le nombre de documents de ce jour et leur total HT
 * net, lus sur les montants que la page lit pour ses cartes (voir
 * SeriesDesGraphiques::documentsDeVente(), avoirs() et documentsDAchat(),
 * chacun lu une seule fois pour la page) : aucun nom de client, aucun numéro
 * de document. La liste
 * elle-même, filtrée sur ce jour, est celle de l'écran du magasin, qui
 * applique les droits de la personne (voir le filtre « Jour » des listes).
 *
 * Un avoir et un avoir fournisseur se comptent en moins, comme dans le
 * graphique ; les montants sont les montants enregistrés, additionnés en
 * centimes.
 *
 * @phpstan-type ListeDuJour array{liste: ListeDeDocuments, nombre: int, ht: int}
 * @phpstan-type MagasinDuJour array{magasin: ListeDUnJour, listes: list<ListeDuJour>}
 */
final class ContenuDesListesDUnJour
{
    public function __construct(
        private readonly ListesDesJours $listes,
        private readonly SeriesDesGraphiques $series,
    ) {}

    /**
     * @param  string  $jeton  celui d'un jour de la carte (voir Barre::jeton())
     * @return list<MagasinDuJour>
     *
     * @throws AuthorizationException un graphique que le périmètre ne permet pas
     * @throws LogicException un jour qui n'est pas une barre de la carte, ou qui est à venir
     */
    public function pour(CalculDesSeries $calcul, Graphique $graphique, string $jeton): array
    {
        $contenu = [];

        foreach ($this->listes->pour($calcul, $graphique, $jeton) as $duMagasin) {
            $magasin = CleDuModele::de($duMagasin->magasin);
            $lignes = [];

            foreach ($duMagasin->listes as $liste) {
                $lignes[] = $this->ligne($calcul, $liste, $magasin, $duMagasin->jour);
            }

            $contenu[] = ['magasin' => $duMagasin, 'listes' => $lignes];
        }

        return $contenu;
    }

    /**
     * @return ListeDuJour
     */
    private function ligne(CalculDesSeries $calcul, ListeDeDocuments $liste, int $magasin, string $jour): array
    {
        $nombre = 0;
        $ht = 0;

        foreach ($this->documents($calcul, $liste) as $document) {
            if ($document['magasin'] === $magasin && $document['jour'] === $jour && $document['document'] === $this->document($liste)) {
                $nombre++;
                $ht += $calcul->signe($document) * $document['ht'];
            }
        }

        return ['liste' => $liste, 'nombre' => $nombre, 'ht' => $ht];
    }

    /**
     * Les documents que la page a lus pour cette liste.
     *
     * @return list<array{document: string, magasin: int, jour: string, ht: int}>
     */
    private function documents(CalculDesSeries $calcul, ListeDeDocuments $liste): array
    {
        return match ($liste) {
            ListeDeDocuments::Factures => $this->series->documentsDeVente($calcul),
            ListeDeDocuments::Avoirs => $this->series->avoirs($calcul),
            ListeDeDocuments::FacturesAchat, ListeDeDocuments::AvoirsFournisseur => $this->series->documentsDAchat($calcul),
        };
    }

    /**
     * Le genre de document que la page lit pour cette liste.
     */
    private function document(ListeDeDocuments $liste): string
    {
        return match ($liste) {
            ListeDeDocuments::Factures => 'facture',
            ListeDeDocuments::Avoirs => 'avoir',
            ListeDeDocuments::FacturesAchat => 'achat',
            ListeDeDocuments::AvoirsFournisseur => 'avoir_fournisseur',
        };
    }
}
