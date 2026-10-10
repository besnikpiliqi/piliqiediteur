<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * L'émission est le SEUL moment où une facture reçoit son numéro : un
 * brouillon n'est jamais numéroté. Elle est donc aussi le seul moment où le
 * stock est impacté et où la transmission PDP peut se déclencher -- avant
 * l'émission, une facture brouillon n'a aucune existence légale ni
 * commerciale.
 *
 * Le type du client écrit sur le brouillon (voir FigeLeClient) choisit la
 * série : les tickets de caisse pour un particulier, les factures pour un
 * professionnel (voir Facture::serie()), deux suites numériques qui ne se
 * mélangent jamais, même si elles restent dans la même table (voir
 * FacturePdfGenerator pour le libellé affiché). Si la fiche du client a
 * changé de type depuis l'enregistrement du brouillon, l'émission est
 * refusée : le brouillon doit être réenregistré pour son nouveau type, qui
 * décide aussi de son calcul (voir
 * ContexteDeVente::refuserSiLeTypeDeClientAChange()). De même si le
 * magasin est entré en franchise en base de TVA, ou en est sorti, depuis :
 * ses lignes doivent être recalculées, à 0 % ou à leurs taux (voir
 * ContexteDeVente::refuserSiLeRegimeAChange()).
 *
 * Un magasin dont les réglages communs à sa société (régime de TVA, n° de
 * TVA, raison sociale...) ne sont pas ceux du magasin principal de son
 * SIREN n'émet ni facture ni ticket (K5, voir
 * ReglagesCommunsDeLaSociete::verifierQuIlEstAligne()) : le refus dit
 * quel réglage diffère et quelle valeur prendre.
 *
 * Une facture à un professionnel exige en plus l'identité légale complète
 * du magasin (B11, voir Magasin::verifierSonIdentiteLegale()) et, pour un
 * client établi en France, son SIREN : sinon elle reste un brouillon,
 * sans numéro consommé ni signature. Les tickets restent possibles.
 *
 * Tout se fait dans une seule transaction, dans cet ordre (art. 286 I-3°
 * bis du CGI) :
 *  1. le registre fiscal du magasin actif est verrouillé, avant toute
 *     lecture, la facture et la numérotation (voir RegistreFiscal) ; il
 *     donne l'instant de l'émission ;
 *  2. la facture, relue verrouillée, prend la copie de son client (nom,
 *     adresse, identifiants), son numéro, dans la série et l'année de cet
 *     instant à l'heure de Paris, cet instant, en UTC, pour date
 *     d'émission (voir HorodateSonEmission), et son échéance : le jour de
 *     cet instant à Paris plus le délai de paiement du client, sinon celui
 *     du magasin (B7, voir Magasin::delaiDePaiementPour()), jamais dans le
 *     passé : un brouillon n'en a pas. Un ticket n'est plus émis une fois
 *     sa journée clôturée, jusqu'à minuit (voir
 *     ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()) ;
 *  3. FactureEmise sort le stock, applique le crédit d'avoir du client et
 *     prépare la transmission PDP ;
 *  4. le règlement comptant d'une vente au comptoir, s'il y en a un, paie
 *     ce qui reste dû (voir EnregistrerPaiementAction, signé dans la
 *     chaîne des encaissements) ;
 *  5. le document, tel qu'il est alors en base, est signé dans la chaîne
 *     des ventes (voir ContenuFiscal) : client figé, règlement et crédit
 *     compris.
 * Sans clé de signature, ou si l'horloge du serveur a reculé, rien de tout
 * cela n'a lieu : la facture reste un brouillon.
 */
class EmettreFactureAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly RegistreFiscal $registre,
        private readonly ContenuFiscal $contenuFiscal,
        private readonly EnregistrerPaiementAction $enregistrerPaiement,
        private readonly ReglagesCommunsDeLaSociete $reglagesCommuns,
    ) {}

    /**
     * @param  ReglementComptant|null  $reglement  le règlement d'une vente au comptoir, encaissé à l'émission
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(Facture $facture, ?ReglementComptant $reglement = null): Facture
    {
        return DB::transaction(function () use ($facture, $reglement) {
            $instant = $this->registre->verrouiller(app(TenantContext::class)->magasin());
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            if (! $facture->statut->peutTransitionnerVers(StatutFacture::Emise)) {
                throw new LogicException("Impossible d'émettre une facture au statut [{$facture->statut->value}].");
            }

            if ($facture->lignes()->doesntExist()) {
                throw new LogicException('Impossible d\'émettre une facture sans lignes.');
            }

            ContexteDeVente::refuserSiLeTypeDeClientAChange($facture);
            ContexteDeVente::refuserSiLeRegimeAChange($facture);
            $this->reglagesCommuns->verifierQuIlEstAligne($facture->magasin);

            if ($facture->estPourUnProfessionnel()) {
                $this->refuserUneFactureProfessionnelleIncomplete($facture);
            }

            $typeDocument = $facture->serie();

            if ($typeDocument === TypeDocument::TicketCaisse) {
                ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee($facture->magasin, $instant);
            }

            $numero = $this->numerotation->suivant($facture->magasin, $typeDocument, $instant->enHeureLocale()->format('Y'));

            $facture->figerLeClient();
            $facture->forceFill([
                'numero' => $numero,
                'statut' => StatutFacture::Emise,
                'date_emission' => $instant->utc,
                'date_echeance' => $instant->enHeureLocale()->addDays($facture->magasin->delaiDePaiementPour($facture->client))->toDateString(),
            ])->save();

            // Synchrone et dans la même transaction : voir le commentaire
            // de FactureEmise sur pourquoi ce event n'est pas différé.
            FactureEmise::dispatch($facture);

            if ($reglement !== null) {
                $this->reglerComptant($facture->fresh(), $reglement, $instant->enHeureLocale()->toDateString());
            }

            $facture = $facture->fresh();
            $contenu = $this->contenuFiscal->document($facture);
            $this->registre->enregistrerVente(
                $typeDocument === TypeDocument::Facture ? 'facture' : 'ticket',
                $contenu['document']['totaux']['ttc'],
                $contenu,
                $facture,
            );

            return $facture;
        });
    }

    /**
     * Une facture à un professionnel imprime l'identité légale complète du
     * magasin et, pour un client établi en France, son SIREN (B12) : la
     * fiche du client, dont le type vient d'être contrôlé, est celle que
     * la facture copie à son émission.
     *
     * @throws LogicException
     */
    private function refuserUneFactureProfessionnelleIncomplete(Facture $facture): void
    {
        $facture->magasin->verifierSonIdentiteLegale();

        if ($facture->client->estEtabliEnFrance() && blank($facture->client->siren)) {
            throw new LogicException('Renseignez le SIREN de ce client avant d\'émettre sa facture.');
        }
    }

    /**
     * Une vente au comptoir est soldée dès son émission : ce qui reste dû
     * après le crédit d'avoir du client est encaissé, et une vente que ce
     * crédit couvre, ou qui ne coûte rien (remise de 100 %), est payée
     * sans encaissement.
     */
    private function reglerComptant(Facture $facture, ReglementComptant $reglement, string $recuLe): void
    {
        $montantRestant = $facture->montantRestant();

        if ($montantRestant <= 0) {
            $facture->constaterUnReglement();

            return;
        }

        $this->enregistrerPaiement->handle(
            $facture->client,
            [
                'mode_paiement' => $reglement->mode->value,
                'montant' => $montantRestant,
                'reference' => $reglement->reference,
                'recu_le' => $recuLe,
            ],
            [['facture_id' => $facture->id, 'montant' => $montantRestant]],
        );
    }
}
