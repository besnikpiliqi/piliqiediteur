<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Achats\Models\LigneAvoirFournisseur;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreAvoirAction côté vente : l'avoir fournisseur ne
 * reçoit son numéro qu'à l'émission, moment où le stock est décrémenté
 * (voir AvoirFournisseurEmis/DecrementerStockApresEmissionAvoirFournisseur).
 * L'instant de l'émission se prend et s'enregistre en UTC (voir
 * AvoirFournisseur::casts()) : l'heure de Paris se répète la nuit du
 * passage à l'heure d'hiver, et les avoirs d'une facture d'achat se lisent
 * dans l'ordre de leur émission (voir FactureAchat::avoirsFournisseurEmis()).
 * Un magasin suspendu ou fermé n'en émet plus (voir
 * Magasin::verifierQuIlEstOuvert()) : sa ligne se relit sous un verrou
 * partagé, premier verrou de la transaction, et la fermeture du magasin,
 * qui la verrouille (voir FermerMagasinAction), passe donc tout entière
 * avant ou après l'émission.
 *
 * Il fait sortir du stock et recalcule le coût moyen, comme une
 * validation d'achat : son auteur est relu sous le verrou de sa ligne,
 * avant l'avoir et ses produits (voir LecteurDesDroits), et doit pouvoir
 * créer un avoir fournisseur. Ses produits se verrouillent, par
 * identifiant croissant, avant la numérotation, quand leur stock se relit
 * (voir AvoirFournisseur::verifierQueLeStockSuffit()). L'écran ne l'appelle
 * qu'avec la création de l'avoir, dans la même transaction (voir
 * EnregistrerAvoirFournisseurAction).
 *
 * L'avoir émis s'inscrit au journal d'audit de la société, dans la chaîne
 * du magasin, en dernier verrou (voir JournalAudit) : son numéro, celui de
 * la facture d'achat qu'il crédite, le n° de l'avoir du fournisseur, ses
 * totaux et, pour chaque ligne, la référence et la quantité retournées ;
 * ni le fournisseur ni le motif, qui restent sur l'avoir.
 */
class EmettreAvoirFournisseurAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly JournalAudit $journalAudit,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, AvoirFournisseur $avoirFournisseur): AvoirFournisseur
    {
        return DB::transaction(function () use ($auteur, $avoirFournisseur) {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, AvoirFournisseur::class);

            return $this->operateur->pendant($compte, fn (): AvoirFournisseur => $this->emettre($compte, $avoirFournisseur));
        });
    }

    /**
     * @throws LogicException
     */
    private function emettre(User $compte, AvoirFournisseur $avoirFournisseur): AvoirFournisseur
    {
        $avoirFournisseur = AvoirFournisseur::query()->whereKey($avoirFournisseur->getKey())->lockForUpdate()->firstOrFail();

        if (! $avoirFournisseur->statut->peutTransitionnerVers(StatutAvoirFournisseur::Emise)) {
            throw new LogicException("Impossible d'émettre un avoir fournisseur au statut [{$avoirFournisseur->statut->value}].");
        }

        $this->arreterLesMontants($avoirFournisseur);
        $avoirFournisseur->verifierQueLeStockSuffit();

        $numero = $this->numerotation->suivant($avoirFournisseur->magasin, TypeDocument::AvoirFournisseur, now()->format('Y'));

        $avoirFournisseur->forceFill([
            'numero' => $numero,
            'statut' => StatutAvoirFournisseur::Emise,
            'date_emission' => CarbonImmutable::now('UTC'),
        ])->save();

        // Synchrone et dans la même transaction, voir le commentaire de
        // AvoirFournisseurEmis.
        AvoirFournisseurEmis::dispatch($avoirFournisseur);

        $this->journalAudit->inscrire(ActionAuditee::AvoirFournisseurEmis, $compte, $avoirFournisseur->magasin, null, [
            'avoir' => $avoirFournisseur->numero,
            'facture_achat' => $avoirFournisseur->factureAchat->numero,
            'numero_fournisseur' => $avoirFournisseur->numero_fournisseur,
            'total_ht' => $avoirFournisseur->total_ht,
            'total_tva' => $avoirFournisseur->total_tva,
            'total_ttc' => $avoirFournisseur->total_ttc,
            'lignes' => $avoirFournisseur->lignes->map(fn (LigneAvoirFournisseur $ligne): array => [
                'reference' => $ligne->reference,
                'quantite' => $ligne->quantite,
            ])->values()->all(),
        ], $avoirFournisseur);

        return $avoirFournisseur;
    }

    /**
     * Un brouillon n'a encore aucune existence : entre sa création et son
     * émission, un autre avoir fournisseur a pu retourner les mêmes lignes,
     * ou le stock a pu être vendu. Ses quantités sont donc revérifiées et
     * ses montants arrêtés au moment de l'émission, sur le cumul des retours
     * déjà émis (voir LigneAchat::montantsDuRetour()).
     *
     * @throws LogicException
     */
    private function arreterLesMontants(AvoirFournisseur $avoirFournisseur): void
    {
        $lignesArretees = [];

        foreach ($avoirFournisseur->lignes as $ligneAvoir) {
            $ligneAchat = LigneAchat::query()
                ->with('produit')
                ->whereKey($ligneAvoir->ligne_achat_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restante = $ligneAchat->quantiteRetournableRestante();

            if ($ligneAvoir->quantite > $restante) {
                throw new LogicException("La ligne \"{$ligneAchat->description}\" a été retournée ou vendue entre-temps : il ne reste que {$restante} article(s) à retourner.");
            }

            $ligneAvoir->forceFill($ligneAchat->montantsDuRetour($ligneAvoir->quantite))->save();
            $lignesArretees[] = $ligneAvoir->only(['montant_ht', 'montant_tva', 'montant_ttc']);
        }

        $avoirFournisseur->forceFill($this->calculateur->totaliser($lignesArretees));
    }
}
