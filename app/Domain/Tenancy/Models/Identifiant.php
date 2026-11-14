<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'identifiant de connexion d'un compte : 8 chiffres tirés au hasard (voir
 * GenerateurDIdentifiants), uniques dans tout le logiciel, qui ne changent
 * jamais et ne sont jamais redonnés, et le magasin de rattachement d'un
 * compte de membre, écrit une seule fois (voir la migration de la table et
 * GardesDesIdentifiants).
 *
 * Il ne se montre qu'à la personne, au propriétaire, au responsable de son
 * magasin pour un compte de vendeur ou d'observateur, et à un
 * administrateur seulement par un geste inscrit au journal d'audit (voir
 * PorteeDesDroits::voitLIdentifiant()) ; jamais dans un document, un
 * e-mail, le journal d'audit ni un journal technique.
 *
 * Seule lecture hors de la société active : à la connexion, le compte que
 * désigne un identifiant (voir FournisseurDesComptes), rien d'autre.
 *
 * @property string $identifiant
 * @property int $user_id
 * @property int|null $magasin_id
 * @property string|null $jeton_de_creation
 * @property string|null $empreinte_de_creation
 */
class Identifiant extends Model
{
    use BelongsToSociete;

    public const UPDATED_AT = null;

    protected $fillable = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function compte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }
}
