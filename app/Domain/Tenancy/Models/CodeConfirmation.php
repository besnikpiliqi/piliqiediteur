<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\MotifCode;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Un code de confirmation envoyé par courrier recommandé au représentant
 * légal d'une entreprise, à l'adresse officielle de son siège (voir
 * EnvoyerCodeDeConfirmationAction et SaisirCodeDeConfirmationAction) :
 * seule son empreinte (Hash::make) se garde, jamais le code. Il est actif
 * tant qu'il n'est ni utilisé, ni annulé, ni expiré ; un seul l'est par
 * SIREN et par motif dans tout le logiciel.
 *
 * Seuls son suivi (référence, remise, retour), ses essais, son usage, son
 * annulation et la fin de sa conservation se posent ; ses autres colonnes
 * ne se corrigent jamais. Il ne s'efface pas par le modèle, et la base
 * refuse son effacement avant la fin de sa conservation (voir
 * DeclencheursDeProtection::suppressionApresConservation()).
 *
 * @property array<string, mixed> $destinataire
 */
class CodeConfirmation extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'demande_par' => 'demande_par_nom',
        ];
    }

    public const DUREE_DE_VALIDITE_EN_JOURS = 30;

    protected $table = 'codes_confirmation';

    protected $fillable = [];

    protected $hidden = ['code_hache'];

    protected $casts = [
        'motif' => MotifCode::class,
        'destinataire' => 'array',
        'envoye_le' => Instant::class,
        'remis_le' => Instant::class,
        'retourne_le' => Instant::class,
        'expire_le' => Instant::class,
        'tentatives' => 'integer',
        'utilise_le' => Instant::class,
        'annule_le' => Instant::class,
        'conserver_jusqu_au' => Instant::class,
    ];

    /**
     * Les colonnes qui changent après l'envoi.
     */
    private const COLONNES_QUI_CHANGENT = ['reference_envoi', 'envoye_le', 'remis_le', 'retourne_le', 'tentatives', 'utilise_le', 'annule_le', 'conserver_jusqu_au', 'updated_at'];

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
