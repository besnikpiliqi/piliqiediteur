<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreFactureAction : l'avoir ne reçoit son numéro
 * légal qu'à l'émission, moment où le stock est restauré (voir
 * AvoirEmis/RestaurerStockApresEmissionAvoir), et dans la même
 * transaction, dans cet ordre :
 *  1. le registre fiscal du magasin est verrouillé, avant l'avoir et la
 *     numérotation (voir RegistreFiscal) ; il donne l'instant de
 *     l'émission ;
 *  2. l'avoir, relu verrouillé, arrête ses montants, reçoit son numéro,
 *     dans l'année de cet instant à l'heure de Paris, et cet instant, en
 *     UTC, pour date d'émission (voir HorodateSonEmission) -- un avoir sur
 *     des tickets, que compte la clôture de sa journée (voir
 *     Avoir::corrigeDesTickets()), n'est plus émis une fois cette journée
 *     clôturée, jusqu'à minuit (voir
 *     ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()) ;
 *  3. AvoirEmis rend le stock et impute l'avoir sur ses factures ;
 *  4. l'avoir est signé en négatif dans la chaîne des ventes (voir
 *     ContenuFiscal), avec les lignes et les numéros des documents qu'il
 *     corrige et ses imputations : il diminue le grand total réel et
 *     augmente le grand total absolu ;
 *  5. un avoir sur des tickets de caisse inscrit en plus une annulation de
 *     ticket au journal des événements (JET 190).
 * Sans clé de signature, ou si l'horloge du serveur a reculé, rien de tout
 * cela n'a lieu.
 */
class EmettreAvoirAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
        private readonly RegistreFiscal $registre,
        private readonly ContenuFiscal $contenuFiscal,
    ) {}

    /**
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Avoir $avoir): Avoir
    {
        return DB::transaction(function () use ($avoir) {
            $instant = $this->registre->verrouiller($avoir->magasin);
            $avoir = Avoir::query()->whereKey($avoir->getKey())->lockForUpdate()->firstOrFail();

            if (! $avoir->statut->peutTransitionnerVers(StatutAvoir::Emise)) {
                throw new LogicException("Impossible d'émettre un avoir au statut [{$avoir->statut->value}].");
            }

            if ($avoir->corrigeDesTickets()) {
                ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee($avoir->magasin, $instant);
            }

            $this->arreterLesMontants($avoir);

            $numero = $this->numerotation->suivant($avoir->magasin, TypeDocument::Avoir, $instant->enHeureLocale()->format('Y'));

            $avoir->forceFill([
                'numero' => $numero,
                'statut' => StatutAvoir::Emise,
                'date_emission' => $instant->utc,
            ])->save();

            // Synchrone et dans la même transaction, voir le commentaire
            // de AvoirEmis.
            AvoirEmis::dispatch($avoir);

            $avoir = $avoir->fresh();
            $contenu = $this->contenuFiscal->document($avoir);
            $enregistrement = $this->registre->enregistrerVente('avoir', $contenu['document']['totaux']['ttc'], $contenu, $avoir);

            if ($contenu['document']['corrige'] === 'ticket') {
                $this->registre->journaliser(CodeEvenement::AnnulationDeTicket, [
                    'avoir' => $avoir->numero,
                    'tickets' => $contenu['document']['documents_corriges'],
                    'montant_ttc' => $contenu['document']['totaux']['ttc'],
                    'sequence_vente' => $enregistrement->sequence,
                    'motif' => $avoir->motif,
                ], $avoir);
            }

            return $avoir;
        });
    }

    /**
     * Garde du domaine pour un brouillon émis par un appel direct, après
     * sa création dans une autre transaction : entre-temps, un autre avoir
     * a pu créditer les mêmes lignes. Ses quantités sont donc revérifiées
     * et ses montants arrêtés au moment où il devient un document fiscal
     * -- le dernier retour d'une ligne en prend alors le solde exact (voir
     * LigneFacture::montantsDuRetour()).
     *
     * Par EnregistrerAvoirAction, seul chemin de l'application, les lignes
     * restent verrouillées depuis CreerAvoirAction jusqu'ici : cette
     * revérification n'y refuse jamais rien et retrouve les montants déjà
     * calculés à la création.
     *
     * @throws LogicException
     */
    private function arreterLesMontants(Avoir $avoir): void
    {
        $lignesArretees = [];

        foreach ($avoir->lignes as $ligneAvoir) {
            $ligneFacture = LigneFacture::query()
                ->whereKey($ligneAvoir->ligne_facture_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restante = $ligneFacture->quantiteCreditableRestante();

            if ($ligneAvoir->quantite > $restante) {
                throw new LogicException("La ligne \"{$ligneFacture->description}\" a été créditée entre-temps par un autre avoir : il ne reste que {$restante} article(s) à créditer.");
            }

            $ligneAvoir->forceFill($ligneFacture->montantsDuRetour($ligneAvoir->quantite))->save();
            $lignesArretees[] = $ligneAvoir->only(['montant_ht', 'montant_tva', 'montant_ttc']);
        }

        $avoir->forceFill($this->calculateur->totaliser($lignesArretees));
    }
}
