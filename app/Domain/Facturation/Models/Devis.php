<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\EstEnvoyeParEmail;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\DevisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Devis extends Model implements DocumentEnvoyable
{
    /** @use HasFactory<DevisFactory> */
    use BelongsToMagasin, EstEnvoyeParEmail, HasFactory, SoftDeletes, VentileLaTvaParTaux;

    protected $table = 'devis';

    // numero, statut, date_emission, les totaux, le type du client et la
    // franchise de TVA sont volontairement absents : ils ne sont écrits que
    // par les actions des devis (CreerDevisAction, ModifierDevisAction,
    // EmettreDevisAction...), jamais par mass assignment depuis une requête
    // utilisateur.
    protected $fillable = [
        'client_id',
        'date_validite',
        'devise',
        'conditions_paiement',
        'notes',
    ];

    protected $casts = [
        'statut' => StatutDevis::class,
        'type_client' => TypeClient::class,
        'franchise_en_base' => 'boolean',
        'date_emission' => 'datetime',
        'date_validite' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Devis $devis) {
            $devis->statut ??= StatutDevis::Brouillon;
            $devis->devise ??= 'EUR';
        });
    }

    /**
     * Un document légal garde son client, même archivé depuis (voir les
     * SoftDeletes de Client) : il se réimprime et se renvoie. Un devis ne
     * fige pas son client : il imprime sa fiche telle qu'elle est, son nom,
     * son adresse et, si elle est celle d'un professionnel, ses
     * identifiants. Le type écrit au dernier enregistrement du devis
     * (type_client) ne sert qu'à la facture qui en sortira (voir
     * CreerFactureDepuisDevisAction).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class)->orderBy('ordre');
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function libelleDocument(): string
    {
        return 'Devis';
    }

    /**
     * Quel que soit son statut : un brouillon reçoit son numéro au moment
     * de partir (voir EnvoyerDocumentAction), un devis déjà envoyé, accepté
     * ou refusé peut toujours être renvoyé à son client.
     */
    public function peutEtreEnvoye(): bool
    {
        return true;
    }

    /**
     * Un devis reste modifiable quel que soit son propre statut
     * (brouillon, envoyé, accepté, refusé...) tant qu'il n'a jamais servi
     * à créer une facture (voir CreerFactureDepuisDevisAction) : rien
     * n'empêche légalement de corriger ou de relancer un devis qui n'a pas
     * encore donné lieu à une vente réelle. Une fois converti, il devient
     * définitivement figé, "validé" par cette conversion elle-même.
     */
    public function estModifiable(): bool
    {
        return $this->factures()->doesntExist();
    }
}
