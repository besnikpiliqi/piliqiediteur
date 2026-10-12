<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Enums\SignalDeRisque;
use App\Domain\Tenancy\Enums\TypeVerification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Une ligne du registre des vérifications d'une société : chaque contrôle
 * d'un fournisseur (le répertoire Sirene de l'INSEE, le registre national
 * des entreprises, la vérification d'identité, l'envoi recommandé),
 * chaque décision de l'éditeur et chaque signal de risque constaté, avec
 * les seuls détails que retient son type (voir
 * TypeVerification::detailsRetenus()), le signal qu'elle constate ou lève,
 * et la personne dont elle prouve l'identité ou la qualité (beneficiaire).
 * L'état de la vérification se tire de ces lignes (voir
 * EvaluateurDeVerification).
 *
 * Une ligne ne se modifie jamais et ne s'efface qu'une fois sa durée de
 * conservation fixée et passée ; la base refuse tout le reste (voir
 * DeclencheursDeProtection::effacementApresExpiration()).
 */
class VerificationSociete extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'auteur_id' => 'auteur_nom',
            'beneficiaire_id' => 'beneficiaire_nom',
        ];
    }

    protected $table = 'verifications_societe';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'type' => TypeVerification::class,
        'resultat' => ResultatVerification::class,
        'signal' => SignalDeRisque::class,
        'details' => 'array',
        'operateur' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (VerificationSociete $verification): void {
            $inconnus = array_diff(array_keys($verification->details ?? []), $verification->type->detailsRetenus());

            if ($inconnus !== []) {
                throw new LogicException('Une vérification ne retient que les détails de la liste de son type : '.implode(', ', $inconnus).'.');
            }
        });

        static::updating(function (): never {
            throw new LogicException('Une vérification ne se modifie jamais.');
        });
    }

    /**
     * @return BelongsTo<Magasin, $this>
     */
    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function beneficiaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiaire_id');
    }

    public function estConcluante(): bool
    {
        return $this->resultat === ResultatVerification::Concluante;
    }

    /**
     * La ligne vaut-elle pour ce SIREN ? Une preuve tenant à l'entreprise
     * (rapprochement des dirigeants, mandat, plateforme, code d'un courrier
     * ou d'une lettre électronique, décision de l'éditeur) ne vaut que pour
     * le SIREN qu'elle a retenu (voir TypeVerification::detailsRetenus()) :
     * après une correction du SIREN avant la validation, celles de l'ancien
     * ne prouvent rien du nouveau.
     */
    public function vautPourLeSiren(?string $siren): bool
    {
        return $siren !== null && ($this->details['siren'] ?? null) === $siren;
    }
}
