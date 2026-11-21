<?php

namespace App\Domain\Charges\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

/**
 * La pièce d'une charge (décision de l'éditeur du 14/11/2026, lot
 * CHARGES) : la quittance, la facture ou le ticket, en PDF ou en photo,
 * contrôlée à son dépôt comme une pièce du fournisseur (voir
 * ControleDesFichiers), une seule par charge (voir PiecesDesCharges). Elle
 * ne se modifie jamais : seuls l'instant de sa dernière analyse par
 * l'antivirus et son blocage, une fois, s'écrivent par
 * PiecesDesCharges::contenuServable() ; elle se remplace tant que sa
 * charge n'est pas validée, et la base le garantit (voir
 * GardesDesCharges). Elle se télécharge en pièce jointe, jamais affichée
 * dans une page, pour qui voit sa charge, sauf bloquée.
 *
 * @property string $uuid
 * @property int $charge_id
 * @property string $nom
 * @property string $type_mime
 * @property int $taille
 * @property string $empreinte
 * @property string $chemin
 */
class PieceDeCharge extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    protected $table = 'pieces_de_charge';

    protected $fillable = [];

    protected $hidden = ['chemin'];

    protected $casts = [
        'charge_id' => 'integer',
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
        static::creating(function (PieceDeCharge $piece): void {
            $piece->uuid ??= (string) Str::uuid();
        });

        static::updating(function (): never {
            throw new LogicException('La pièce d\'une charge ne se modifie jamais : elle se remplace tant que la charge n\'est pas validée ; sa dernière analyse et son blocage par l\'antivirus s\'écrivent par PiecesDesCharges.');
        });
    }

    /**
     * @return BelongsTo<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
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
