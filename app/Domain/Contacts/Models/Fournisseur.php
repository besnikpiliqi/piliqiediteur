<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Contacts\Concerns\ProposableSiActif;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\FournisseurFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    /** @use HasFactory<FournisseurFactory> */
    use BelongsToMagasin, HasFactory, ProposableSiActif, SoftDeletes;

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
        'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];
}
