<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Un service que le magasin réalise (remplacement d'écran, main-d'œuvre,
 * pose de protection...) : la caisse propose en cases à cocher ceux déjà
 * créés, et en crée un nouveau quand il manque (voir
 * TrouverOuCreerServiceAction). Un nom, sans prix : le prix se saisit à
 * chaque vente, TTC en France, sans TVA hors de la TVA française, et la
 * ligne en garde une copie figée (voir ConstructeurLigneDocument). Deux
 * marques disent ce qu'une vente hors de France en fait (voir
 * ControleNatureOperation) : des frais accessoires (port, emballage,
 * assurance) suivent la livraison des biens qu'ils accompagnent ; un
 * service au lieu fixé par la loi (immeuble, transport de personnes,
 * location courte de véhicule, restauration, accès à un événement, art.
 * 259 A du CGI) ne se facture jamais sans TVA française à un
 * professionnel étranger.
 */
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use BelongsToMagasin, HasFactory;

    public const LONGUEUR_MAXIMALE_NOM = 150;

    /**
     * Longueur de la clé de doublon : la translittération peut allonger un
     * nom (« œ » devient « oe », « … » devient « ... »).
     */
    public const LONGUEUR_MAXIMALE_CLE = 255;

    protected $fillable = [
        'nom',
        'actif',
        'frais_accessoires',
        'lieu_specifique',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'frais_accessoires' => 'boolean',
        'lieu_specifique' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Service $service) {
            $service->nom = self::nettoyerNom((string) $service->nom);

            if ($service->nom === '') {
                throw new InvalidArgumentException('Le nom du service est obligatoire.');
            }

            $service->nom_normalise = self::normaliserNom($service->nom);
        });
    }

    /**
     * Le nom tel qu'il s'affiche : sans espaces autour, ni espaces répétés
     * (espaces insécables compris).
     */
    public static function nettoyerNom(string $nom): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $nom));
    }

    /**
     * Clé de doublon : « Main d'œuvre », « main d’oeuvre », « Main-d'œuvre »
     * et « MAIN D'OEUVRE » désignent le même service, comme « Remplacement
     * écran » et « REMPLACEMENT ECRAN ». Accents, ligatures, majuscules,
     * tirets et apostrophes typographiques des claviers de téléphone sont
     * ignorés, en PHP et non par la collation de la base : SQLite et
     * PostgreSQL décident pareil. Un nom sans aucune lettre latine (que
     * des emojis) garde sa propre écriture plutôt que de devenir une clé
     * vide.
     */
    public static function normaliserNom(string $nom): string
    {
        $nom = self::nettoyerNom((string) preg_replace('/\p{Pd}/u', ' ', str_replace(['’', '‘', 'ʼ', '`'], "'", $nom)));
        $sansAccents = self::nettoyerNom(Str::ascii($nom, 'fr'));

        return mb_substr(mb_strtolower($sansAccents !== '' ? $sansAccents : $nom), 0, self::LONGUEUR_MAXIMALE_CLE);
    }

    /**
     * @param  Builder<Service>  $query
     */
    public function scopeActifs(Builder $query): void
    {
        $query->where('actif', true);
    }
}
