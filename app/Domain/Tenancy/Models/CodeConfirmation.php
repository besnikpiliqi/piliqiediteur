<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\CanalEnvoi;
use App\Domain\Tenancy\Enums\MotifCode;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Un code de confirmation envoyé par courrier recommandé à l'adresse
 * officielle d'une entreprise, ou par lettre recommandée électronique
 * qualifiée à l'un de ses dirigeants (voir EnvoyerCodeDeConfirmationAction
 * et SaisirCodeDeConfirmationAction) : seule son empreinte (Hash::make) se
 * garde, jamais le code. Il est actif tant qu'il n'est ni utilisé, ni
 * annulé, ni expiré ; un seul l'est par SIREN et par motif dans tout le
 * logiciel.
 *
 * Seuls son suivi (référence, remise, retour), ses essais, son usage et
 * son annulation se posent ; ses autres colonnes ne se corrigent jamais,
 * et il ne s'efface pas.
 *
 * @property array<string, mixed> $destinataire
 */
class CodeConfirmation extends Model
{
    use BelongsToSociete;

    public const DUREE_DE_VALIDITE_EN_JOURS = 30;

    public const ESSAIS_PERMIS = 5;

    protected $table = 'codes_confirmation';

    protected $fillable = [];

    protected $hidden = ['code_hache'];

    protected $casts = [
        'motif' => MotifCode::class,
        'canal' => CanalEnvoi::class,
        'destinataire' => 'array',
        'envoye_le' => 'datetime',
        'remis_le' => 'datetime',
        'retourne_le' => 'datetime',
        'expire_le' => 'datetime',
        'tentatives' => 'integer',
        'utilise_le' => 'datetime',
        'annule_le' => 'datetime',
    ];

    /**
     * Les colonnes qui changent après l'envoi.
     */
    private const COLONNES_QUI_CHANGENT = ['reference_envoi', 'envoye_le', 'remis_le', 'retourne_le', 'tentatives', 'utilise_le', 'annule_le', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (CodeConfirmation $code): void {
            $corrigees = array_diff(array_keys($code->getDirty()), self::COLONNES_QUI_CHANGENT);

            if ($corrigees !== []) {
                throw new LogicException('Un code de confirmation ne se corrige jamais : '.implode(', ', $corrigees).'.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Un code de confirmation ne s\'efface jamais.');
        });
    }

    public function estActif(): bool
    {
        return $this->utilise_le === null && $this->annule_le === null && $this->expire_le->isFuture();
    }
}
