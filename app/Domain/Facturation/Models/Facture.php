<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Integrations\Pdp\Models\TransmissionPdp;
use App\Models\User;
use Database\Factories\FactureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Facture extends Model
{
    /** @use HasFactory<FactureFactory> */
    use BelongsToMagasin, HasFactory, SoftDeletes;

    protected $table = 'factures';

    // numero, statut, date_emission, totaux, montant_paye : jamais en
    // mass assignment, uniquement via les Actions dédiées (voir
    // app/Domain/Facturation/Actions).
    protected $fillable = [
        'client_id',
        'devis_id',
        'date_echeance',
        'devise',
        'conditions_paiement',
        'notes',
    ];

    protected $casts = [
        'statut' => StatutFacture::class,
        'date_emission' => 'datetime',
        'date_echeance' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'montant_paye' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Facture $facture) {
            $facture->statut ??= StatutFacture::Brouillon;
            $facture->devise ??= 'EUR';
            // Jamais fourni par le payload : c'est toujours l'auteur réel
            // de la requête, pour que la restriction de visibilité par
            // vendeur (voir FacturePolicy) ne puisse pas être usurpée.
            $facture->cree_par ??= auth()->id();
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function devisOrigine(): BelongsTo
    {
        return $this->belongsTo(Devis::class, 'devis_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneFacture::class)->orderBy('ordre');
    }

    public function transmissionsPdp(): MorphMany
    {
        return $this->morphMany(TransmissionPdp::class, 'transmissible');
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutFacture::Brouillon;
    }

    public function montantRestant(): float
    {
        return round((float) $this->total_ttc - (float) $this->montant_paye, 2);
    }

    /**
     * Détermine le circuit réglementaire applicable : une facture à un
     * client professionnel relève de l'e-invoicing (transmission PDP
     * individuelle) ; un particulier relève de l'e-reporting agrégé via
     * la clôture de caisse journalière (non transmise facture par facture).
     */
    public function relevantDeLeInvoicing(): bool
    {
        return $this->client->estProfessionnel();
    }
}
