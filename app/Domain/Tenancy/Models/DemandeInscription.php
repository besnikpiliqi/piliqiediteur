<?php

namespace App\Domain\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une demande d'inscription d'une société, avant toute société (voir
 * InscriptionSociete et ConfirmerInscriptionController) : le lien envoyé à
 * l'adresse du futur propriétaire porte son jeton, dont seule l'empreinte
 * SHA-256 est gardée (jeton_hache) ; ses données, chiffrées, sont celles
 * que la confirmation enregistre (voir CreerSocieteAction). Elle vaut 24
 * heures, ne sert qu'une fois, et s'efface 48 heures après son expiration
 * (voir app:purger-demandes-inscription).
 *
 * @property array{siren: string, nom: string, mot_de_passe: string} $donnees
 */
class DemandeInscription extends Model
{
    public const DUREE_DE_VALIDITE_EN_HEURES = 24;

    public const EFFACEMENT_APRES_EXPIRATION_EN_HEURES = 48;

    protected $table = 'demandes_inscription';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $hidden = ['donnees', 'jeton_hache'];

    protected $casts = [
        'donnees' => 'encrypted:array',
        'expire_le' => 'datetime',
        'consommee_le' => 'datetime',
    ];

    /**
     * L'empreinte d'un jeton, telle qu'elle se garde et se cherche : le
     * jeton lui-même n'est jamais enregistré.
     */
    public static function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }
}
