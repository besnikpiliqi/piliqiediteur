<?php

namespace App\Domain\Demonstration\Registre\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Demonstration\Enums\EtatDUneCopie;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une copie au registre du site de démonstration (lot DEMO-COPIE, C.1) :
 * rien de la vraie société, seulement la référence, le repère, le type, si
 * elle est validée, la génération du lien et la fin. Lue par le rôle des
 * pages (connexion registre), qui n'y inscrit une demande que par la
 * fonction inscrire_une_copie() ; changée par demo_admin seul.
 *
 * @property int $id
 * @property string $reference
 * @property string $repere
 * @property TypeDActivite $type
 * @property bool $validee
 * @property string|null $nom_de_la_base
 * @property int|null $modele_id
 * @property CarbonImmutable $creee_le
 * @property CarbonImmutable|null $prete_le
 * @property CarbonImmutable $fin
 * @property int $generation_du_lien
 * @property EtatDUneCopie $etat
 * @property int $essais
 * @property CarbonImmutable|null $prochain_essai_le
 * @property string|null $cause_de_l_echec
 * @property string|null $version
 * @property string|null $empreinte_du_schema
 * @property int|null $taille_mesuree
 * @property CarbonImmutable|null $derniere_passe_des_taches_le
 * @property CarbonImmutable|null $derniere_verification_le
 * @property string|null $message
 * @property string|null $motif_du_message
 * @property CarbonImmutable|null $supprimee_le
 */
class Copie extends Model
{
    protected $connection = 'registre';

    protected $table = 'copies';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => TypeDActivite::class,
            'etat' => EtatDUneCopie::class,
            'validee' => 'boolean',
            'creee_le' => Instant::class,
            'prete_le' => Instant::class,
            'fin' => Instant::class,
            'prochain_essai_le' => Instant::class,
            'derniere_passe_des_taches_le' => Instant::class,
            'derniere_verification_le' => Instant::class,
            'supprimee_le' => Instant::class,
            'generation_du_lien' => 'integer',
            'essais' => 'integer',
            'taille_mesuree' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Modele, $this>
     */
    public function modele(): BelongsTo
    {
        return $this->belongsTo(Modele::class);
    }

    /**
     * @return HasMany<Lien, $this>
     */
    public function liens(): HasMany
    {
        return $this->hasMany(Lien::class);
    }

    /**
     * @return HasMany<Temoin, $this>
     */
    public function temoins(): HasMany
    {
        return $this->hasMany(Temoin::class);
    }

    /**
     * La fin est un instant UTC : la copie est terminée dès sa fin, même
     * avant le passage de la tâche qui la supprime (C.14).
     */
    public function estEchue(CarbonImmutable $maintenant): bool
    {
        return $this->fin->lessThanOrEqualTo($maintenant);
    }
}
