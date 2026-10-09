<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un paiement reçu, signé à son enregistrement dans la chaîne des
 * encaissements du registre fiscal (voir EnregistrerPaiementAction) :
 * jamais modifié ni supprimé, ni lui ni ses imputations (voir
 * ProtegeLesDonneesValidees et les déclencheurs de la table).
 */
class Paiement extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'paiements';

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

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Un paiement enregistré ne se modifie ni ne se supprime.';
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
}
