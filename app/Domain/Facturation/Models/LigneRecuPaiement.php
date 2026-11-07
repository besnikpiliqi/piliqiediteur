<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un document qu'un reçu de paiement règle (voir RecuPaiement) : ce que
 * ses paiements lui imputent, le crédit d'avoir qu'ils y imputent ou y
 * rendent, et ce qui en restait dû après l'encaissement, lus à la fin de
 * sa transaction (voir RecusDePaiement). Jamais modifié ni supprimé (voir
 * ProtegeLesDonneesValidees et les déclencheurs de la table).
 */
class LigneRecuPaiement extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'lignes_recu_paiement';

    protected $fillable = [
        'recu_paiement_id',
        'facture_id',
        'montant',
        'credit',
        'reste_du',
        'ordre',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'credit' => 'decimal:2',
        'reste_du' => 'decimal:2',
        'ordre' => 'integer',
    ];

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Ce qu\'un reçu de paiement dit d\'un document ne se modifie ni ne se supprime.';
    }

    public function recuPaiement(): BelongsTo
    {
        return $this->belongsTo(RecuPaiement::class);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
