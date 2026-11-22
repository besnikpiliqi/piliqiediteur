<?php

namespace App\Domain\TableauDeBord\Services;

use App\Domain\TableauDeBord\Enums\Graphique;
use App\Domain\TableauDeBord\Enums\ListeDeDocuments;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Support\Barre;
use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\ListeDUnJour;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\CleDuModele;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Ce que renvoie le clic sur un jour d'un graphique par période (choix de
 * l'éditeur du 16/11/2026, « Cliquer pour voir le détail » : clic sur un
 * jour, la liste filtrée des documents de ce jour, pour qui peut les
 * voir) : pour chaque magasin que le graphique compte, les listes de
 * documents que la personne y voit en entier, filtrées sur ce jour ; rien
 * pour la valeur du stock, qui n'est la somme d'aucun document du jour. Le
 * jour doit être l'une des barres que la carte montre dans son détail ouvert
 * (voir CalculDesSeries::barresDe()), jamais à venir : un jour forgé est
 * refusé, sans rien lire. Aucun document n'est lu ici, ni aucun magasin
 * hors de ceux que le périmètre permet (voir Perimetre::magasinsDe()) ; la
 * liste elle-même est celle de l'écran du magasin, qui applique les droits
 * de la personne.
 */
final class ListesDesJours
{
    /**
     * @param  string  $jeton  celui d'une barre d'un jour de la carte (voir Barre::jeton())
     * @return list<ListeDUnJour>
     *
     * @throws AuthorizationException un graphique que le périmètre ne permet pas
     * @throws LogicException un jour qui n'est pas une barre de la carte, ou qui est à venir
     */
    public function pour(CalculDesSeries $calcul, Graphique $graphique, string $jeton): array
    {
        $perimetre = $calcul->perimetre;
        $carte = $calcul->carte($graphique);

        if ($carte === null || ! $graphique->estParPeriode() || ! $perimetre->voit($graphique)) {
            throw new AuthorizationException(PerimetreDesGraphiques::REFUS);
        }

        $jour = $this->barreDuJour($calcul->barresDe($carte), $jeton)->premierJour;
        $listes = $graphique->listesDuJour($perimetre->societe->aDesArticles());
        $ouvertes = [];

        foreach ($perimetre->magasinsDe($graphique) as $magasin) {
            $visibles = array_values(array_filter($listes, fn (ListeDeDocuments $liste): bool => $this->voitLaListe($calcul, $liste, $magasin)));

            if ($visibles !== []) {
                $ouvertes[] = new ListeDUnJour($magasin, $jour, $visibles);
            }
        }

        return $ouvertes;
    }

    /**
     * @param  list<Barre>  $barres
     *
     * @throws LogicException
     */
    private function barreDuJour(array $barres, string $jeton): Barre
    {
        foreach ($barres as $barre) {
            if ($barre->jeton() === $jeton && $barre->niveau === NiveauDeLaBarre::Jour && ! $barre->aVenir) {
                return $barre;
            }
        }

        throw new LogicException('Ce jour n\'est pas un jour que la carte montre.');
    }

    private function voitLaListe(CalculDesSeries $calcul, ListeDeDocuments $liste, Magasin $magasin): bool
    {
        return $calcul->perimetre->magasinsAvec($liste->source())->contains(fn (Magasin $vu): bool => CleDuModele::de($vu) === CleDuModele::de($magasin));
    }
}
