<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Le tenant. Volontairement PAS de BelongsToMagasin ici : un magasin ne
 * s'appartient pas à lui-même, et ce modèle doit rester accessible sans
 * contexte tenant résolu (ex: l'écran de sélection du magasin actif).
 */
class Magasin extends Model implements HasName
{
    /** @use HasFactory<MagasinFactory> */
    use HasFactory, SoftDeletes;

    /**
     * L'exemple proposé au magasin qui active les relances (Paramètres >
     * Relances) : il le modifie, le complète ou le réduit comme il veut.
     */
    public const DELAIS_RELANCE_PAR_DEFAUT = [7, 15, 30];

    protected $fillable = [
        'nom',
        'raison_sociale',
        'siren',
        'siret',
        'numero_tva',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'email',
        'telephone',
        'devise',
        'locale',
        'statut',
        'heure_cloture_automatique',
        'regime_tva',
        'option_debits_tva',
        'prix_vente_automatique',
        'bareme_prix_vente',
        'relances_automatiques',
        'delais_relance',
    ];

    protected $casts = [
        'statut' => StatutMagasin::class,
        'heure_cloture_automatique' => 'datetime:H:i',
        'regime_tva' => RegimeTva::class,
        'option_debits_tva' => 'boolean',
        'prix_vente_automatique' => 'boolean',
        'bareme_prix_vente' => BaremePrixDeVente::class,
        'relances_automatiques' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Magasin $magasin) {
            $magasin->uuid ??= (string) Str::uuid();
            $magasin->statut ??= StatutMagasin::EnAttenteVerification;
            $magasin->devise ??= 'EUR';
            $magasin->locale ??= 'fr';
            $magasin->pays ??= 'FR';
            $magasin->heure_cloture_automatique ??= '00:00';
            $magasin->prix_vente_automatique ??= false;
            $magasin->bareme_prix_vente ??= BaremePrixDeVente::parDefaut();
            $magasin->relances_automatiques ??= false;
            $magasin->delais_relance ??= self::DELAIS_RELANCE_PAR_DEFAUT;
        });
    }

    /**
     * Les délais de relance, en jours après l'échéance d'une facture :
     * toujours enregistrés dans l'ordre, sans doublon, la relance n°1 étant
     * celle du plus court (voir relanceDue()).
     *
     * @return Attribute<list<int>|null, list<int|string>>
     */
    protected function delaisRelance(): Attribute
    {
        return Attribute::make(
            get: fn (?string $valeur): ?array => $valeur === null ? null : json_decode($valeur, true, flags: JSON_THROW_ON_ERROR),
            set: function (array $delais): string {
                $jours = collect($delais)->map(fn (int|string $delai): int => (int) $delai)->unique()->sort()->values();

                if ($jours->contains(fn (int $delai): bool => $delai < 1)) {
                    throw new InvalidArgumentException('Une relance part au moins un jour après l\'échéance.');
                }

                return json_encode($jours->all(), JSON_THROW_ON_ERROR);
            },
        );
    }

    /**
     * La relance n°$niveau d'une facture échue depuis $joursDeRetard jours
     * est-elle due ? Les relances partent dans l'ordre, chacune à son délai
     * après l'échéance (avec 7, 15 et 30 jours : la n°2 pas avant 15 jours
     * de retard), et jamais plus près de la précédente que l'écart entre
     * leurs deux délais (8 jours entre la n°1 et la n°2) : une facture déjà
     * très en retard quand les relances commencent -- activées tard,
     * planificateur arrêté, relance en échec -- reçoit la n°1, puis les
     * suivantes au rythme choisi par le magasin, jamais toutes d'un coup.
     * Aucune au-delà du dernier délai.
     */
    public function relanceDue(int $niveau, int $joursDeRetard, ?int $joursDepuisLaPrecedente): bool
    {
        $delais = $this->delais_relance;

        if ($niveau < 1 || $niveau > count($delais) || $joursDeRetard < $delais[$niveau - 1]) {
            return false;
        }

        return $niveau === 1
            || $joursDepuisLaPrecedente === null
            || $joursDepuisLaPrecedente >= $delais[$niveau - 1] - $delais[$niveau - 2];
    }

    /**
     * Le barème avec lequel ses factures d'achat proposent les prix de
     * vente, une fois que le magasin a activé le calcul automatique
     * (désactivé au départ).
     */
    public function baremePrixDeVenteActif(): ?BaremePrixDeVente
    {
        return $this->prix_vente_automatique ? $this->bareme_prix_vente : null;
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'magasin_user')
            ->using(MagasinUser::class)
            ->withPivot(['role', 'statut', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function estActif(): bool
    {
        return $this->statut === StatutMagasin::Active;
    }

    /**
     * Les membres qui ont aujourd'hui accès au magasin : un rattachement
     * invité ou suspendu ne reçoit rien de lui (voir les notifications).
     */
    public function membresActifs(): BelongsToMany
    {
        return $this->utilisateurs()->wherePivot('statut', StatutMembreMagasin::Actif->value);
    }

    public function proprietaires(): BelongsToMany
    {
        return $this->membresActifs()->wherePivot('role', RoleMagasin::Proprietaire->value);
    }

    // Les relations ci-dessous existent avant tout pour la convention de
    // tenancy de Filament (Resource::$tenantOwnershipRelationshipName) :
    // elles ne sont PAS le mécanisme d'isolation réel, qui reste
    // BelongsToMagasin + MagasinScope (seul mécanisme actif en dehors de
    // Filament : API, jobs, console). Les deux restent cohérents puisque
    // la création passe toujours par le TenantContext déjà positionné.
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function fournisseurs(): HasMany
    {
        return $this->hasMany(Fournisseur::class);
    }

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function devis(): HasMany
    {
        return $this->hasMany(Devis::class);
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class);
    }

    public function cloturesCaisse(): HasMany
    {
        return $this->hasMany(ClotureCaisse::class);
    }

    public function facturesAchat(): HasMany
    {
        return $this->hasMany(FactureAchat::class);
    }

    public function avoirsFournisseur(): HasMany
    {
        return $this->hasMany(AvoirFournisseur::class);
    }

    public function soumissionsEReporting(): HasMany
    {
        return $this->hasMany(SoumissionEReporting::class);
    }

    /**
     * Même remarque que ci-dessus : sert la convention de tenancy
     * Filament pour MembreEquipeResource (modèle = MagasinUser).
     */
    public function membres(): HasMany
    {
        return $this->hasMany(MagasinUser::class);
    }

    public function getFilamentName(): string
    {
        return $this->nom;
    }
}
