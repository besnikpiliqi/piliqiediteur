<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\ContenuFiscal;
use App\Domain\CaisseSecurisee\Services\InstantFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\ControleNatureOperation;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Stock\Events\StockDevenuNegatif;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Exceptions\StockInsuffisantException;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Stock\Services\ManqueDeStock;
use App\Domain\Stock\Services\VerificateurDeStock;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
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
 * Une vente qui ferait passer sous zéro un produit qui suit son stock
 * n'est émise que par une personne autorisée à vendre sans stock dans ce
 * magasin, après l'avertissement qu'elle a confirmé (R1, voir
 * VerificateurDeStock) : sinon rien n'est numéroté ni signé. Confirmée,
 * elle s'inscrit au journal d'audit de la société, dans la chaîne du
 * magasin, et chaque produit devenu négatif est signalé (voir
 * StockDevenuNegatif).
 *
 * L'émission demandée par une personne porte un jeton (R3, voir
 * ActionsUniques) : répétée après une réponse perdue, elle rend la
 * facture déjà émise, sans second numéro. Une vente à la caisse l'appelle
 * sans jeton : la vente porte le sien.
 *
 * Tout se fait dans une seule transaction, dans cet ordre (art. 286 I-3°
 * bis du CGI) :
 *  1. le registre fiscal du magasin actif est verrouillé, avant toute
 *     lecture, la facture et la numérotation (voir RegistreFiscal) ; il
 *     donne l'instant de l'émission ; le jeton se cherche sous ce verrou ;
 *     le magasin, relu, doit pouvoir vendre ;
 *  2. l'auteur est relu sous le verrou de sa ligne (voir
 *     LecteurDesDroits::exigerSousVerrou()) : il doit pouvoir émettre
 *     cette facture, et, pour une vente au comptoir réglée à l'émission,
 *     enregistrer un paiement ; une autorisation retirée pendant que son
 *     écran restait ouvert est vue. Il n'encaisse que dans un magasin à la
 *     fois : sa caisse personnelle d'un autre magasin de la société doit
 *     être fermée (voir VerificateurCaissesPersonnelles). Le registre signe
 *     l'émission à son nom (voir OperateurCourant) ;
 *  3. la facture, relue verrouillée, devient la vente de qui l'émet si
 *     son auteur est devenu propriétaire de la société depuis la création
 *     du brouillon (voir reprendreLeBrouillonDuProprietaire()), puis prend
 *     la copie de son client (nom,
 *     adresse, identifiants), la vérification VIES que retient sa nature,
 *     son numéro, dans la série et l'année de cet
 *     instant à l'heure de Paris, cet instant, en UTC, pour date
 *     d'émission (voir HorodateSonEmission), et son échéance : le jour de
 *     cet instant à Paris plus le délai de paiement du client, sinon celui
 *     du magasin (B7, voir Magasin::delaiDePaiementPour()), jamais dans le
 *     passé : un brouillon n'en a pas. Un ticket n'est plus émis une fois
 *     sa journée clôturée, jusqu'à minuit (voir
 *     ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()). Ses
 *     produits se verrouillent, par identifiant croissant, et leur stock se
 *     contrôle avant la numérotation (voir VerificateurDeStock) ;
 *  4. FactureEmise sort le stock des produits déjà verrouillés, applique
 *     le crédit d'avoir du client et prépare la transmission PDP ;
 *  5. le règlement d'une vente à la caisse (voir ReglementComptant)
 *     impute ensuite les bons d'avoir présentés, dans l'ordre saisi, chacun
 *     pour son crédit restant, relu sous le verrou du registre, dans la
 *     limite de ce qui reste dû (voir imputerLesBonsDAvoir()) ; ses parts
 *     doivent égaler exactement ce qui reste alors à payer : sinon, parce
 *     que le crédit du client ou un bon a changé depuis l'écran, rien
 *     n'est enregistré et le refus dit ce qui reste à répartir, ou de
 *     combien les parts dépassent (« Le total à payer a changé (crédit
 *     d'avoir du client) : 20,00 € à répartir. »). Chaque part
 *     devient un paiement de son mode (voir EnregistrerPaiementAction,
 *     signé dans la chaîne des encaissements). Une facture dont il ne
 *     reste rien à payer, parce que ce crédit ou ces bons la couvrent ou
 *     qu'elle ne coûte rien (remise de 100 %), passe « payée » sans
 *     encaissement : elle n'attend aucun règlement et ne sera jamais en
 *     retard (G1) ;
 *  6. le document, tel qu'il est alors en base, est signé dans la chaîne
 *     des ventes (voir ContenuFiscal) : client figé, paiements de chaque
 *     part, crédit et bons d'avoir compris ;
 *  7. une vente sans stock s'inscrit au journal d'audit, dont la tête de
 *     la chaîne du magasin se verrouille en dernier ; puis le jeton.
 * Sans clé de signature, ou si l'horloge du serveur a reculé, rien de tout
 * cela n'a lieu : la facture reste un brouillon.
 */
class EmettreFactureAction
{
    public const TOTAL_A_PAYER_CHANGE = 'Le total à payer a changé (crédit d\'avoir du client) : %s à répartir.';

    public const TOTAL_A_PAYER_CHANGE_PARTS_EN_TROP = 'Le total à payer a changé (crédit d\'avoir du client) : les parts le dépassent de %s.';

    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly RegistreFiscal $registre,
        private readonly ContenuFiscal $contenuFiscal,
        private readonly EnregistrerPaiementAction $enregistrerPaiement,
        private readonly ControleNatureOperation $controleNatureOperation,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly VerificateurCaissesPersonnelles $caissesPersonnelles,
        private readonly VerificateurDeStock $stock,
        private readonly JournalAudit $journalAudit,
        private readonly ActionsUniques $actionsUniques,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @param  ReglementComptant|null  $reglement  le règlement d'une vente à la caisse, encaissé à l'émission
     * @param  ConfirmationVenteSansStock|null  $confirmation  l'avertissement d'une vente sans stock, confirmé (R1)
     * @param  string|null  $jeton  le jeton de la demande (R3), null quand une autre action l'appelle
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws StockInsuffisantException
     * @throws ConfirmationVenteSansStockRequiseException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Facture $facture, ?ReglementComptant $reglement = null, ?ConfirmationVenteSansStock $confirmation = null, ?string $jeton = null): Facture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::EMETTRE_FACTURE, $auteur, [
            'facture_id' => $facture->getKey(),
            'reglement' => $reglement?->enTableau(),
        ], fn () => $this->registre->verrouiller($magasin), fn (): Facture => DB::transaction(function () use ($auteur, $facture, $reglement, $confirmation, $magasin) {
            $instant = $this->registre->verrouiller($magasin);
            Magasin::query()->findOrFail($magasin->getKey())->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'emettre', $magasin, $facture);

            if ($reglement !== null) {
                $this->lecteur->exiger($compte, PermissionRegistry::PAIEMENT_CREER, $magasin);
            }

            $this->caissesPersonnelles->refuserSiSaCaisseEstOuverteAilleurs($compte, $magasin);

            return $this->operateur->pendant($compte, fn (): Facture => $this->emettre($compte, $facture, $reglement, $confirmation, $instant));
        }));
    }

    /**
     * @throws LogicException
     * @throws StockInsuffisantException
     * @throws ConfirmationVenteSansStockRequiseException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function emettre(User $compte, Facture $facture, ?ReglementComptant $reglement, ?ConfirmationVenteSansStock $confirmation, InstantFiscal $instant): Facture
    {
        $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

        if (! $facture->statut->peutTransitionnerVers(StatutFacture::Emise)) {
            throw new LogicException("Impossible d'émettre une facture au statut [{$facture->statut->value}].");
        }

        if ($facture->lignes()->doesntExist()) {
            throw new LogicException('Impossible d\'émettre une facture sans lignes.');
        }

        $this->reprendreLeBrouillonDuProprietaire($facture, $compte);
        ContexteDeVente::refuserSiLeTypeDeClientAChange($facture);
        ContexteDeVente::refuserSiLeRegimeAChange($facture);

        if ($facture->estPourUnProfessionnel()) {
            $this->refuserUneFactureProfessionnelleIncomplete($facture);
        }

        $typeDocument = $facture->serie();

        if ($typeDocument === TypeDocument::TicketCaisse) {
            ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee($facture->magasin, $instant);
        }

        $manques = $this->stock->verifier($compte, $facture, $confirmation);
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

        if ($reglement !== null) {
            $this->encaisserComptant($compte, $facture, $reglement, $instant->enHeureLocale()->toDateString());
        }

        $facture = $facture->fresh();

        if ($facture->resteDuEnCentimes() <= 0) {
            $facture->constaterUnReglement();
        }

        $facture = $facture->fresh();
        $contenu = $this->contenuFiscal->document($facture);
        $this->registre->enregistrerVente(
            $typeDocument === TypeDocument::Facture ? 'facture' : 'ticket',
            $contenu['document']['totaux']['ttc'],
            $contenu,
            $facture,
        );

        if ($manques !== []) {
            $this->journaliserLaVenteSansStock($compte, $facture, $manques);
        }

        return $facture;
    }

    /**
     * Un brouillon créé par un administrateur à qui la propriété de la
     * société a été transmise depuis (voir AccepterLaTransmissionDeLaProprieteAction)
     * devient la vente de qui l'émet : le propriétaire ne vend jamais et
     * n'a pas de caisse personnelle, et une vente est celle d'une personne
     * autorisée à vendre dans ce magasin à ce moment, ici la seule de la
     * transaction. La facture, son nom figé (voir FigeLeNomDeSesAuteurs),
     * son contenu signé, « Mes ventes » et la caisse personnelle désignent
     * donc qui l'émet ; la base refuse d'émettre une facture au nom du
     * propriétaire (voir GardesDeLaVente). Le rôle de l'auteur se lit sous
     * le verrou du registre, que la transmission de la propriété prend
     * aussi : il ne change pas pendant l'émission. Un brouillon d'un autre
     * compte garde son auteur.
     */
    private function reprendreLeBrouillonDuProprietaire(Facture $facture, User $compte): void
    {
        if ($facture->cree_par !== null && User::query()->whereKey($facture->cree_par)->where('role_societe', RoleSociete::Proprietaire)->exists()) {
            $facture->forceFill(['cree_par' => $compte->getKey()]);
        }
    }

    /**
     * La vente sans stock, confirmée, s'inscrit au journal d'audit de la
     * société, dans la chaîne du magasin : son numéro et, pour chaque
     * produit qu'elle a fait passer sous zéro, sa référence, son stock
     * avant la vente et la quantité vendue. Chaque produit devenu négatif
     * est signalé une fois la vente validée (voir NotifierLeStockNegatif).
     *
     * @param  list<ManqueDeStock>  $manques
     */
    private function journaliserLaVenteSansStock(User $compte, Facture $facture, array $manques): void
    {
        $this->journalAudit->inscrire(ActionAuditee::VenteSansStock, $compte, $facture->magasin, null, [
            'vente' => $facture->numero,
            'produits' => array_map(fn (ManqueDeStock $manque): array => [
                'reference' => $manque->reference,
                'stock_avant' => $manque->stockActuel,
                'quantite' => $manque->quantiteVendue,
            ], $manques),
        ], $facture);

        foreach (Produit::withTrashed()->whereKey(array_map(fn (ManqueDeStock $manque): int => $manque->produitId, $manques))->orderBy('id')->get() as $produit) {
            StockDevenuNegatif::dispatch($produit, $facture->numero);
        }
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
     * Une vente à la caisse est soldée dès son émission : les bons d'avoir
     * présentés s'imputent sur ce que le crédit du client laisse dû, puis
     * chaque part paie son montant, dans son mode. Les parts doivent égaler
     * exactement ce qui reste à payer.
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function encaisserComptant(User $compte, Facture $facture, ReglementComptant $reglement, string $recuLe): void
    {
        $this->imputerLesBonsDAvoir($facture, $reglement->bonsDAvoir);
        $ecart = $reglement->totalDesParts() - $facture->fresh()->resteDuEnCentimes();

        if ($ecart !== 0) {
            throw new LogicException(sprintf($ecart < 0 ? self::TOTAL_A_PAYER_CHANGE : self::TOTAL_A_PAYER_CHANGE_PARTS_EN_TROP, $this->calculateur->enEuros(abs($ecart))));
        }

        foreach ($reglement->parts as $part) {
            $montant = $this->calculateur->enDecimal($part->montant);

            $this->enregistrerPaiement->handle(
                $compte,
                $facture->client,
                ['mode_paiement' => $part->mode->value, 'montant' => $montant, 'reference' => $part->reference, 'recu_le' => $recuLe],
                [['facture_id' => $facture->getKey(), 'montant' => $montant]],
            );
        }
    }

    /**
     * Les bons d'avoir présentés à la caisse imputent chacun son crédit
     * restant sur la facture, quel qu'en soit le client, dans l'ordre
     * saisi, au plus ce qu'en laissent dû le crédit du client, déjà imputé
     * (voir AppliquerAvoirsDisponiblesAction), et les bons qui le
     * précèdent : un avoir émis au client comptoir du magasin (voir
     * Avoir::scopeBonDAvoir()), relu verrouillé sous le verrou du
     * registre, dont le crédit n'est pas épuisé. Un bon qui ne trouve plus
     * rien à régler est refusé.
     *
     * @param  list<string>  $numeros
     *
     * @throws LogicException
     */
    private function imputerLesBonsDAvoir(Facture $facture, array $numeros): void
    {
        foreach ($numeros as $numero) {
            $bon = Avoir::query()->bonDAvoir($numero)->lockForUpdate()->first();

            if ($bon === null || $bon->creditEnCentimes() <= 0) {
                throw new LogicException(Avoir::BON_D_AVOIR_INTROUVABLE);
            }

            $montant = min($bon->creditEnCentimes(), $facture->fresh()->resteDuEnCentimes());

            if ($montant <= 0) {
                throw new LogicException(sprintf(Avoir::BON_D_AVOIR_SANS_EMPLOI, $bon->numero));
            }

            AvoirAllocation::create([
                'avoir_id' => $bon->getKey(),
                'facture_id' => $facture->getKey(),
                'montant_alloue' => $this->calculateur->enDecimal($montant),
            ]);
        }
    }
}
