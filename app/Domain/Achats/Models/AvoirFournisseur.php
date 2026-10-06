<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\AvoirFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Document de retour au fournisseur, jamais modifiable ni supprimable une
 * fois émis (voir AvoirFournisseurPolicy). Référence une facture d'achat
 * SANS jamais la modifier (voir CreerAvoirFournisseurAction) : la facture
 * d'achat d'origine reste un document figé, l'avoir ne fait qu'y
 * référencer un retour.
 */
class AvoirFournisseur extends Model
{
    /** @use HasFactory<AvoirFournisseurFactory> */
    use BelongsToMagasin, HasFactory, VentileLaTvaParTaux;

    protected $table = 'avoirs_fournisseur';

    // numero, statut, date_emission, totaux : jamais en mass assignment,
    // uniquement via CreerAvoirFournisseurAction/EmettreAvoirFournisseurAction.
    protected $fillable = [
        'facture_achat_id',
        'motif',
        'devise',
        'source',
    ];

    protected $casts = [
        'statut' => StatutAvoirFournisseur::class,
        'source' => SourceAvoirFournisseur::class,
        'date_emission' => 'datetime',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (AvoirFournisseur $avoir) {
            $avoir->statut ??= StatutAvoirFournisseur::Brouillon;
            $avoir->devise ??= 'EUR';
            $avoir->cree_par ??= auth()->id();
        });
    }

    public function factureAchat(): BelongsTo
    {
        return $this->belongsTo(FactureAchat::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAvoirFournisseur::class)->orderBy('ordre');
    }
}
