<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Enums\MotifDeRegularisation;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\RegularisationDUneExoneration;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * « Régulariser l'exonération » d'un export hors de l'Union ou d'une
 * livraison vers un DOM dont la preuve de sortie manque 150 jours après sa
 * date (T29.3, voir RegularisationDUneExoneration) : son avoir de
 * régularisation puis sa facture rectificative à la TVA française, au même
 * prix TTC, d'un seul geste.
 *
 * Le geste d'un compte autorisé à vendre dans ce magasin, qui crée et émet
 * la facture et fait l'avoir : un responsable, un vendeur, ou un
 * administrateur ou un observateur à qui la vente est cochée ; jamais le
 * propriétaire hors du magasin de démonstration, ni un compte d'un cabinet
 * lié ou un expert-comptable de la société, qui n'ont pas ces
 * autorisations (voir PermissionRegistry::deVente()).
 *
 * Tout se fait dans une seule transaction : le registre fiscal du magasin
 * actif se verrouille d'abord ; le magasin, relu, doit pouvoir vendre ;
 * l'auteur est relu sous le verrou de sa ligne et doit pouvoir créer une
 * facture, faire un avoir et voir la vente ; la vente, relue dans le
 * magasin actif, doit se régulariser ce jour (voir
 * RegularisationDUneExoneration::refus()) : deux demandes simultanées
 * passent l'une après l'autre, et la seconde est refusée. L'avoir crédite
 * tout ce qui reste à créditer de chaque ligne, motif ExonerationNonProuvee
 * (voir CreerAvoirAction), puis s'émet (voir EmettreAvoirAction) : sans
 * retour en stock, signé avec la mention de la preuve non reçue, son
 * crédit réglant ce qui reste dû de la vente. La facture rectificative
 * naît alors au nom de l'auteur, vente en France, avec le client, la
 * livraison et les conditions de paiement de la vente et les lignes à la
 * TVA française (voir RegularisationDUneExoneration::lignesALaTvaFrancaise()),
 * et s'émet aussitôt (voir EmettreFactureAction) : sans sortie de stock,
 * avec la copie du client que la vente a figée et la mention de la vente
 * qu'elle rectifie, le crédit encore réservé de l'avoir la réglant avant
 * tout autre (voir AppliquerAvoirsDisponiblesAction). Le geste s'inscrit
 * enfin au journal d'audit, dans la chaîne du magasin. Sans clé de
 * signature, rien de tout cela n'a lieu.
 *
 * La demande porte un jeton (R3, voir ActionsUniques), cherché sous le
 * verrou du registre : répétée après une réponse perdue, elle rend la
 * facture rectificative déjà émise. Les émissions qu'elle compose n'en
 * consomment aucun.
 */
class RegulariserUneExonerationNonProuveeAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CreerAvoirAction $creerAvoir,
        private readonly EmettreAvoirAction $emettreAvoir,
        private readonly EmettreFactureAction $emettreFacture,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly ActionsUniques $actionsUniques,
        private readonly RegularisationDUneExoneration $exoneration,
        private readonly RegularisationDUneFacture $regularisation,
        private readonly JournalAudit $journalAudit,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @param  string|null  $jeton  le jeton de la demande (R3)
     * @return Facture la facture rectificative, à la TVA française
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Facture $facture, ?string $jeton = null): Facture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::REGULARISER_UNE_EXONERATION, Facture::class, $auteur, ['facture_id' => $facture->getKey()], fn () => $this->registre->verrouiller($magasin), fn (): Facture => DB::transaction(function () use ($auteur, $facture, $magasin): Facture {
            $this->registre->verrouiller($magasin);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Facture::class);
            $this->lecteur->exiger($compte, 'create', $magasin, Avoir::class);
            $origine = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->first() ?? throw new LogicException(Facture::REFUS_INTROUVABLE);
            $this->lecteur->exiger($compte, 'view', $magasin, $origine);
            $refus = $this->exoneration->refus($origine, $magasin->calendrier()->aujourdhui());

            if ($refus !== null) {
                throw new LogicException($refus);
            }

            $avoir = $this->emettreAvoir->handle($compte, $this->creerAvoir->handle(
                $compte,
                ValeurRequise::de($origine->client, 'le client de la vente régularisée'),
                $this->regularisation->lignesDeLAvoir($origine),
                sprintf(RegularisationDUneExoneration::MOTIF_DE_L_AVOIR, $origine->estPourUnProfessionnel() ? 'la facture' : 'le ticket', $origine->numero),
                regularisation: MotifDeRegularisation::ExonerationNonProuvee,
            ));
            $brouillon = $this->operateur->pendant($compte, fn (): Facture => $this->creerLeBrouillon($compte, $origine));
            $rectificative = $this->emettreFacture->handle($compte, $brouillon);

            $this->journalAudit->inscrire(ActionAuditee::ExonerationNonProuveeRegularisee, $compte, $magasin, null, [
                'facture' => $origine->numero,
                'avoir' => $avoir->numero,
                'facture_rectificative' => $rectificative->numero,
                'total_ttc' => $this->calculateur->enCentiemes($rectificative->total_ttc),
                'total_tva' => $this->calculateur->enCentiemes($rectificative->total_tva),
            ], $rectificative);

            return $rectificative;
        }));
    }

    /**
     * Le brouillon de la facture rectificative, au nom de l'auteur : une
     * vente en France, avec ce que la vente d'origine a noté (client, type
     * du client, livraison, conditions de paiement) et ses lignes à la TVA
     * française, et leurs totaux. Son PU HT, sur une facture à un
     * professionnel, et ses totaux s'impriment tels qu'ils sont enregistrés.
     */
    private function creerLeBrouillon(User $compte, Facture $origine): Facture
    {
        $lignes = $this->exoneration->lignesALaTvaFrancaise($origine);
        $brouillon = new Facture([
            'client_id' => $origine->client_id,
            'devise' => $origine->devise,
            'conditions_paiement' => $origine->conditions_paiement,
        ]);
        $brouillon->forceFill([
            'cree_par' => $compte->getKey(),
            'facture_rectifiee_id' => $origine->getKey(),
            'type_client' => $origine->type_client,
            'franchise_en_base' => $origine->franchise_en_base,
            'nature_operation' => NatureOperation::VenteEnFrance,
            'livraison_adresse' => $origine->livraison_adresse,
            'livraison_complement' => $origine->livraison_complement,
            'livraison_code_postal' => $origine->livraison_code_postal,
            'livraison_ville' => $origine->livraison_ville,
            'livraison_pays' => $origine->livraison_pays,
            'livraison_date' => $origine->livraison_date ?? $origine->magasin->calendrier()->jourDe(ValeurRequise::de($origine->date_emission, 'la date d\'émission de la vente régularisée')),
            'transport_par' => $origine->transport_par,
            ...$this->calculateur->enDecimaux($this->calculateur->totaliser($lignes)),
        ])->save();

        foreach ($lignes as $ligne) {
            $brouillon->lignes()->create($ligne);
        }

        return ModeleRelu::de($brouillon, 'lignes');
    }
}
