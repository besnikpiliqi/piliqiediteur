<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\ControleNatureOperation;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Models\Magasin;
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
 * ContexteDeVente::refuserSiLeTypeDeClientAChange()). De même si la
 * société est entrée en franchise en base de TVA, ou en est sortie,
 * depuis : ses lignes doivent être recalculées, à 0 % ou à leurs taux
 * (voir ContexteDeVente::refuserSiLeRegimeAChange()), le régime étant
 * relu sur la société après le verrou du registre, sous lequel il change
 * (voir JournaliseSesParametresDeConformite).
 *
 * Un magasin n'émet ni facture ni ticket tant qu'il ne peut pas vendre
 * (voir Magasin::verifierQuIlPeutVendre()) : société en cours de
 * vérification ou refusée, établissement pas encore confirmé par Sirene,
 * lecture seule. Le contrôle se fait après le verrou du registre, sur le
 * magasin et la société relus en base.
 *
 * Une facture à un professionnel exige en plus l'identité légale complète
 * de la société et du magasin (B11, voir
 * Magasin::verifierSonIdentiteLegale()) et, pour un
 * client établi en France, son SIREN : sinon elle reste un brouillon,
 * sans numéro consommé ni signature. Les tickets restent possibles.
 *
 * Une vente hors de la TVA française (export, livraison vers un DOM,
 * livraison intracommunautaire, prestation à un professionnel établi hors
 * de France) ne s'émet que si toutes les conditions de sa nature sont
 * réunies ce jour-là (I1, I2, voir ControleNatureOperation) ; elle retient
 * alors, s'il le faut, la vérification sur VIES du n° de TVA de son client
 * qui la justifie.
 *
 * Tout se fait dans une seule transaction, dans cet ordre (art. 286 I-3°
 * bis du CGI) :
 *  1. le registre fiscal du magasin actif est verrouillé, avant toute
 *     lecture, la facture et la numérotation (voir RegistreFiscal) ; il
 *     donne l'instant de l'émission ; le magasin, relu, doit pouvoir
 *     vendre ;
 *  2. la facture, relue verrouillée, prend la copie de son client (nom,
 *     adresse, identifiants), la vérification VIES que retient sa nature,
 *     son numéro, dans la série et l'année de cet
 *     instant à l'heure de Paris, cet instant, en UTC, pour date
 *     d'émission (voir HorodateSonEmission), et son échéance : le jour de
 *     cet instant à Paris plus le délai de paiement du client, sinon celui
 *     du magasin (B7, voir Magasin::delaiDePaiementPour()), jamais dans le
 *     passé : un brouillon n'en a pas. Un ticket n'est plus émis une fois
 *     sa journée clôturée, jusqu'à minuit (voir
 *     ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()) ;
 *  3. FactureEmise sort le stock, applique le crédit d'avoir du client et
 *     prépare la transmission PDP ;
 *  4. le règlement comptant d'une vente au comptoir impute d'abord le
 *     bon d'avoir présenté, s'il y en a un, sur ce que le crédit du client
 *     laisse dû (voir imputerLeBonDAvoir()) ;
 *     une facture dont il ne reste alors rien à payer, parce que ce crédit
 *     ou ce bon la couvre ou qu'elle ne coûte rien (remise de 100 %), passe
 *     « payée » sans encaissement : elle n'attend aucun règlement et ne
 *     sera jamais en retard (G1) ; sinon ce règlement, s'il y en a un, paie
 *     ce qui reste dû (voir EnregistrerPaiementAction, signé dans la
 *     chaîne des encaissements) ;
 *  5. le document, tel qu'il est alors en base, est signé dans la chaîne
 *     des ventes (voir ContenuFiscal) : client figé, règlement, crédit et
 *     bon d'avoir compris.
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
        private readonly ControleNatureOperation $controleNatureOperation,
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
            Magasin::query()->findOrFail(app(TenantContext::class)->id())->verifierQuIlPeutVendre();
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            if (! $facture->statut->peutTransitionnerVers(StatutFacture::Emise)) {
                throw new LogicException("Impossible d'émettre une facture au statut [{$facture->statut->value}].");
            }

            if ($facture->lignes()->doesntExist()) {
                throw new LogicException('Impossible d\'émettre une facture sans lignes.');
            }

            ContexteDeVente::refuserSiLeTypeDeClientAChange($facture);
            ContexteDeVente::refuserSiLeRegimeAChange($facture);

            if ($facture->estPourUnProfessionnel()) {
                $this->refuserUneFactureProfessionnelleIncomplete($facture);
            }

            $typeDocument = $facture->serie();

            if ($typeDocument === TypeDocument::TicketCaisse) {
                ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee($facture->magasin, $instant);
            }

            $consultationVies = $this->controleNatureOperation->verifier($facture, $instant->enHeureLocale());
            $numero = $this->numerotation->suivant($facture->magasin, $typeDocument, $instant->enHeureLocale()->format('Y'));

            $facture->figerLeClient();
            $facture->forceFill([
                'numero' => $numero,
                'statut' => StatutFacture::Emise,
                'consultation_vies_id' => $consultationVies?->getKey(),
                'date_emission' => $instant->utc,
                'date_echeance' => $instant->enHeureLocale()->addDays($facture->magasin->delaiDePaiementPour($facture->client))->toDateString(),
            ])->save();

            // Synchrone et dans la même transaction : voir le commentaire
            // de FactureEmise sur pourquoi ce event n'est pas différé.
            FactureEmise::dispatch($facture);

            $facture = $facture->fresh();

            if ($reglement?->bonDAvoir !== null && $facture->montantRestant() > 0) {
                $this->imputerLeBonDAvoir($facture, $reglement->bonDAvoir);
            }

            if ($facture->montantRestant() <= 0) {
                $facture->constaterUnReglement();
            } elseif ($reglement !== null) {
                $this->encaisserComptant($facture, $reglement, $instant->enHeureLocale()->toDateString());
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
     * Une facture à un professionnel imprime l'identité légale complète de
     * la société et du magasin et, pour un client établi en France, son SIREN (B12) : la
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
     * Le bon d'avoir présenté au comptoir (voir EnregistrerVenteCaisseAction)
     * impute son crédit restant sur la facture, quel qu'en soit le client,
     * au plus ce qu'en laisse dû le crédit du client, déjà imputé (voir
     * AppliquerAvoirsDisponiblesAction) : un avoir du client comptoir du
     * magasin de la facture, dont le crédit, relu sous le verrou du
     * registre, n'est pas épuisé.
     *
     * @throws LogicException
     */
    private function imputerLeBonDAvoir(Facture $facture, Avoir $bon): void
    {
        $montant = min($bon->montantNonAlloue(), $facture->montantRestant());

        if ((int) $bon->magasin_id !== (int) $facture->magasin_id || ! ($bon->client?->estComptoir() ?? false) || $montant <= 0) {
            throw new LogicException(Avoir::BON_D_AVOIR_INTROUVABLE);
        }

        AvoirAllocation::create([
            'avoir_id' => $bon->id,
            'facture_id' => $facture->id,
            'montant_alloue' => $montant,
        ]);
    }

    /**
     * Une vente au comptoir est soldée dès son émission : ce qui reste dû
     * après le crédit d'avoir du client et le bon d'avoir présenté est
     * encaissé.
     */
    private function encaisserComptant(Facture $facture, ReglementComptant $reglement, string $recuLe): void
    {
        $montantRestant = $facture->montantRestant();

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
