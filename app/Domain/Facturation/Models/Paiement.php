<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\PaiementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paiement extends Model
{
    /** @use HasFactory<PaiementFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'paiements';

    // montant n'est jamais modifié après coup : un paiement mal saisi se
    // corrige en ajustant ses allocations, jamais en réécrivant le
    // montant encaissé (voir EnregistrerPaiementAction).
    protected $fillable = [
        'client_id',
        'mode_paiement',
        'montant',
        'reference',
        'notes',
        'recu_le',
    ];

    protected $casts = [
        'mode_paiement' => ModePaiement::class,
        'montant' => 'decimal:2',
        'recu_le' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (Paiement $paiement) {
            $paiement->cree_par ??= auth()->id();
        });
    }

    /**
     * Un paiement garde son client, même archivé depuis (voir les
     * SoftDeletes de Client), comme les factures qu'il règle.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaiementAllocation::class);
    }

    public function montantAlloue(): float
    {
        return app(CalculateurLigne::class)->sommer($this->allocations()->pluck('montant_alloue'));
    }

    public function montantNonAlloue(): float
    {
        return app(CalculateurLigne::class)->soustraire($this->montant, $this->montantAlloue());
    }
}
