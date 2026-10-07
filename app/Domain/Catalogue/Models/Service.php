<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un service que le magasin réalise (remplacement d'écran, main-d'œuvre,
 * pose de protection...) : la caisse propose en cases à cocher ceux déjà
 * créés, et en crée un nouveau quand il manque (voir
 * TrouverOuCreerServiceAction). Juste un nom : le prix se saisit en TTC à
 * chaque vente, la ligne en garde une copie figée (voir
 * ConstructeurLigneDocument).
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToMagasin, HasFactory;

    public const LONGUEUR_MAXIMALE_NOM = 150;

    protected $fillable = [
        'nom',
        'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Service $service) {
            $service->nom = self::nettoyerNom((string) $service->nom);
            $service->nom_normalise = self::normaliserNom($service->nom);
        });
    }

    /**
     * Le nom tel qu'il s'affiche : sans espaces autour, ni espaces répétés.
     */
    public static function nettoyerNom(string $nom): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nom));
    }

    /**
     * Clé de doublon : "Main d'œuvre", "main d’œuvre" et " MAIN  D'ŒUVRE "
     * désignent le même service. L'apostrophe typographique (’) qu'insèrent
     * les claviers de téléphone vaut l'apostrophe droite.
     */
    public static function normaliserNom(string $nom): string
    {
        return mb_strtolower(str_replace(['’', '‘', 'ʼ', '`'], "'", self::nettoyerNom($nom)));
    }

    /**
     * @param  Builder<Service>  $query
     */
    public function scopeActifs(Builder $query): void
    {
        $query->where('actif', true);
    }

    /**
     * @return HasMany<LigneFacture, $this>
     */
    public function lignesFacture(): HasMany
    {
        return $this->hasMany(LigneFacture::class);
    }
}
