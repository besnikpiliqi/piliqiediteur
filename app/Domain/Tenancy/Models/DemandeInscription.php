<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use Illuminate\Database\Eloquent\Model;

/**
 * Une demande d'inscription d'une société, avant toute société (voir
 * InscriptionSociete et ConfirmerInscriptionController) : le lien envoyé à
 * l'adresse du futur propriétaire porte son jeton, dont seule l'empreinte
 * SHA-256 est gardée (jeton_hache) ; ses données, chiffrées, sont celles
 * que la confirmation enregistre (voir CreerSocieteAction) : le SIREN, le
 * type d'activité choisi, que la page du lien laisse encore changer, le
 * code d'activité de l'entreprise et sa nomenclature lus au répertoire
 * Sirene à la demande (nuls s'il n'a pas répondu), que cette page relit
 * pour ses avertissements sans appeler l'INSEE, et le mot de passe déjà
 * haché. Elle vaut 24 heures, ne sert qu'une fois, et s'efface 48 heures
 * après son expiration (voir app:purger-demandes-inscription), son code
 * d'activité avec elle.
 *
 * @property array{siren: string, type_activite: string, code_activite: string|null, nomenclature_activite: string|null, mot_de_passe: string} $donnees
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
        'expire_le' => Instant::class,
        'consommee_le' => Instant::class,
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
