<?php

namespace App\Domain\Achats\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * Une pièce du fournisseur (décision de l'éditeur du 03/11/2026, lot EX) :
 * la vraie facture d'achat ou la vraie note d'avoir du fournisseur, en PDF
 * ou en photo, contrôlée à son dépôt (voir ControleDesFichiers), jointe à
 * une facture d'achat ou à un avoir fournisseur du magasin (voir
 * PiecesDuFournisseur). Elle ne se modifie jamais : seuls l'instant de sa
 * dernière analyse par l'antivirus et son blocage, une fois, s'écrivent
 * par PiecesDuFournisseur::contenuServable() ; elle ne s'efface que d'une
 * facture d'achat encore en brouillon, et la base le garantit (voir
 * GardesDesPiecesDuFournisseur). Elle se télécharge en pièce jointe, par
 * son adresse, désignée par son uuid tiré au hasard, pour qui peut voir
 * son document, sauf bloquée (voir
 * TelechargerUnePieceDuFournisseurController).
 */
class PieceDuFournisseur extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs;

    protected $table = 'pieces_fournisseur';

    protected $fillable = [];

    protected $hidden = ['chemin'];

    protected $casts = [
        'taille' => 'integer',
        'analysee_le' => Instant::class,
        'bloquee_le' => Instant::class,
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'ajoutee_par' => 'ajoutee_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PieceDuFournisseur $piece): void {
            $piece->uuid ??= (string) Str::uuid();
        });

        static::updating(function (): never {
            throw new LogicException('Une pièce du fournisseur ne se modifie jamais : elle se retire d\'un brouillon, ou une autre s\'ajoute ; sa dernière analyse et son blocage par l\'antivirus s\'écrivent par PiecesDuFournisseur.');
        });
    }

    /**
     * Une pièce se désigne dans une adresse par son uuid, jamais par son
     * identifiant.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Celle de cet uuid, venu d'une adresse : un texte qui n'est pas un
     * uuid ne désigne rien, sans interroger la base, où PostgreSQL le
     * refuserait.
     *
     * @param  Builder<PieceDuFournisseur>  $requete
     * @return Builder<PieceDuFournisseur>
     */
    public function scopeParUuid(Builder $requete, string $uuid): Builder
    {
        return Str::isUuid($uuid) ? $requete->where('uuid', $uuid) : $requete->whereRaw('1 = 0');
    }

    /**
     * @return BelongsTo<FactureAchat, $this>
     */
    public function factureAchat(): BelongsTo
    {
        return $this->belongsTo(FactureAchat::class);
    }

    /**
     * @return BelongsTo<AvoirFournisseur, $this>
     */
    public function avoirFournisseur(): BelongsTo
    {
        return $this->belongsTo(AvoirFournisseur::class);
    }

    /**
     * Le document auquel elle est jointe.
     */
    public function document(): FactureAchat|AvoirFournisseur
    {
        return $this->facture_achat_id !== null ? $this->factureAchat()->firstOrFail() : $this->avoirFournisseur()->firstOrFail();
    }

    /**
     * Sa taille telle qu'elle s'affiche : « 245 Ko », « 3,2 Mo ».
     */
    public function tailleLisible(): string
    {
        return $this->taille < 1024 * 1024
            ? max(1, (int) round($this->taille / 1024)).' Ko'
            : str_replace('.', ',', (string) round($this->taille / (1024 * 1024), 1)).' Mo';
    }
}
