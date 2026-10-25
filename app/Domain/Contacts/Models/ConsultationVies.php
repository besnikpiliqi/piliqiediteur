<?php

namespace App\Domain\Contacts\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
 * minuit à minuit (voir scopeDuJour()). L'instant de la vérification est
 * aussi celui de son enregistrement : la table n'a pas d'autre date.
 */
class ConsultationVies extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, ProtegeLesDonneesValidees;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'consultee_par' => 'consultee_par_nom',
        ];
    }

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
            'consultee_le' => HorodatageUtc::class.':'.Calendrier::deParis()->fuseau(),
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
     * La consultation telle que l'écran la cite : son n° chez VIES quand
     * elle en a un, et son instant à l'heure du calendrier (« consultation
     * WAPIAAAAW1234567 du 09/10/2026 à 14:32 »).
     */
    public function reference(): string
    {
        return trim('consultation '.$this->identifiant_consultation).' du '.Calendrier::deParis()->afficher($this->consultee_le, 'd/m/Y à H:i');
    }

    /**
     * Les vérifications faites tel jour du calendrier, de minuit à minuit,
     * qu'elles aient trouvé le n° valide ou non : la dernière décide (voir
     * ControleNatureOperation::consultationDuJour()). La colonne étant en
     * UTC, ses bornes se comparent comme des instants (voir HorodatageUtc).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDuJour(Builder $query, string $jour): Builder
    {
        [$minuit, $minuitSuivant] = Calendrier::deParis()->bornesDuJour($jour);

        return $query->where('consultee_le', '>=', HorodatageUtc::enBase($minuit))->where('consultee_le', '<', HorodatageUtc::enBase($minuitSuivant));
    }
}
