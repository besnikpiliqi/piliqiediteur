<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Le calendrier des journées de caisse d'un magasin : chaque jour du
 * calendrier à partir de sa première vente (facture ou ticket émis),
 * dimanches et jours de fermeture habituelle compris, même sans vente,
 * qui tombe dans l'une de ses périodes d'activité (voir
 * PeriodesDActivite). Un magasin fermé n'a aucune journée de caisse, et
 * aucun ticket Z ne se produit pour ses jours de fermeture : la liste des
 * journées à clôturer (voir CloturerLesJourneesAction) et le contrôle de
 * chaque clôture (voir CloturerCaisseAction) lisent ce même calendrier.
 *
 * Le magasin doit être le magasin actif : ses ventes se lisent sous
 * MagasinScope.
 */
class JourneesDeCaisse
{
    public function __construct(
        private readonly PeriodesDActivite $periodesDActivite,
    ) {}

    /**
     * Les journées du magasin encore à clôturer jusqu'à $fin compris, dans
     * l'ordre : du lendemain de $derniereCloturee, ou du jour de la
     * première vente, jusqu'à $fin, sans les jours hors de ses périodes
     * d'activité. Aucune tant que le magasin n'a rien vendu.
     *
     * @param  string  $fin  AAAA-MM-JJ
     * @param  string|null  $derniereCloturee  AAAA-MM-JJ, la dernière journée clôturée du magasin
     * @return Collection<int, string> les journées, AAAA-MM-JJ
     *
     * @throws LogicException quand $fin n'est pas un jour du calendrier
     */
    public function aCloturer(Magasin $magasin, string $fin, ?string $derniereCloturee): Collection
    {
        if (Calendrier::jourValide($fin) === null) {
            throw new LogicException("« {$fin} » n'est pas une journée : une journée s'écrit AAAA-MM-JJ.");
        }

        $premiereVente = Facture::jourDeLaPremiereVente();

        if ($premiereVente === null) {
            return collect();
        }

        $journee = $derniereCloturee !== null && $derniereCloturee >= $premiereVente
            ? Calendrier::ajouterJours($derniereCloturee, 1)
            : $premiereVente;
        $periodes = $this->periodesDActivite->jours($magasin);
        $journees = collect();

        while ($journee <= $fin) {
            if (self::estDansUnePeriode($journee, $periodes)) {
                $journees->push($journee);
            }

            $journee = Calendrier::ajouterJours($journee, 1);
        }

        return $journees;
    }

    /**
     * La journée tombe-t-elle dans l'une des périodes d'activité du
     * magasin ?
     *
     * @param  string  $journee  AAAA-MM-JJ
     */
    public function estUneJourneeDActivite(Magasin $magasin, string $journee): bool
    {
        return self::estDansUnePeriode($journee, $this->periodesDActivite->jours($magasin));
    }

    /**
     * @param  list<array{0: string, 1: string|null}>  $periodes  voir PeriodesDActivite::jours()
     */
    private static function estDansUnePeriode(string $journee, array $periodes): bool
    {
        foreach ($periodes as [$debut, $fin]) {
            if ($journee >= $debut && ($fin === null || $journee <= $fin)) {
                return true;
            }
        }

        return false;
    }
}
