<?php

namespace App\Domain\Demonstration\Registre\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Demonstration\Enums\EtatDUnModele;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Une base modèle au registre du site de démonstration (lot DEMO-COPIE,
 * C.1 et C.2). Lu par le rôle des pages (connexion registre), écrit par
 * demo_admin seul (Modele::on('demo_admin')).
 *
 * @property int $id
 * @property TypeDActivite $type
 * @property string $nom_de_la_base
 * @property CarbonImmutable $commence_le
 * @property CarbonImmutable|null $construit_le
 * @property string $version_du_code
 * @property string|null $empreinte_du_schema
 * @property string $mot_de_passe
 * @property EtatDUnModele $etat
 * @property string|null $cause_de_l_echec
 * @property int|null $taille
 * @property int|null $duree_en_secondes
 */
class Modele extends Model
{
    protected $connection = 'registre';

    protected $table = 'modeles';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['mot_de_passe'];

    protected function casts(): array
    {
        return [
            'type' => TypeDActivite::class,
            'etat' => EtatDUnModele::class,
            'commence_le' => Instant::class,
            'construit_le' => Instant::class,
            'taille' => 'integer',
            'duree_en_secondes' => 'integer',
        ];
    }
}
