<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\CaisseSecurisee\Concerns\JournaliseSesParametresDeConformite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
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
 *
 * Son régime de TVA et son option pour les débits sont des paramètres
 * ayant un impact sur la conformité : chaque changement s'inscrit au
 * journal des événements du magasin (JET 270, voir
 * JournaliseSesParametresDeConformite).
 */
class Magasin extends Model implements HasName
{
    /** @use HasFactory<MagasinFactory> */
    use HasFactory, JournaliseSesParametresDeConformite, SoftDeletes;

    /**
     * L'exemple proposé au magasin qui active les relances (Paramètres >
     * Relances) : il le modifie, le complète ou le réduit comme il veut.
     */
    public const DELAIS_RELANCE_PAR_DEFAUT = [7, 15, 30];

    protected $fillable = [
        'nom',
        'raison_sociale',
        'forme_juridique',
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
        'statut',
        'regime_tva',
        'option_debits_tva',
        'prix_vente_automatique',
        'bareme_prix_vente',
        'relances_automatiques',
        'delais_relance',
    ];

    protected $casts = [
        'statut' => StatutMagasin::class,
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
            $magasin->pays ??= 'FR';
            $magasin->option_debits_tva ??= false;
            $magasin->prix_vente_automatique ??= false;
            $magasin->bareme_prix_vente ??= BaremePrixDeVente::parDefaut();
            $magasin->relances_automatiques ??= false;
            $magasin->delais_relance ??= self::DELAIS_RELANCE_PAR_DEFAUT;
        });
    }

    protected function parametresDeConformite(): array
    {
        return ['regime_tva', 'option_debits_tva'];
    }

    protected function magasinIdDesParametres(): int
    {
        return $this->getKey();
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
            ->withPivot(['role', 'statut', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Les membres qui ont aujourd'hui accès au magasin : un rattachement
     * suspendu ne reçoit rien de lui (voir les notifications).
     */
    public function membresActifs(): BelongsToMany
    {
        return $this->utilisateurs()->wherePivot('statut', StatutMembreMagasin::Actif->value);
    }

    public function proprietaires(): BelongsToMany
    {
        return $this->membresActifs()->wherePivot('role', RoleMagasin::Proprietaire->value);
    }

    /**
     * clients(), fournisseurs(), produits() et services() servent aux
     * créations que Filament enregistre lui-même (CreateClient,
     * CreateFournisseur, CreateProduit et la fenêtre « Nouveau » de
     * ManageServices) : il y rattache la fiche au magasin par la relation
     * que nomme Resource::getTenantRelationshipName(), le pluriel du
     * modèle. Les créations qui surchargent handleRecordCreation() ne s'en
     * servent pas. L'isolation, elle, reste BelongsToMagasin + MagasinScope.
     */
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

    /**
     * Les rattachements des membres de l'équipe (voir CreateMembreEquipe et
     * EmailLibrePourUnNouveauMembre).
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
