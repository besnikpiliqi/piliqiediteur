<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un paiement reçu, signé à son enregistrement dans la chaîne des
 * encaissements du registre fiscal (voir EnregistrerPaiementAction) :
 * jamais modifié ni supprimé, ni lui ni ses imputations (voir
 * ProtegeLesDonneesValidees et les déclencheurs de la table). Saisi par
 * erreur, il se corrige par sa contre-passation, un paiement opposé qui
 * le désigne (voir ContrePasserPaiementAction), puis par le bon paiement.
 */
class Paiement extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'paiements';

    // contre_passe_paiement_id : jamais en mass assignment, seule
    // ContrePasserPaiementAction le pose.
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

    /**
     * Le crédit d'avoir du client que ce paiement a imputé sur ce qui
     * restait dû des factures qu'il règle (voir EnregistrerPaiementAction) ;
     * pour une contre-passation, en négatif, celui qu'elle rend aux avoirs
     * (voir ContrePasserPaiementAction).
     */
    public function creditsImputes(): HasMany
    {
        return $this->hasMany(AvoirAllocation::class);
    }

    /**
     * Le paiement erroné qu'annule cette contre-passation.
     */
    public function contrePasse(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contre_passe_paiement_id');
    }

    /**
     * La contre-passation qui annule ce paiement, s'il a été saisi par
     * erreur.
     */
    public function contrePassation(): HasOne
    {
        return $this->hasOne(self::class, 'contre_passe_paiement_id');
    }

    public function estUneContrePassation(): bool
    {
        return $this->contre_passe_paiement_id !== null;
    }

    /**
     * Fermetures de caisse personnelles qui ont compté ce paiement (une
     * seule au plus, voir ClotureCaissePersonnelle::paiements()).
     */
    public function cloturesCaissePersonnelles(): BelongsToMany
    {
        return $this->belongsToMany(ClotureCaissePersonnelle::class, 'clotures_caisse_personnelles_paiements');
    }

    /**
     * Les paiements qui reviennent au relevé d'un vendeur (voir
     * FermerSaCaisseAction) et qu'aucune de ses fermetures n'a encore
     * comptés : ceux qu'il a enregistrés, et les contre-passations de ses
     * paiements, quel qu'en soit l'auteur -- une erreur de saisie que le
     * propriétaire corrige revient dans le relevé de celui qui l'a faite,
     * jamais dans celui du propriétaire.
     */
    public function scopeNonFermesParLeVendeur(Builder $query, int $vendeurId): Builder
    {
        return $query
            ->where(fn (Builder $paiements) => $paiements
                ->where(fn (Builder $saisis) => $saisis->whereNull('contre_passe_paiement_id')->where('cree_par', $vendeurId))
                ->orWhereHas('contrePasse', fn (Builder $originaux) => $originaux->where('cree_par', $vendeurId)))
            ->whereDoesntHave('cloturesCaissePersonnelles');
    }
}
