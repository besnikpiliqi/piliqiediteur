<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La facture rectificative d'une facture que désigne l'examen du SIREN de
 * la société, après sa correction par l'éditeur (voir
 * RegularisationDUneFacture) : émise par un compte autorisé à créer et
 * émettre une facture dans ce magasin à ce moment (un responsable, un
 * vendeur, un administrateur ou un observateur à qui la vente est cochée),
 * jamais par le propriétaire, qui ne vend jamais, ni par un compte d'un
 * cabinet lié : ni l'un ni l'autre n'a ces autorisations (voir
 * PermissionRegistry::deVente()), et la base refuse une facture à leur nom
 * (voir GardesDeLaVente).
 *
 * Tout se fait dans une seule transaction : le registre fiscal du magasin
 * actif se verrouille d'abord ; le magasin, relu, doit pouvoir vendre ;
 * l'auteur est relu sous le verrou de sa ligne et doit pouvoir créer une
 * facture et voir celle qu'il rectifie ; la facture, relue dans le magasin
 * actif sous ce verrou, doit encore recevoir sa rectificative (voir
 * RegularisationDUneFacture::refusDeLaRectificative()) : deux demandes
 * simultanées passent l'une après l'autre, la seconde est refusée, et
 * l'index unique de la facture rectifiée en reste le dernier rempart. Le
 * brouillon naît au nom de l'auteur, avec le client, la nature
 * d'opération, la franchise de TVA, la livraison et les conditions de
 * paiement de la facture, sa date de livraison, ou à défaut le jour de
 * son émission, et ses lignes (voir
 * RegularisationDUneFacture::lignesDeLaRectificative()), que personne ne
 * modifie : il s'émet aussitôt (voir EmettreFactureAction), sous l'identité
 * actuelle de la société, sans sortie de stock (voir
 * DecrementerStockApresEmissionFacture), avec la copie du client que la
 * facture a figée et la mention qui la rectifie (voir MentionsFiscales) ;
 * le crédit encore réservé de son avoir de régularisation déjà émis la
 * règle avant tout autre (voir AppliquerAvoirsDisponiblesAction) ; émise
 * avant cet avoir, elle l'attend, sans rien recevoir d'ici là (voir
 * StatutFacture::EnAttenteDeRegularisation). Elle s'inscrit enfin au
 * journal d'audit, dans la chaîne du magasin.
 *
 * La demande porte un jeton (R3, voir ActionsUniques), cherché sous le
 * verrou du registre : répétée après une réponse perdue, elle rend la
 * facture rectificative déjà émise. L'émission qu'elle compose n'en
 * consomme aucun.
 */
class EmettreLaFactureRectificativeAction
{
    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly EmettreFactureAction $emettreFacture,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly ActionsUniques $actionsUniques,
        private readonly RegularisationDUneFacture $regularisation,
        private readonly JournalAudit $journalAudit,
        private readonly CalculateurLigne $calculateur,
    ) {}

    /**
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function handle(User $auteur, Facture $facture, ?string $jeton = null): Facture
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->actionsUniques->executer($jeton, ActionsUniques::EMETTRE_LA_FACTURE_RECTIFICATIVE, Facture::class, $auteur, ['facture_id' => $facture->getKey()], fn () => $this->registre->verrouiller($magasin), fn (): Facture => DB::transaction(function () use ($auteur, $facture, $magasin): Facture {
            $this->registre->verrouiller($magasin);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlPeutVendre();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Facture::class);
            $origine = Facture::query()->whereKey($facture->getKey())->first() ?? throw new LogicException(Facture::REFUS_INTROUVABLE);
            $this->lecteur->exiger($compte, 'view', $magasin, $origine);
            $refus = $this->regularisation->refusDeLaRectificative($origine);

            if ($refus !== null) {
                throw new LogicException($refus);
            }

            $brouillon = $this->operateur->pendant($compte, fn (): Facture => $this->creerLeBrouillon($compte, $origine));
            $rectificative = $this->emettreFacture->handle($compte, $brouillon);

            $this->journalAudit->inscrire(ActionAuditee::FactureRectificativeEmise, $compte, $magasin, null, [
                'facture' => $rectificative->numero,
                'facture_rectifiee' => $origine->numero,
                'total_ttc' => $this->calculateur->enCentiemes($rectificative->total_ttc),
            ], $rectificative);

            return $rectificative;
        }));
    }

    /**
     * Le brouillon de la facture rectificative, au nom de l'auteur, avec ce
     * que la facture d'origine a noté et ses lignes, et leurs totaux.
     */
    private function creerLeBrouillon(User $compte, Facture $origine): Facture
    {
        $lignes = $this->regularisation->lignesDeLaRectificative($origine);
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
            'nature_operation' => $origine->nature_operation,
            'livraison_adresse' => $origine->livraison_adresse,
            'livraison_complement' => $origine->livraison_complement,
            'livraison_code_postal' => $origine->livraison_code_postal,
            'livraison_ville' => $origine->livraison_ville,
            'livraison_pays' => $origine->livraison_pays,
            'livraison_date' => $origine->livraison_date ?? $origine->magasin->calendrier()->jourDe($origine->date_emission),
            'transport_par' => $origine->transport_par,
            ...$this->calculateur->totaliser($lignes),
        ])->save();

        foreach ($lignes as $ligne) {
            $brouillon->lignes()->create($ligne);
        }

        return $brouillon->fresh('lignes');
    }
}
