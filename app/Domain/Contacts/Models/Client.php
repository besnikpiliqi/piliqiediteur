<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Contacts\Concerns\Proposable;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Casts\NumeroTvaNormalise;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\TerritoireFiscal;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use LogicException;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use BelongsToMagasin, HasFactory, Proposable, SoftDeletes;

    /**
     * Nom du client comptoir créé pour chaque magasin (voir
     * CreerMagasinAction) : une seule fiche partagée par toutes les ventes
     * au comptoir anonymes, jamais une personne identifiée. Le comptoir se
     * reconnaît à est_comptoir, jamais à ce nom.
     */
    public const NOM_COMPTOIR = 'Client comptoir';

    // magasin_id est volontairement absent : il est toujours dérivé du
    // TenantContext par le trait BelongsToMagasin, jamais du payload client.
    // est_comptoir aussi : seul CreerMagasinAction le pose.
    protected $fillable = [
        'nom',
        'type_client',
        'siren',
        'siret',
        'numero_tva',
        'etabli_en_france',
        'identifiant_etranger',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'telephone',
        'email',
        'delai_paiement_jours',
        'mode_paiement_prefere',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'etabli_en_france' => true,
    ];

    protected $casts = [
        'type_client' => TypeClient::class,
        'numero_tva' => NumeroTvaNormalise::class,
        'etabli_en_france' => 'boolean',
        'delai_paiement_jours' => 'integer',
        'mode_paiement_prefere' => ModePaiement::class,
        'est_comptoir' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(fn (Client $client) => $client->normaliserSesIdentifiants());

        static::updating(function (Client $client) {
            if ($client->estComptoir() && $client->isDirty(['nom', 'type_client'])) {
                throw new LogicException('Le client comptoir ne se renomme pas et reste un particulier.');
            }
        });

        // Supprimer un client l'archive (SoftDeletes) : jamais le client
        // comptoir, que portent toutes les ventes anonymes du magasin.
        static::deleting(function (Client $client) {
            if ($client->estComptoir()) {
                throw new LogicException('Le client comptoir ne s\'archive jamais.');
            }
        });
    }

    public function estProfessionnel(): bool
    {
        return $this->type_client === TypeClient::Professionnel;
    }

    /**
     * Établi en France, un professionnel a un SIREN ; établi ailleurs, un
     * n° de TVA ou un autre identifiant (voir ClientResource). Une
     * entreprise dont l'adresse est en France y est établie (voir
     * normaliserSesIdentifiants()) ; à l'étranger, c'est la fiche qui le
     * dit, puisqu'une entreprise étrangère peut avoir un établissement en
     * France.
     */
    public function estEtabliEnFrance(): bool
    {
        return $this->etabli_en_france;
    }

    /**
     * Les identifiants tels qu'ils s'enregistrent : le pays en majuscules,
     * le SIREN tiré au besoin du SIRET, dont il forme les 9 premiers
     * chiffres (le n° de TVA, lui, perd ses séparateurs dès qu'il est
     * écrit, voir NumeroTvaNormalise). Les champs que le formulaire cache
     * ne restent pas en base pour s'imprimer encore sur ses documents : un
     * particulier n'a ni SIREN, ni SIRET, ni n° de TVA et compte comme
     * établi en France, comme une entreprise dont l'adresse est en France ;
     * établi en France, un client n'a pas d'identifiant étranger.
     */
    private function normaliserSesIdentifiants(): void
    {
        if (filled($this->pays)) {
            $this->pays = Str::upper(trim($this->pays));
        }

        if (blank($this->siren) && filled($this->siret)) {
            $this->siren = substr($this->siret, 0, 9);
        }

        if (! $this->estProfessionnel()) {
            $this->siren = null;
            $this->siret = null;
            $this->numero_tva = null;
        }

        if (! $this->estProfessionnel() || TerritoireFiscal::estEnFrance($this->pays)) {
            $this->etabli_en_france = true;
        }

        if ($this->estEtabliEnFrance()) {
            $this->identifiant_etranger = null;
        }
    }

    /**
     * La fiche partagée par les ventes au comptoir anonymes du magasin
     * (voir CreerMagasinAction) : la seule où est_comptoir est vrai, quel
     * que soit le nom d'une autre fiche.
     */
    public function estComptoir(): bool
    {
        return $this->est_comptoir === true;
    }

    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class);
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    /**
     * Les clients à qui un paiement s'enregistre : archivé, un client n'est
     * plus proposé pour une vente, mais il règle toujours ce qu'il doit
     * encore (voir Facture::client()), en un seul paiement s'il le veut
     * (voir PaiementResource).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeQuiPeuventRegler(Builder $query): Builder
    {
        return $query->withTrashed()->where(fn (Builder $clients): Builder => $clients
            ->whereNull($this->getQualifiedDeletedAtColumn())
            ->orWhereHas('factures', fn (Builder $factures): Builder => $factures->whereIn('statut', StatutFacture::quiAttendentUnReglement())));
    }

    /**
     * Les clients à qui un avoir se fait : archivé, un client n'est plus
     * proposé pour une vente, mais ce qu'il a acheté se retourne toujours,
     * sur plusieurs documents à la fois s'il le faut (voir AvoirResource).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeQuiPeuventEtreCredites(Builder $query): Builder
    {
        return $query->withTrashed()->where(fn (Builder $clients): Builder => $clients
            ->whereNull($this->getQualifiedDeletedAtColumn())
            ->orWhereHas('factures', fn (Builder $factures): Builder => $factures->emises()->whereHas('lignes', fn (Builder $lignes): Builder => $lignes->creditables())));
    }

    /**
     * Crédit encore disponible pour ce client, tous avoirs confondus,
     * particulier ou professionnel : un avoir n'est jamais remboursé (B5).
     * À son émission, il règle d'abord les factures qu'il corrige, puis les
     * autres factures impayées du client, de la plus ancienne à la plus
     * récente (voir ImputerAvoirSurSesFacturesAction) ; ce qui reste est ce
     * crédit, comme celui que la contre-passation d'un paiement rend à ses
     * avoirs (voir ContrePasserPaiementAction). La prochaine facture du
     * client l'absorbe (voir AppliquerAvoirsDisponiblesAction), ou un
     * paiement le solde sur les factures qu'il règle (voir
     * EnregistrerPaiementAction). Il peut donc coexister avec des factures
     * dues : celles qu'une contre-passation a rouvertes sans y imputer ce
     * crédit, qui attend le bon paiement (K9).
     *
     * Le client comptoir (voir estComptoir()) n'a jamais de solde
     * créditeur : il est partagé par toutes les ventes anonymes du magasin,
     * et le crédit d'un avoir fait au comptoir n'appartient qu'à qui en
     * présente le numéro, son bon d'avoir (voir
     * EnregistrerVenteCaisseAction), jamais à la vente comptoir suivante.
     */
    public function soldeCrediteur(): float
    {
        if ($this->estComptoir()) {
            return 0.0;
        }

        return app(CalculateurLigne::class)->sommer(
            $this->avoirs()
                ->where('statut', StatutAvoir::Emise)
                ->get()
                ->map(fn (Avoir $avoir) => $avoir->montantNonAlloue()),
        );
    }
}
