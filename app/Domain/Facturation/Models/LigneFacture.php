<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LigneFacture extends Model
{
    use BelongsToMagasin;

    protected $table = 'lignes_facture';

    protected $fillable = [
        'facture_id',
        'produit_id',
        'service_id',
        'nature',
        'description',
        'reference',
        'quantite',
        'prix_unitaire_ht',
        'prix_unitaire_ttc',
        'taux_tva',
        'remise_pourcentage',
        'montant_ht',
        'montant_tva',
        'montant_ttc',
        'ordre',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'prix_unitaire_ttc' => 'decimal:2',
        'nature' => NatureLigne::class,
        'taux_tva' => 'decimal:2',
        'remise_pourcentage' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    /**
     * Le service du catalogue dont vient la ligne (voir Service), jamais en
     * même temps qu'un produit.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function lignesAvoir(): HasMany
    {
        return $this->hasMany(LigneAvoir::class);
    }

    /**
     * Quantité déjà créditée par des avoirs ÉMIS référençant cette ligne.
     * Un avoir encore brouillon n'a aucune existence légale (voir
     * Avoir::class) : il ne doit jamais réduire la quantité restante
     * créditable, au même titre qu'il ne bloque pas un nouvel avoir (voir
     * CreerAvoirAction).
     */
    public function quantiteDejaCreditee(): int
    {
        return (int) $this->lignesAvoir()
            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
            ->sum('quantite');
    }

    /**
     * Permet un avoir partiel, et plusieurs avoirs successifs sur une même
     * ligne, tant que leur somme ne dépasse jamais la quantité facturée
     * (voir CreerAvoirAction).
     */
    public function quantiteCreditableRestante(): int
    {
        return $this->quantite - $this->quantiteDejaCreditee();
    }

    /**
     * Montants d'un retour de $quantite articles de cette ligne : la ligne
     * calculée pour tout ce qui en a été rendu jusqu'ici, moins ce que les
     * avoirs déjà émis ont crédité (voir CalculateurLigne::retourCumule()).
     * Le dernier retour retombe ainsi exactement sur la ligne vendue.
     *
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function montantsDuRetour(int $quantite): array
    {
        $calculateur = app(CalculateurLigne::class);
        $avoirsEmis = $this->lignesAvoir()
            ->whereHas('avoir', fn ($query) => $query->where('statut', StatutAvoir::Emise))
            ->get(['quantite', 'montant_ht', 'montant_tva', 'montant_ttc']);

        return $calculateur->retourCumule(
            $calculateur->montantsDeVente(
                (float) $this->prix_unitaire_ht,
                (float) $this->prix_unitaire_ttc,
                (int) $avoirsEmis->sum('quantite') + $quantite,
                (float) $this->remise_pourcentage,
            ),
            $avoirsEmis->toArray(),
        );
    }
}
