<?php

namespace App\Domain\Contacts\Models;

use App\Domain\Contacts\Concerns\Proposable;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
        'siret',
        'numero_tva',
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

    protected $casts = [
        'type_client' => TypeClient::class,
        'est_comptoir' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Un particulier n'a ni SIRET ni numéro de TVA : repassé de
        // professionnel à particulier, il ne garde pas ceux qu'il avait.
        static::saving(function (Client $client) {
            if (! $client->estProfessionnel()) {
                $client->siret = null;
                $client->numero_tva = null;
            }
        });

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

    /**
     * Crédit encore disponible pour ce client, tous avoirs confondus
     * (particulier ou professionnel : aucune intégration bancaire pour
     * rembourser par virement/espèces, voir AppliquerAvoirsDisponiblesAction
     * -- ce qu'un avoir n'a pas imputé sur ses propres factures, voir
     * ImputerAvoirSurSesFacturesAction, reste un crédit à appliquer sur une
     * prochaine facture jusqu'à épuisement).
     *
     * Exception volontaire : le client comptoir générique (voir
     * estComptoir()) n'a jamais de solde créditeur. Il est partagé par
     * toutes les ventes anonymes du magasin -- un avoir émis "pour lui"
     * n'a aucun client identifiable à qui l'appliquer plus tard, et
     * l'appliquer au hasard sur la prochaine vente comptoir reviendrait à
     * donner le remboursement d'une personne à une autre.
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
