<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\AvoirFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use LogicException;

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

    /**
     * Chaque ligne est plafonnée au stock de son produit (voir
     * LigneAchat::quantiteRetournableRestante()), mais deux lignes du même
     * produit (deux lignes de la facture d'achat) ne peuvent pas non plus
     * en retourner ensemble plus que son stock : des articles déjà vendus
     * seraient retournés. Le stock est lu sous verrou, à la création de
     * l'avoir comme à son émission, puisqu'il change entre les deux ; celui
     * d'un produit archivé depuis compte aussi, il est toujours là.
     *
     * @throws LogicException
     */
    public function verifierQueLeStockSuffit(): void
    {
        $quantitesParProduit = $this->lignes()
            ->get()
            ->groupBy('produit_id')
            ->map(fn (Collection $lignes): int => $lignes->sum('quantite'))
            ->sortKeys();

        foreach ($quantitesParProduit as $produitId => $quantite) {
            $produit = Produit::withTrashed()->whereKey($produitId)->lockForUpdate()->firstOrFail();

            if ($produit->suivi_stock && $quantite > $produit->stock_quantite) {
                throw new LogicException(self::messageStockInsuffisant($produit, $quantite));
            }
        }
    }

    /**
     * Le même message dans le formulaire de l'avoir et à son
     * enregistrement.
     */
    public static function messageStockInsuffisant(Produit $produit, int $quantite): string
    {
        return "« {$produit->nom} » : ces lignes en retournent {$quantite} au total, mais il n'en reste que ".max(0, $produit->stock_quantite).' en stock (les autres ont déjà été vendus ou retournés).';
    }
}
