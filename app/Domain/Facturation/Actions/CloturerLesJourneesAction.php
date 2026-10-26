<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\JourneesDeCaisse;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use LogicException;
use Throwable;

/**
 * Clôture, dans l'ordre du calendrier, toutes les journées de caisse du
 * magasin qui ne le sont pas encore, jusqu'à un jour donné compris : le
 * bouton « Clôturer la journée » le fait jusqu'à la journée qu'il affiche,
 * au nom de la personne qui le presse (parUnePersonne(), voir
 * ListClotureCaisses) ; la clôture automatique, passé le minuit du
 * magasin, jusqu'à sa veille, et la fermeture du magasin de démonstration
 * jusqu'à son dernier jour, au nom
 * du système (parLeSysteme(), voir ClotureAutomatiqueCaisse et
 * FermerLaDemonstrationAction). Une journée oubliée (un soir sans clôture
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
 * CloturerCaisseAction), au nom de la personne, relue sous le verrou de sa
 * ligne à chaque journée, ou du système : celles qui précèdent une erreur
 * restent clôturées, et l'erreur arrête le magasin à la journée en échec,
 * reprise en premier la fois suivante. Après la dernière journée
 * clôturée par une personne, les caisses personnelles restées ouvertes se
 * ferment d'office, au nom du système, jusqu'à l'instant de la clôture ou
 * jusqu'à la fin de la dernière journée si elle est déjà finie (voir
 * FermerLesCaissesPersonnellesAction) : chaque relevé porte « fermée
 * automatiquement », et un échec de ces fermetures, signalé au journal de
 * l'application, ne défait aucune clôture. Les appelants de l'entrée du
 * système les ferment eux-mêmes, après leur propre transaction.
 *
 * La clôture demandée par une personne (le bouton) porte un jeton (R3,
 * voir ActionsUniques::executerParEtapes()) : chaque journée, toujours dans
 * sa propre transaction, cherche ce jeton sous le verrou du registre, et
 * la dernière l'inscrit avec sa clôture. Une erreur laisse clôturées les
 * journées qui la précèdent, et la même demande, répétée, reprend à la
 * journée en échec ; répétée après une réponse perdue, elle rend la
 * dernière clôture déjà faite, sans rien refaire.
 */
class CloturerLesJourneesAction
{
    public function __construct(
        private readonly CloturerCaisseAction $cloturerCaisse,
        private readonly JourneesDeCaisse $journeesDeCaisse,
        private readonly FermerLesCaissesPersonnellesAction $fermerLesCaisses,
        private readonly OperateurCourant $operateur,
        private readonly ActionsUniques $actionsUniques,
        private readonly RegistreFiscal $registre,
    ) {}

    /**
     * Les journées du magasin actif encore à clôturer jusqu'au jour $jour
     * du calendrier compris, dans l'ordre : du lendemain de la dernière
     * journée clôturée, ou du jour de la première vente, jusqu'à $jour,
     * sans les jours qui ne tombent dans aucune de ses périodes d'activité
     * (voir JourneesDeCaisse) : un magasin fermé ne produit plus de ticket
     * Z, et aucun n'est produit pour ses jours de fermeture.
     *
     * @param  string  $jour  AAAA-MM-JJ
     * @return Collection<int, string> les journées, AAAA-MM-JJ
     *
     * @throws LogicException quand $jour n'est pas un jour du calendrier
     */
    public function journeesEnAttente(string $jour): Collection
    {
        return $this->journeesDeCaisse->aCloturer(app(TenantContext::class)->magasin(), $jour, ClotureCaisse::derniereJourneeCloturee());
    }

    /**
     * Clôture, au nom de son auteur, les journées en attente du magasin,
     * qui doit être le magasin actif, puis ferme d'office les caisses
     * personnelles restées ouvertes. Une demande répétée sous le même
     * jeton rend la dernière clôture déjà faite, et ne ferme rien.
     *
     * @param  string|null  $jeton  le jeton de la demande (R3)
     * @return array{clotures: Collection<int, ClotureCaisse>, caisses_fermees: Collection<int, ClotureCaissePersonnelle>} les clôtures créées, dans l'ordre des journées, et les relevés des caisses fermées d'office
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function parUnePersonne(User $auteur, Magasin $magasin, string $jour, ?string $jeton = null): array
    {
        $this->verifierQueLeMagasinEstActif($magasin);
        $clotures = new Collection;

        $demande = $this->actionsUniques->executerParEtapes(
            $jeton,
            ActionsUniques::CLOTURER_LA_JOURNEE,
            $auteur,
            ['jour' => $jour],
            fn () => $this->registre->verrouiller($magasin),
            function () use ($auteur, $magasin, $jour, $clotures): array {
                $journees = $this->journeesEnAttente($jour);

                if ($journees->isEmpty()) {
                    return [null, true];
                }

                $cloture = $this->cloturerCaisse->handle($auteur, $magasin, $journees->first());
                $clotures->push($cloture);

                return [$cloture, $journees->count() === 1];
            },
        );

        if ($demande['deja_enregistree']) {
            return ['clotures' => new Collection($demande['resultat'] === null ? [] : [$demande['resultat']]), 'caisses_fermees' => new Collection];
        }

        return [
            'clotures' => $clotures,
            'caisses_fermees' => $clotures->isEmpty() ? new Collection : $this->fermerLesCaissesPersonnelles($magasin, $jour),
        ];
    }

    /**
     * Clôture, au nom du système, les journées en attente du magasin, qui
     * doit être le magasin actif. Ses appelants ferment ensuite d'office les
     * caisses personnelles restées ouvertes, hors de leur transaction (voir
     * ClotureAutomatiqueCaisse et FermerLaDemonstrationAction).
     *
     * @return Collection<int, ClotureCaisse> les clôtures créées, dans l'ordre des journées
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function parLeSysteme(Magasin $magasin, string $jour): Collection
    {
        $this->verifierQueLeMagasinEstActif($magasin);

        return $this->operateur->pendantLeSysteme(fn (): Collection => $this->journeesEnAttente($jour)
            ->map(fn (string $journee): ClotureCaisse => $this->cloturerCaisse->parLeSysteme($magasin, $journee))
            ->values());
    }

    /**
     * @throws LogicException
     */
    private function verifierQueLeMagasinEstActif(Magasin $magasin): void
    {
        if (app(TenantContext::class)->id() !== $magasin->getKey()) {
            throw new LogicException('Les journées d\'un magasin ne se clôturent que depuis ce magasin.');
        }
    }

    /**
     * Ferme d'office, au nom du système, les caisses personnelles restées
     * ouvertes, jusqu'à maintenant, ou jusqu'à la dernière seconde de la
     * dernière journée clôturée si elle est déjà finie. Un échec ne défait aucune
     * clôture : il est signalé au journal de l'application, et le passage
     * horaire suivant de la clôture automatique les fermera.
     *
     * @return Collection<int, ClotureCaissePersonnelle>
     */
    private function fermerLesCaissesPersonnelles(Magasin $magasin, string $jour): Collection
    {
        try {
            return $this->fermerLesCaisses->handle(
                $magasin,
                CarbonImmutable::now()->min($magasin->calendrier()->bornesDuJour($jour)[1]->subSecond()),
            );
        } catch (Throwable $exception) {
            report($exception);

            return new Collection;
        }
    }
}
