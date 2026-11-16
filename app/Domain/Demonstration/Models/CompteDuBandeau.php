<?php

namespace App\Domain\Demonstration\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un compte du bandeau « Vous êtes : » d'une copie du site de
 * démonstration (lot DEMO-COPIE, C.8 et F.3) : un compte de la société mise
 * en avant de la base modèle, avec le libellé de son rôle, son groupe
 * (« La société », un magasin, « Le comptable » ; dans la copie
 * « Cabinet » : « Le cabinet ») et son rang dans le menu. Le semis du
 * modèle les écrit (voir ComptesDuBandeauDuModele) ; la table n'existe
 * que dans les bases du site de démonstration (database/migrations-demonstration).
 *
 * @property int $user_id
 * @property string $libelle_du_role
 * @property string $groupe
 * @property int $ordre
 */
class CompteDuBandeau extends Model
{
    use BelongsToSociete;

    public $timestamps = false;

    protected $table = 'comptes_du_bandeau';

    protected $fillable = ['user_id', 'libelle_du_role', 'groupe', 'ordre'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'ordre' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function compte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
