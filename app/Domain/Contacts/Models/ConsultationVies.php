<?php

namespace App\Domain\Contacts\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une vérification du n° de TVA d'un client professionnel sur VIES (voir
 * VerifierNumeroTvaAction) : la preuve, datée, que ce n° était valide ou
 * inconnu à cet instant. Une preuve ne se modifie ni ne se supprime (voir
 * ProtegeLesDonneesValidees et les déclencheurs de la table) : elle ne
 * s'écrit qu'une fois, par forceCreate() depuis l'action, d'où un
 * $fillable vide.
 *
 * consultee_le est enregistrée en UTC et relue à l'heure de Paris (voir
 * HorodatageUtc) : le jour d'une vérification est celui de Paris, de
 * minuit à minuit (voir scopeValideLe()). L'instant de la vérification est
 * aussi celui de son enregistrement : la table n'a pas d'autre date.
 */
class ConsultationVies extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'consultations_vies';

    public $timestamps = false;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valide' => 'boolean',
            'consultee_le' => HorodatageUtc::class.':'.config('app.timezone'),
        ];
    }

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Une vérification VIES est une preuve : elle ne se modifie ni ne se supprime.';
    }

    /**
     * Le client vérifié, même archivé depuis (voir les SoftDeletes de
     * Client) : la preuve reste la sienne.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function consulteePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultee_par');
    }

    /**
     * La consultation telle que l'écran la cite : son n° chez VIES quand
     * elle en a un, et son instant à l'heure de Paris (« consultation
     * WAPIAAAAW1234567 du 09/10/2026 à 14:32 »).
     */
    public function reference(): string
    {
        return trim('consultation '.$this->identifiant_consultation).' du '.$this->consultee_le->format('d/m/Y à H:i');
    }

    /**
     * Les vérifications qui ont trouvé le n° valide tel jour à Paris, de
     * minuit à minuit : la colonne étant en UTC, ses bornes se convertissent
     * en UTC (voir HorodatageUtc). Une date porte son jour dans son propre
     * fuseau, comme pour whereDate().
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeValideLe(Builder $query, CarbonInterface|string $jour): Builder
    {
        $minuit = CarbonImmutable::parse($jour instanceof CarbonInterface ? $jour->toDateString() : $jour, config('app.timezone'))->startOfDay();

        return $query
            ->where('valide', true)
            ->whereBetween('consultee_le', [HorodatageUtc::enBase($minuit), HorodatageUtc::enBase($minuit->endOfDay())]);
    }
}
