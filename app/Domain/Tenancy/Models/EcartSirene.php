<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un écart que la revérification du répertoire Sirene a relevé sur la
 * société validée ou l'un de ses magasins (voir
 * ReverificationSirene::retenirLEcart()) : rien ne se bloque, et il reste
 * dans l'« À faire » de l'espace de la société jusqu'à « C'est vérifié »
 * (décision de l'éditeur du 01/11/2026, Q-AF13, voir
 * VerifierUnEcartSireneAction). Il ne s'écrit que par forceCreate() et
 * forceFill(), d'où un $fillable vide.
 */
class EcartSirene extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    protected $table = 'ecarts_sirene';

    protected $fillable = [];

    protected $casts = [
        'negatif' => 'boolean',
        'signale_le' => Instant::class,
        'verifie_le' => Instant::class,
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'verifie_par' => 'verifie_par_nom',
        ];
    }

    /**
     * Les écarts que personne n'a encore dit vérifiés.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAVerifier(Builder $query): Builder
    {
        return $query->whereNull('verifie_le');
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }
}
