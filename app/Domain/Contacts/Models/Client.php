<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToMagasin, HasFactory, SoftDeletes;

    /**
     * Nom du client générique créé pour chaque magasin (voir
     * CreerMagasinAction) : une seule fiche partagée par toutes les ventes
     * au comptoir anonymes, jamais une personne identifiée.
     */
    public const NOM_COMPTOIR = 'Client comptoir';

    // magasin_id est volontairement absent : il est toujours dérivé du
    // TenantContext par le trait BelongsToMagasin, jamais du payload client.
    protected $fillable = [
        'nom',
        'type_client',
        'siret',
        'numero_tva',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'telephone',
        'email',
        'delai_paiement_jours',
        'mode_paiement_prefere',
        'notes',
        'actif',
    ];

    protected $casts = [
        'type_client' => TypeClient::class,
        'actif' => 'boolean',
    ];

    public function estProfessionnel(): bool
    {
        return $this->type_client === TypeClient::Professionnel;
    }

    public function estComptoir(): bool
    {
        return $this->nom === self::NOM_COMPTOIR;
    }

    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class);
    }

    /**
     * Crédit encore disponible pour ce client, tous avoirs confondus
     * (particulier ou professionnel : aucune intégration bancaire pour
     * rembourser par virement/espèces, voir AppliquerAvoirsDisponiblesAction
     * -- un avoir émis reste donc un crédit à appliquer sur une prochaine
     * facture jusqu'à épuisement).
     *
     * Exception volontaire : le client comptoir générique (voir
     * estComptoir()) n'a jamais de solde créditeur. Il est partagé par
     * toutes les ventes anonymes du magasin -- un avoir émis "pour lui"
     * n'a aucun client identifiable à qui l'appliquer plus tard, et
     * l'appliquer au hasard sur la prochaine vente comptoir reviendrait à
     * donner le remboursement d'une personne à une autre.
     */
    public function soldeCrediteur(): float
    {
        if ($this->estComptoir()) {
            return 0.0;
        }

        return app(CalculateurLigne::class)->sommer(
            $this->avoirs()
                ->where('statut', StatutAvoir::Emise)
                ->get()
                ->map(fn (Avoir $avoir) => $avoir->montantNonAlloue()),
        );
    }
}
