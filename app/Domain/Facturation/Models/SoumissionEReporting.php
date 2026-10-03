<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Integrations\Pdp\Models\TransmissionPdp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Agrège toutes les clôtures de caisse (voir ClotureCaisse) d'une période
 * d'e-reporting (décade, mois ou bimestre selon le régime de TVA du
 * magasin, voir CalculateurPeriodeEReporting) en UN SEUL envoi au PDP :
 * contrairement à la clôture quotidienne, la transmission d'e-reporting
 * n'est PAS journalière (voir GenererSoumissionEReportingAction).
 *
 * PAS de SoftDeletes, même raisonnement que ClotureCaisse : document de
 * conformité fiscale, jamais supprimé en pratique.
 */
class SoumissionEReporting extends Model
{
    use BelongsToMagasin;

    protected $table = 'soumissions_ereporting';

    // numero, totaux, generee_le : jamais en mass assignment, uniquement
    // via GenererSoumissionEReportingAction.
    protected $fillable = [
        'periode_debut',
        'periode_fin',
        'date_limite_transmission',
    ];

    protected $casts = [
        'periode_debut' => 'date',
        'periode_fin' => 'date',
        'date_limite_transmission' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'generee_le' => 'datetime',
    ];

    public function clotures(): HasMany
    {
        // Clé étrangère explicite, même raisonnement que
        // ClotureCaisse::soumissionEReporting() : la convention par défaut
        // ("soumission_e_reporting_id") ne correspond pas à la colonne
        // réelle de la migration.
        return $this->hasMany(ClotureCaisse::class, 'soumission_ereporting_id');
    }

    public function transmissionsPdp(): MorphMany
    {
        return $this->morphMany(TransmissionPdp::class, 'transmissible');
    }
}
