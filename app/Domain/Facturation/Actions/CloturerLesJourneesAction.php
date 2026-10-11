<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Services\JourneesDeCaisse;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Clôture, dans l'ordre du calendrier, toutes les journées de caisse du
 * magasin qui ne le sont pas encore, jusqu'à un jour donné compris : le
 * bouton « Clôturer la journée » le fait jusqu'à aujourd'hui (voir
 * ListClotureCaisses), la clôture automatique de minuit jusqu'à hier (voir
 * ClotureAutomatiqueCaisse). Une journée oubliée (un soir sans clôture
 * manuelle suivi d'une nuit où la commande n'a pas tourné) est ainsi
 * clôturée la première, avant les suivantes, et aucune vente ne reste
 * hors de toute clôture.
 *
 * Un ticket Z se produit chaque jour du calendrier à partir de la
 * première vente du magasin (facture ou ticket émis), dimanches et jours
 * de fermeture habituelle compris, même à zéro, tant que le magasin n'est
 * pas fermé (voir JourneesDeCaisse) ; jamais avant : un magasin qui n'a
 * encore rien vendu n'a aucune journée à clôturer, ne numérote rien, et
 * son code reste modifiable (voir Magasin::aNumeroteUnDocument()).
 *
 * Chaque journée se clôture dans sa propre transaction (voir
 * CloturerCaisseAction) : celles qui précèdent une erreur restent
 * clôturées, et l'erreur arrête le magasin à la journée en échec, reprise
 * en premier la fois suivante.
 */
class CloturerLesJourneesAction
{
    public function __construct(
        private readonly CloturerCaisseAction $cloturerCaisse,
        private readonly JourneesDeCaisse $journeesDeCaisse,
    ) {}

    /**
     * Les journées du magasin actif encore à clôturer jusqu'à $jour compris
     * (à l'heure de Paris), dans l'ordre : du lendemain de la dernière
     * journée clôturée, ou du jour de la première vente, jusqu'à $jour,
     * sans les jours qui ne tombent dans aucune de ses périodes d'activité
     * (voir JourneesDeCaisse) : un magasin fermé ne produit plus de ticket
     * Z, et aucun n'est produit pour ses jours de fermeture.
     *
     * @return Collection<int, string> les journées, AAAA-MM-JJ
     */
    public function journeesEnAttente(CarbonInterface $jour): Collection
    {
        return $this->journeesDeCaisse->aCloturer(
            app(TenantContext::class)->magasin(),
            CarbonImmutable::instance($jour)->setTimezone(config('app.timezone'))->toDateString(),
            ClotureCaisse::derniereJourneeCloturee(),
        );
    }

    /**
     * Clôture les journées en attente du magasin, qui doit être le magasin
     * actif : ses ventes et ses clôtures se lisent sous MagasinScope.
     *
     * @return Collection<int, ClotureCaisse> les clôtures créées, dans l'ordre des journées
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function jusquAu(Magasin $magasin, CarbonInterface $jour): Collection
    {
        if (app(TenantContext::class)->id() !== $magasin->getKey()) {
            throw new LogicException('Les journées d\'un magasin ne se clôturent que depuis ce magasin.');
        }

        return $this->journeesEnAttente($jour)
            ->map(fn (string $journee): ClotureCaisse => $this->cloturerCaisse->handle($magasin, $journee));
    }
}
