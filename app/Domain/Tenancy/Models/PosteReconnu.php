<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;

/**
 * Un poste du magasin reconnu par le propriétaire ou un responsable (voir
 * PostesReconnus) : un navigateur qui porte le témoin de ce poste, dont
 * seule l'empreinte SHA-256 du jeton est gardée. Tant que le mot de passe
 * d'un compte est encore son identifiant, il n'ouvre ce compte que depuis
 * un poste reconnu, non retiré, d'un magasin que ce compte ouvre. Un poste
 * retiré ne sert plus ; il reste listé, avec qui l'a reconnu et qui l'a
 * retiré, leur nom figé.
 *
 * Seule lecture hors du magasin actif : à la connexion, le poste que
 * désigne le témoin du navigateur, pour le compte qui se connecte (voir
 * PostesReconnus::ouvreLeCompte()).
 *
 * @property string $nom
 * @property string $jeton_hache
 * @property int $reconnu_par
 * @property string $reconnu_par_nom
 * @property int|null $retire_par
 * @property string|null $retire_par_nom
 */
class PosteReconnu extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs;

    protected $table = 'postes_reconnus';

    protected $fillable = [];

    protected $hidden = ['jeton_hache'];

    protected $casts = [
        'retire_le' => 'datetime',
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'reconnu_par' => 'reconnu_par_nom',
            'retire_par' => 'retire_par_nom',
        ];
    }

    public function estRetire(): bool
    {
        return $this->retire_le !== null;
    }
}
