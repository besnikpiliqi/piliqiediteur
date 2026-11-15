<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Une demande déjà traitée dans une société, hors de tout magasin (R3,
 * voir ActionsUniques::executerDansLaSociete()) : son jeton, son action,
 * la personne qui l'a demandée, l'empreinte de son contenu et son
 * résultat. Écrite seulement par ActionsUniques, effacée après 30 jours
 * (voir PurgerActionsTraitees).
 *
 * @property string $jeton
 * @property string $action
 * @property int $user_id
 * @property string $empreinte_demande
 * @property string|null $resultat_type
 * @property int|null $resultat_id
 */
class ActionTraiteeDeLaSociete extends Model
{
    use BelongsToSociete;

    public const UPDATED_AT = null;

    protected $table = 'actions_traitees_de_la_societe';

    // Tout s'écrit par ActionsUniques, jamais en mass assignment.
    protected $fillable = [];

    protected $casts = [
        'user_id' => 'integer',
        'resultat_id' => 'integer',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function resultat(): MorphTo
    {
        return $this->morphTo();
    }
}
