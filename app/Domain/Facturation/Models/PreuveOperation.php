<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La référence d'une preuve qui justifie l'exonération d'une facture
 * émise (I4) : déclaration d'export, document de transport, attestation
 * de l'acquéreur (voir TypePreuve et PreuvesRequises). Une preuve ne se
 * modifie ni ne se supprime (voir ProtegeLesDonneesValidees et les
 * déclencheurs de la table) : elle ne s'écrit qu'une fois, par
 * forceCreate() depuis AjouterPreuveOperationAction, d'où un $fillable
 * vide. Elle n'entre pas dans le contenu signé de la facture, qu'elle
 * suit sans la changer.
 */
class PreuveOperation extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, ProtegeLesDonneesValidees;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'note_par' => 'note_par_nom',
        ];
    }

    protected $table = 'preuves_operation';

    public const UPDATED_AT = null;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TypePreuve::class,
            'date_document' => 'date',
        ];
    }

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Une preuve d\'exonération enregistrée ne se modifie ni ne se supprime.';
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }
}
