<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Models\User;
use Database\Factories\MandatPdpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pas de BelongsToMagasin : comme SequenceDocument, c'est une donnée
 * d'infrastructure/configuration interrogée explicitement par magasin_id
 * (ex: depuis un listener de queue avant que le TenantContext de la
 * facture ne soit nécessairement pertinent), pas une ressource métier
 * exposée telle quelle via une Policy utilisateur.
 */
class MandatPdp extends Model
{
    /** @use HasFactory<MandatPdpFactory> */
    use HasFactory;

    protected $table = 'mandats_pdp';

    protected $fillable = [
        'magasin_id',
        'prestataire_pdp',
        'mandat_externe_id',
        'statut',
        'perimetre',
        'accorde_par',
        'consentement_ip',
        'consentement_user_agent',
        'consentement_a',
        'active_a',
        'revoque_a',
        'reponse_brute',
    ];

    protected $casts = [
        'statut' => StatutMandatPdp::class,
        'consentement_a' => Instant::class,
        'active_a' => Instant::class,
        'revoque_a' => Instant::class,
        'reponse_brute' => 'array',
    ];

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }

    public function accordePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accorde_par');
    }
}
