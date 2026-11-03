<?php

namespace App\Domain\Cabinets\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;

/**
 * Un client lié que le propriétaire d'un cabinet d'expertise comptable
 * confie à l'un de ses collaborateurs (voir ConfierDesClientsAction) ; sa
 * société est le cabinet. Un collaborateur ne voit et n'ouvre que les
 * clients qui lui sont confiés (voir AccesDesCabinets). Sans mise à jour :
 * une ligne naît, puis s'efface quand le client ne lui est plus confié,
 * quand le lien prend fin ou quand le collaborateur est retiré.
 *
 * @property int $lien_id
 * @property int $user_id
 */
class ClientConfie extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    public const UPDATED_AT = null;

    protected $table = 'clients_confies';

    protected $fillable = [];

    protected static function colonnesAuNomFige(): array
    {
        return ['confie_par' => 'confie_par_nom'];
    }
}
