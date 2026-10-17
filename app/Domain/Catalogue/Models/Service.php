<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ContexteDeVente;
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
 * TrouverOuCreerServiceAction). Un nom et, s'il est fixé, un prix de
 * référence TTC avec son taux de TVA (R2) : une ligne de ce service le
 * prend, converti sans TVA hors de la TVA française (voir
 * prixDeReferenceDans()), et seule une personne autorisée à fixer le prix
 * d'un service en met un autre, ou vend un service qui n'en a pas (voir
 * DerogationsDePrix) ; la ligne garde une copie figée de son prix (voir
 * ConstructeurLigneDocument). Prix et taux se fixent ensemble : sans prix,
 * pas de taux. Deux
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
        'prix_ttc',
        'taux_tva',
        'actif',
        'frais_accessoires',
        'lieu_specifique',
    ];

    protected $casts = [
        'prix_ttc' => 'decimal:2',
        'taux_tva' => 'decimal:2',
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
            $service->verifierSonPrixDeReference();
        });
    }

    /**
     * A-t-il un prix de référence ? Sans, seule une personne autorisée à
     * fixer le prix d'un service le vend (voir DerogationsDePrix).
     */
    public function aUnPrixDeReference(): bool
    {
        return $this->prix_ttc !== null;
    }

    /**
     * Le prix que prend une ligne de ce service dans cette vente, null sans
     * prix de référence : son prix TTC, et, hors de la TVA française, ce
     * prix sans sa TVA, au centime, comme le convertit l'écran (voir
     * ChoisitLaNatureDeLOperation). En franchise en base, le client paie
     * le même prix TTC, sans TVA.
     */
    public function prixDeReferenceDans(ContexteDeVente $contexte): ?float
    {
        if (! $this->aUnPrixDeReference()) {
            return null;
        }

        return $contexte->retireLaTvaDuPrix()
            ? app(CalculateurLigne::class)->prixUnitaireHtDepuisTtc((float) $this->prix_ttc, (float) $this->taux_tva)
            : (float) $this->prix_ttc;
    }

    /**
     * Le taux de TVA que prend une ligne de ce service dans cette vente,
     * null quand la vente le fixe d'elle-même (franchise en base, vente
     * sans TVA française, toutes deux à 0 %) ou qu'il n'a pas de prix de
     * référence.
     */
    public function tauxDeReferenceDans(ContexteDeVente $contexte): ?float
    {
        if (! $this->aUnPrixDeReference() || $contexte->franchiseEnBase || $contexte->retireLaTvaDuPrix()) {
            return null;
        }

        return (float) $this->taux_tva;
    }

    /**
     * Prix et taux se fixent ensemble : sans prix, le taux ne s'enregistre
     * pas ; un prix sans taux est refusé. Un prix n'est jamais négatif, un
     * taux reste entre 0 et 100 %.
     *
     * @throws InvalidArgumentException
     */
    private function verifierSonPrixDeReference(): void
    {
        if ($this->prix_ttc === null) {
            $this->taux_tva = null;
        }

        if ($this->prix_ttc !== null && $this->taux_tva === null) {
            throw new InvalidArgumentException('Le prix de référence d\'un service et son taux de TVA se fixent ensemble.');
        }

        if ($this->prix_ttc !== null && ((float) $this->prix_ttc < 0 || (float) $this->taux_tva < 0 || (float) $this->taux_tva > 100)) {
            throw new InvalidArgumentException('Le prix de référence d\'un service n\'est jamais négatif, et son taux de TVA reste entre 0 et 100 %.');
        }
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
