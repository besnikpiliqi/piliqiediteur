<?php

namespace App\Domain\Stock\Models;

use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Models\Magasin;
use Database\Factories\InventaireOuvertureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Le stock existant au démarrage d'un magasin, avec son coût : avec la
 * facture d'achat validée, le seul document dont un produit peut naître
 * (voir OrigineDesProduits). Il se saisit en brouillon jusqu'à la
 * première vente du magasin (voir CreerInventaireOuvertureAction), sans
 * aucun effet ; sa validation crée ses nouveaux produits, fait entrer leur
 * stock au coût saisi, sans TVA ni fournisseur, lui donne son numéro et
 * s'inscrit au journal d'audit (voir ValiderInventaireOuvertureAction).
 * Validé, il ne se modifie ni ne se supprime plus jamais, ni ses lignes :
 * ses actions le relisent sous son verrou avant de le modifier ou de le
 * supprimer (voir ModifierInventaireOuvertureAction et
 * SupprimerInventaireOuvertureAction), et ce modèle refuse en plus
 * d'écrire ou d'effacer un inventaire validé en base, même lu avant sa
 * validation.
 *
 * L'inventaire complet (comptage, ajustements, import) reste à l'étape 7
 * de la feuille de route.
 */
class InventaireOuverture extends Model
{
    /** @use HasFactory<InventaireOuvertureFactory> */
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, HasFactory;

    public const REFUS_VALIDE = 'Cet inventaire d\'ouverture est validé : il ne se modifie ni ne se supprime plus.';

    public const REFUS_APRES_LA_PREMIERE_VENTE = 'Ce magasin a déjà vendu : un inventaire d\'ouverture ne se saisit que jusqu\'à sa première vente. Un produit oublié entre par une facture d\'achat.';

    protected $table = 'inventaires_ouverture';

    // numero, statut, date_validation, valeur_ht et les auteurs : jamais en
    // mass assignment, seulement par les actions de l'inventaire.
    protected $fillable = [
        'notes',
    ];

    protected $casts = [
        'statut' => StatutInventaireOuverture::class,
        'date_validation' => 'datetime',
        'valeur_ht' => 'decimal:2',
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
            'valide_par' => 'valide_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InventaireOuverture $inventaire): void {
            $inventaire->statut ??= StatutInventaireOuverture::Brouillon;
        });

        static::updating(fn (InventaireOuverture $inventaire) => $inventaire->verifierQuIlEstEncoreUnBrouillon());
        static::deleting(fn (InventaireOuverture $inventaire) => $inventaire->verifierQuIlEstEncoreUnBrouillon());
    }

    /**
     * Relu en base, et non sur ce modèle, qui peut avoir été lu avant une
     * validation faite depuis : un inventaire validé ne s'écrit ni ne
     * s'efface plus. La validation elle-même passe, l'inventaire étant
     * encore un brouillon en base quand elle l'enregistre.
     *
     * @throws LogicException
     */
    private function verifierQuIlEstEncoreUnBrouillon(): void
    {
        if (static::query()->whereKey($this->getKey())->value('statut') === StatutInventaireOuverture::Valide) {
            throw new LogicException(self::REFUS_VALIDE);
        }
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneInventaireOuverture::class)->orderBy('ordre')->orderBy('id');
    }

    public function estBrouillon(): bool
    {
        return $this->statut === StatutInventaireOuverture::Brouillon;
    }

    /**
     * Un inventaire d'ouverture se saisit et se valide jusqu'à la première
     * vente du magasin (voir Magasin::aVendu()).
     *
     * @throws LogicException
     */
    public static function verifierQuIlSeSaisitEncore(Magasin $magasin): void
    {
        if ($magasin->aVendu()) {
            throw new LogicException(self::REFUS_APRES_LA_PREMIERE_VENTE);
        }
    }
}
