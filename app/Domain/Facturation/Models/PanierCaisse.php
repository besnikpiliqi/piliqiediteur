<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\PanierCaisseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Le panier en cours de la caisse d'un vendeur, tel qu'il le voit : son
 * client et les lignes où un produit ou un service est choisi, par clé du
 * répéteur de la caisse. Écrit seulement par PanierDeCaisse, qui inscrit
 * au journal des événements tout ce que le panier perd avant
 * l'encaissement. Pas un document fiscal : il s'efface quand la vente est
 * encaissée ou abandonnée. Son jeton est celui de la vente (R3, voir
 * ActionsUniques).
 *
 * @property string $jeton
 * @property array<string, array{produit_id: int|null, service_id: int|null, description: string|null, reference: string|null, quantite: int, prix_unitaire_ht: string|null, prix_unitaire_ttc: string|null, taux_tva: string|null, remise_pourcentage: string|null}> $lignes
 */
class PanierCaisse extends Model
{
    /** @use HasFactory<PanierCaisseFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'paniers_caisse';

    // Tout s'écrit par PanierDeCaisse, jamais en mass assignment.
    protected $fillable = [];

    protected $casts = [
        'client_id' => 'integer',
        'lignes' => 'array',
    ];
}
