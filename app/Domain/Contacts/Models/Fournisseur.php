<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\SourceFournisseur;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use Database\Factories\FournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    /** @use HasFactory<FournisseurFactory> */
    use BelongsToMagasin, HasFactory, SoftDeletes;

    protected $fillable = [
        'nom',
        'siret',
        'numero_tva',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'telephone',
        'email',
        'source',
        'actif',
    ];

    protected $casts = [
        'source' => SourceFournisseur::class,
        'actif' => 'boolean',
    ];

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function facturesAchat(): HasMany
    {
        return $this->hasMany(FactureAchat::class);
    }

    /**
     * Point d'entrée unique pour la synchronisation automatique des
     * fournisseurs depuis une facture d'achat reçue via le PDP. Fait
     * autorité : un fournisseur existant est reconnu par son SIRET (jamais
     * par son nom, qui peut varier d'une facture à l'autre), un nouveau
     * SIRET crée un fournisseur marqué `pdp_auto` pour signalement en UI.
     *
     * Suppose que TenantContext est déjà positionné sur le bon magasin
     * (appelé depuis un job WithTenantContext).
     */
    public static function upsertDepuisFactureEntrante(string $siret, array $donnees): self
    {
        return static::query()->updateOrCreate(
            ['siret' => $siret],
            [...$donnees, 'source' => SourceFournisseur::AutoPdp->value],
        );
    }
}
