<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Contacts\Concerns\Proposable;
use App\Domain\Facturation\Casts\NumeroTvaNormalise;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Models\User;
use Database\Factories\FournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un fournisseur du magasin, qui nomme qui a créé sa fiche et qui l'a
 * modifiée, archivée ou restaurée en dernier (cree_par, modifie_par, avec
 * leur nom figé), posés par les actions des fiches (voir
 * CreerUneFicheAction, ModifierUneFicheAction et ArchiverUneFicheAction) :
 * la base refuse ces écritures à un compte d'un cabinet lié ou à un
 * expert-comptable de la société dans un magasin que le client ne lui
 * ouvre pas (voir GardesDesCabinets::AUTEURS_DANS_UN_MAGASIN).
 *
 * @property int|null $cree_par
 * @property string|null $cree_par_nom
 * @property int|null $modifie_par
 * @property string|null $modifie_par_nom
 */
class Fournisseur extends Model
{
    /** @use HasFactory<FournisseurFactory> */
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, HasFactory, Proposable, SoftDeletes;

    /**
     * Refus d'une action qui reçoit le fournisseur déjà chargé d'un autre
     * magasin (voir BelongsToMagasin::relueDansLeMagasin()).
     */
    public const REFUS_AUTRE_MAGASIN = 'Ce fournisseur n\'est pas un fournisseur du magasin.';

    protected $fillable = [
        'nom',
        'siret',
        'numero_tva',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'telephone',
        'email',
    ];

    protected $casts = [
        'numero_tva' => NumeroTvaNormalise::class,
    ];

    /**
     * Note ce compte comme l'auteur de la dernière modification de la
     * fiche, et, pour une fiche nouvelle, de sa création.
     */
    public function noterSonAuteur(User $compte): self
    {
        return $this->forceFill([
            ...($this->exists ? [] : ['cree_par' => $compte->getKey()]),
            'modifie_par' => $compte->getKey(),
        ]);
    }

    protected static function colonnesAuNomFige(): array
    {
        return ['cree_par' => 'cree_par_nom', 'modifie_par' => 'modifie_par_nom'];
    }
}
