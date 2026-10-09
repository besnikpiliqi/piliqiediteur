<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\ClotureCaissePersonnelleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Relevé personnel des ventes d'UN vendeur : toutes celles qu'aucune de ses
 * fermetures n'a encore comptées, y compris celles d'un jour précédent. Sa
 * période (periode_debut) part de sa dernière fermeture, ou du début de la
 * journée s'il n'en a encore fait aucune, ou de sa plus ancienne vente
 * comptée si elle est antérieure.
 *
 * Un outil de réconciliation de fin de service, PAS un document fiscal.
 * Contrairement à ClotureCaisse (la clôture officielle de la journée du
 * magasin, réservée au propriétaire ou à qui il en délègue la permission),
 * un vendeur peut toujours fermer SA PROPRE caisse, sans permission
 * spéciale -- voir FermerSaCaisseAction.
 *
 * N'agrège PAS que les ventes aux particuliers (contrairement à
 * ClotureCaisse) : toutes les factures du vendeur comptent, l'objectif
 * étant de vérifier sa propre caisse, pas l'e-reporting. Les ventes
 * comptées sont notées dans une table à part (voir factures()), jamais sur
 * la facture elle-même : une facture déjà incluse ici reste pleinement
 * éligible à la clôture officielle de la journée.
 */
class ClotureCaissePersonnelle extends Model
{
    /** @use HasFactory<ClotureCaissePersonnelleFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'clotures_caisse_personnelles';

    // Tout le reste (périodes, totaux) n'est jamais en mass assignment,
    // uniquement via FermerSaCaisseAction.
    protected $fillable = [];

    protected $casts = [
        'periode_debut' => 'datetime',
        'cloturee_a' => 'datetime',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClotureCaissePersonnelle $cloture) {
            $cloture->cree_par ??= auth()->id();
        });
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par');
    }

    /**
     * Les ventes comptées par cette fermeture -- chacune ne l'est jamais
     * que par une seule (voir Facture::scopeNonFermeesParLeVendeur()).
     */
    public function factures(): BelongsToMany
    {
        return $this->belongsToMany(Facture::class, 'clotures_caisse_personnelles_factures');
    }
}
