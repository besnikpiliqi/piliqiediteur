<?php

namespace App\Domain\Achats\Models;

use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Database\Factories\AvoirFournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Document de retour au fournisseur, jamais modifiable ni supprimable une
 * fois émis (voir AvoirFournisseurPolicy). Référence une facture d'achat
 * SANS jamais la modifier (voir CreerAvoirFournisseurAction) : la facture
 * d'achat d'origine reste un document figé, l'avoir ne fait qu'y
 * référencer un retour, chacune de ses lignes liée à la ligne d'achat
 * qu'elle retourne. Il garde le n° de l'avoir du fournisseur
 * (numero_fournisseur), qui le rapprochera de celui que le fournisseur
 * enverra par la plateforme de facturation électronique.
 */
class AvoirFournisseur extends Model
{
    use FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'cree_par' => 'cree_par_nom',
        ];
    }

    /** @use HasFactory<AvoirFournisseurFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'avoirs_fournisseur';

    // numero, statut, date_emission, totaux, tva_a_verifier,
    // piece_manquante : jamais en mass assignment, uniquement via
    // CreerAvoirFournisseurAction, EmettreAvoirFournisseurAction,
    // TvaDuFournisseur et PiecesDuFournisseur.
    protected $fillable = [
        'facture_achat_id',
        'numero_fournisseur',
        'motif',
        'devise',
        'source',
    ];

    /**
     * date_emission est l'instant de l'émission, en UTC (voir Instant).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statut' => StatutAvoirFournisseur::class,
            'source' => SourceAvoirFournisseur::class,
            'date_emission' => Instant::class,
            'total_ht' => 'decimal:2',
            'total_tva' => 'decimal:2',
            'total_ttc' => 'decimal:2',
            'tva_a_verifier' => 'boolean',
            'piece_manquante' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AvoirFournisseur $avoir) {
            $avoir->statut ??= StatutAvoirFournisseur::Brouillon;
            $avoir->devise ??= 'EUR';
        });
    }

    /**
     * @return BelongsTo<FactureAchat, $this>
     */
    public function factureAchat(): BelongsTo
    {
        return $this->belongsTo(FactureAchat::class);
    }

    /**
     * @return HasMany<LigneAvoirFournisseur, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneAvoirFournisseur::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Les pièces du fournisseur jointes, la note d'avoir du fournisseur
     * (voir PiecesDuFournisseur), de la première déposée à la dernière.
     *
     * @return HasMany<PieceDuFournisseur, $this>
     */
    public function pieces(): HasMany
    {
        return $this->hasMany(PieceDuFournisseur::class)->orderBy('id');
    }

    /**
     * Sa TVA, taux par taux, telle que l'écrit la note d'avoir du
     * fournisseur (voir TvaDuFournisseur), dans l'ordre où chaque taux
     * apparaît.
     *
     * @return HasMany<TauxAvoirFournisseur, $this>
     */
    public function taux(): HasMany
    {
        return $this->hasMany(TauxAvoirFournisseur::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * Chaque ligne est plafonnée au stock de son produit (voir
     * LigneAchat::quantiteRetournableRestante()), relu ici sous le verrou de
     * la fiche, à la création de l'avoir comme à son émission, puisqu'il
     * change entre les deux et pendant elles : des articles vendus
     * entre-temps ne sont jamais retournés. Une
     * facture d'achat n'ayant qu'une ligne par produit (voir
     * ConstructeurLigneAchat), chaque ligne de l'avoir retourne un produit
     * différent, verrouillé par identifiant croissant ; le stock d'un
     * produit archivé depuis compte aussi, il est toujours là.
     *
     * @throws LogicException
     */
    public function verifierQueLeStockSuffit(): void
    {
        foreach ($this->lignes()->reorder('produit_id')->get() as $ligne) {
            $produit = Produit::withTrashed()->whereKey($ligne->produit_id)->lockForUpdate()->firstOrFail();

            if ($produit->suivi_stock && $ligne->quantite > $produit->stock_quantite) {
                throw new LogicException(self::messageStockInsuffisant($produit, $ligne->quantite));
            }
        }
    }

    /**
     * Le refus d'une ligne qui retournerait plus que le stock restant de son
     * produit.
     */
    private static function messageStockInsuffisant(Produit $produit, int $quantite): string
    {
        return "« {$produit->nom} » : l'avoir en retourne {$quantite}, mais il n'en reste que ".max(0, $produit->stock_quantite).' en stock (les autres ont déjà été vendus ou retournés).';
    }
}
