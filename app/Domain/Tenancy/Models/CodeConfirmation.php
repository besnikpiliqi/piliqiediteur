<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\MotifCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
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
 * Le code d'un courrier d'une procédure de l'éditeur, une fois saisi,
 * prouve la remise du courrier dans les trente jours qui suivent sa saisie
 * (voir preuveDeLaRemise()), et pour une seule procédure : la révocation de
 * sa société, ou la libération du seul SIRET qu'il désigne (siret), après
 * quoi il ne prouve plus rien (procedure_faite_le) ; l'examen d'un SIREN,
 * que l'éditeur peut reprendre sur le même courrier tant que sa preuve
 * vaut, ne le consomme pas.
 *
 * Seuls son suivi (référence, remise, retour), ses essais, son usage, la
 * procédure qu'il a prouvée, son annulation et la fin de sa conservation
 * se posent, celle-ci à la fin de la relation avec sa société, ou à son
 * envoi quand elle a déjà pris fin (voir FinDeLaRelation) ; ses autres
 * colonnes ne se corrigent jamais. Il ne s'efface pas par le modèle : la
 * seule purge de fin de conservation l'efface (voir
 * app:purger-verifications), et la base refuse son effacement avant (voir
 * DeclencheursDeProtection::suppressionApresConservation()).
 *
 * Son envoi et son échéance (voir Instant) ne sont jamais nuls : leurs
 * colonnes ne le sont pas en base.
 *
 * @property array<string, mixed> $destinataire
 * @property CarbonImmutable $envoye_le
 * @property CarbonImmutable $expire_le
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

    /**
     * Combien de jours, après sa saisie, le code d'un courrier d'une
     * procédure de l'éditeur prouve la remise du courrier pour la procédure
     * qui le suit (choix de Claude, que l'éditeur peut contester) : la même
     * durée que pour le saisir.
     */
    public const DUREE_DE_LA_PREUVE_EN_JOURS = 30;

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
        'procedure_faite_le' => Instant::class,
        'annule_le' => Instant::class,
        'conserver_jusqu_au' => Instant::class,
    ];

    /**
     * Les colonnes qui changent après l'envoi.
     */
    private const COLONNES_QUI_CHANGENT = ['reference_envoi', 'envoye_le', 'remis_le', 'retourne_le', 'tentatives', 'utilise_le', 'procedure_faite_le', 'annule_le', 'conserver_jusqu_au', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (CodeConfirmation $code): void {
            $corrigees = array_diff(array_keys($code->getDirty()), self::COLONNES_QUI_CHANGENT);

            if ($corrigees !== []) {
                throw new LogicException('Un code de confirmation ne se corrige jamais : '.implode(', ', $corrigees).'.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Un code de confirmation ne s\'efface jamais par le modèle : seule la purge de fin de conservation l\'efface.');
        });
    }

    public function estActif(): bool
    {
        return $this->utilise_le === null && $this->annule_le === null && $this->expire_le->isFuture();
    }

    /**
     * La preuve de la remise d'un courrier de ce motif d'une procédure de
     * l'éditeur, dans la société active, relue sous son verrou dans la
     * transaction de la procédure : le dernier code de ce motif saisi
     * depuis moins de DUREE_DE_LA_PREUVE_EN_JOURS jours, qui n'a encore
     * prouvé aucune procédure, et qui désigne ce SIREN ou ce SIRET quand on
     * les donne ; null s'il n'y en a aucun. Une révocation ou une
     * libération le consomme (voir aProuveLaProcedure()).
     */
    public static function preuveDeLaRemise(MotifCode $motif, ?string $siren = null, ?string $siret = null): ?self
    {
        return self::query()
            ->where('motif', $motif->value)
            ->whereNotNull('utilise_le')
            ->whereNull('procedure_faite_le')
            ->where('utilise_le', '>', Calendrier::deParis()->decaler(now(), CarbonInterval::days(self::DUREE_DE_LA_PREUVE_EN_JOURS)->invert()))
            ->when($siren !== null, fn (Builder $codes): Builder => $codes->where('siren', $siren))
            ->when($siret !== null, fn (Builder $codes): Builder => $codes->where('siret', $siret))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    /**
     * La procédure que ce code prouvait est faite, dans sa transaction : il
     * ne prouve plus rien.
     */
    public function aProuveLaProcedure(): void
    {
        $this->forceFill(['procedure_faite_le' => now()])->save();
    }
}
