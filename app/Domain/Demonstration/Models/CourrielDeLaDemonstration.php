<?php

namespace App\Domain\Demonstration\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un e-mail qui serait parti d'une copie du site de démonstration (lot
 * DEMO-COPIE, C.9) : le transport « demonstration » l'écrit au lieu de
 * l'envoyer (voir TransportDeLaDemonstration), dans la société d'où il
 * part. La page « E-mails qui seraient partis » ne montre que ceux de la
 * société de la copie (voir SocieteDeLaCopie). La table n'existe que dans
 * les bases du site de démonstration (database/migrations-demonstration).
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $expediteur
 * @property string $destinataires
 * @property string|null $copies
 * @property string $objet
 * @property string|null $html
 * @property string|null $texte
 * @property list<array{nom: string, type: string, taille: int, contenu: string|null}> $pieces_jointes
 * @property CarbonImmutable $envoye_le
 */
class CourrielDeLaDemonstration extends Model
{
    use BelongsToSociete;

    public $timestamps = false;

    protected $table = 'courriels_de_la_demonstration';

    protected $fillable = ['user_id', 'expediteur', 'destinataires', 'copies', 'objet', 'html', 'texte', 'pieces_jointes', 'envoye_le'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'pieces_jointes' => 'array',
            'envoye_le' => Instant::class,
        ];
    }

    /**
     * Le compte qui a demandé l'envoi, quand il y en a un.
     *
     * @return BelongsTo<User, $this>
     */
    public function compte(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
