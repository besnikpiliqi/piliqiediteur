<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
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
     */
    public function soldeCrediteur(): float
    {
        return round(
            $this->avoirs()
                ->where('statut', StatutAvoir::Emise)
                ->get()
                ->sum(fn (Avoir $avoir) => $avoir->montantNonAlloue()),
            2,
        );
    }
}
