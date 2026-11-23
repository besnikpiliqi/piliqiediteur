<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La date du visa d'un bordereau de détaxe (BVE) à la sortie de l'Union,
 * notée sur la fiche du ticket (décision de l'éditeur du 01/11/2026,
 * Q-AF13, voir NoterLeVisaDuBordereauAction) : le ticket n'attend plus son
 * visa dans l'« À faire » du magasin, et sa TVA pourra se régulariser (ce
 * que fera la comptabilité, étape 5). Un visa noté ne se modifie ni ne se
 * supprime (voir ProtegeLesDonneesValidees et les déclencheurs de la
 * table) ; il ne s'écrit que par forceCreate() depuis l'action, d'où un
 * $fillable vide, et ne s'imprime pas sur le ticket, qui reproduit le
 * document tel qu'émis.
 */
class VisaDetaxe extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, ProtegeLesDonneesValidees;

    protected $table = 'visas_detaxe';

    public const UPDATED_AT = null;

    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_visa' => JourCivil::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'note_par' => 'note_par_nom',
        ];
    }

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Le visa d\'un bordereau de détaxe noté ne se modifie ni ne se supprime.';
    }

    /**
     * @return BelongsTo<BordereauDetaxe, $this>
     */
    public function bordereau(): BelongsTo
    {
        return $this->belongsTo(BordereauDetaxe::class, 'bordereau_detaxe_id');
    }
}
