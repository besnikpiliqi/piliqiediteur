<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Copie les montants enregistrés sur les lignes du devis accepté, au
 * moment de la conversion, vers une nouvelle facture BROUILLON (jamais
 * émise automatiquement : l'émission reste une étape manuelle distincte,
 * voir EmettreFactureAction) -- aucun recalcul de prix, comme
 * CreerAvoirAction reprend les lignes de la facture plutôt que de les
 * reconstruire via ConstructeurLigneDocument. La facture prend aussi le
 * type de client du devis, sa franchise de TVA, sa nature d'opération et
 * sa livraison : si le client a changé de type depuis, ou le magasin de
 * régime, l'émission le signale, et elle contrôle les conditions de la
 * nature (voir EmettreFactureAction). Converti, le devis n'est plus
 * modifiable (voir Devis::estModifiable()).
 *
 * Dans une transaction, la ligne du magasin se relit sous un verrou
 * partagé (un magasin suspendu, fermé ou cédé, ou dont la société est en
 * lecture seule, ne crée plus de facture, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur sous le verrou de sa
 * ligne (voir LecteurDesDroits), puis le devis sous le sien : une
 * fermeture du magasin, qui verrouille cette ligne (voir
 * FermerMagasinAction), passe tout entière avant ou après la conversion,
 * et une seconde conversion simultanée trouve le devis déjà converti.
 * L'auteur relu doit pouvoir convertir le devis relu (voir
 * DevisPolicy::convertir()) : c'est lui que la facture enregistre comme
 * auteur, et la conversion se fait à son nom (voir OperateurCourant).
 */
class CreerFactureDepuisDevisAction
{
    public function __construct(
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Devis $devis): Facture
    {
        return DB::transaction(function () use ($auteur, $devis) {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Facture::class);

            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(Devis::REFUS_INTROUVABLE);

            if ($devis->statut !== StatutDevis::Accepte) {
                throw new LogicException("Seul un devis accepté peut être converti en facture (statut actuel : [{$devis->statut->value}]).");
            }

            if ($devis->factures()->exists()) {
                throw new LogicException('Ce devis a déjà été converti en facture.');
            }

            $this->lecteur->exiger($compte, 'convertir', $magasin, $devis);

            return $this->operateur->pendant($compte, fn (): Facture => $this->convertir($compte, $devis));
        });
    }

    private function convertir(User $compte, Devis $devis): Facture
    {
        $facture = new Facture([
            'devise' => $devis->devise,
            'conditions_paiement' => $devis->conditions_paiement,
        ]);
        $facture->forceFill([
            'client_id' => $devis->client_id,
            'devis_id' => $devis->id,
            'cree_par' => $compte->getKey(),
            'type_client' => $devis->type_client,
            'franchise_en_base' => $devis->franchise_en_base,
            ...$devis->only(['nature_operation', 'livraison_adresse', 'livraison_complement', 'livraison_code_postal', 'livraison_ville', 'livraison_pays', 'livraison_date', 'transport_par']),
        ])->save();

        foreach ($devis->lignes as $ligne) {
            $facture->lignes()->create([
                'produit_id' => $ligne->produit_id,
                'service_id' => $ligne->service_id,
                'nature' => $ligne->nature,
                'description' => $ligne->description,
                'reference' => $ligne->reference,
                'quantite' => $ligne->quantite,
                'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                'prix_unitaire_ttc' => $ligne->prix_unitaire_ttc,
                'taux_tva' => $ligne->taux_tva,
                'taux_tva_du_prix' => $ligne->taux_tva_du_prix,
                'remise_pourcentage' => $ligne->remise_pourcentage,
                'montant_ht' => $ligne->montant_ht,
                'montant_tva' => $ligne->montant_tva,
                'montant_ttc' => $ligne->montant_ttc,
                'ordre' => $ligne->ordre,
            ]);
        }

        $facture->forceFill([
            'total_ht' => $devis->total_ht,
            'total_tva' => $devis->total_tva,
            'total_ttc' => $devis->total_ttc,
        ])->save();

        return $facture->fresh('lignes');
    }
}
