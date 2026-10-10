<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\CaisseSecurisee\Concerns\JournaliseSesParametresDeConformite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Casts\NumeroTvaNormalise;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Services\CodeMagasin;
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
use LogicException;

/**
 * Le tenant. Volontairement PAS de BelongsToMagasin ici : un magasin ne
 * s'appartient pas à lui-même, et ce modèle doit rester accessible sans
 * contexte tenant résolu (ex: l'écran de sélection du magasin actif).
 *
 * Son régime de TVA et son option pour les débits sont des paramètres
 * ayant un impact sur la conformité : chaque changement s'inscrit au
 * journal des événements du magasin (JET 270, voir
 * JournaliseSesParametresDeConformite).
 *
 * Son code court (voir CodeMagasin) figure dans tous les numéros de ses
 * documents : proposé depuis son nom s'il n'est pas choisi, il ne change
 * plus dès son premier document (voir codeEstDefinitif()).
 *
 * Son statut (voir StatutMagasin) ne vient jamais d'un formulaire du
 * magasin : actif à la création, il ne changera que par l'administration
 * de l'éditeur (étape 13 de la feuille de route). Un magasin ne se
 * supprime jamais définitivement (voir forceDelete() et le déclencheur de
 * la table magasins).
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
        'code',
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
        'regime_tva',
        'option_debits_tva',
        'prix_vente_automatique',
        'bareme_prix_vente',
        'relances_automatiques',
        'delais_relance',
    ];

    protected $casts = [
        'statut' => StatutMagasin::class,
        'numero_tva' => NumeroTvaNormalise::class,
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
            $magasin->code ??= CodeMagasin::proposer($magasin->nom);
            $magasin->statut ??= StatutMagasin::Active;
            $magasin->pays ??= 'FR';
            $magasin->option_debits_tva ??= false;
            $magasin->prix_vente_automatique ??= false;
            $magasin->bareme_prix_vente ??= BaremePrixDeVente::parDefaut();
            $magasin->relances_automatiques ??= false;
            $magasin->delais_relance ??= self::DELAIS_RELANCE_PAR_DEFAUT;
        });

        static::updating(function (Magasin $magasin) {
            if ($magasin->isDirty('code')) {
                $magasin->verifierQueSonCodePeutChanger();
            }
        });
    }

    /**
     * Le code est-il définitif ? Il l'est dès qu'une série de numérotation
     * de document existe pour le magasin, la première s'étant créée avec
     * son premier numéro, code compris (voir DocumentNumberingService). Les
     * références de produits (PRD-000001), qui ne portent pas le code, ne
     * comptent pas. sequences_documents n'a pas de MagasinScope : le
     * filtre par magasin est explicite.
     */
    public function codeEstDefinitif(): bool
    {
        return SequenceDocument::query()
            ->where('magasin_id', $this->getKey())
            ->where('type_document', '!=', TypeDocument::Produit->value)
            ->exists();
    }

    /**
     * Refuse le changement d'un code définitif. La ligne du magasin se
     * verrouille d'abord : dans la transaction du changement (voir
     * IdentiteMagasin::save()), une première série de numérotation qui se
     * crée en même temps, et relit le code sous un verrou partagé, attend
     * que le changement soit validé et prend le nouveau code ; ou bien le
     * changement attend la fin de la transaction qui a créé la série, et
     * la voit.
     *
     * @throws LogicException
     */
    private function verifierQueSonCodePeutChanger(): void
    {
        static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->value($this->getKeyName());

        if ($this->codeEstDefinitif()) {
            throw new LogicException('Le code du magasin figure déjà dans les numéros de ses documents : il ne change plus.');
        }
    }

    /**
     * Un magasin ne s'efface jamais de la base : ses documents se
     * conservent dix ans, même après sa résiliation, et son SIRET ne sert
     * jamais à un autre magasin (voir l'index unique de magasins.siret).
     * Supprimé, il reste en base avec deleted_at. Le modèle refuse ici,
     * même sans événements (forceDeleteQuietly()) ; la base refuse
     * elle-même ce qui ne passe pas par lui, forceDelete() du constructeur
     * de requêtes ou DB::table() compris (voir
     * DeclencheursDeProtection::sansSuppression()).
     *
     * @throws LogicException
     */
    public function forceDelete(): never
    {
        throw new LogicException('Un magasin ne se supprime jamais définitivement : ses documents sont conservés et son SIRET ne servira jamais à un autre magasin.');
    }

    /**
     * Garde métier de ce que le magasin fait encore, quel que soit l'écran
     * ou la commande qui le demande : suspendu ou fermé, il n'émet plus
     * rien, n'encaisse plus, ne valide plus d'achat et n'écrit plus à ses
     * clients (voir RegistreFiscal et les actions qui l'appellent). Ses
     * clôtures, les statuts de ses factures et ses déclarations, eux,
     * continuent.
     *
     * @throws LogicException
     */
    public function verifierQuIlEstOuvert(): void
    {
        if ($this->statut->estEnLectureSeule()) {
            $statut = mb_strtolower($this->statut->getLabel());

            throw new LogicException("Ce magasin est {$statut} : seules la consultation et les téléchargements restent possibles.");
        }
    }

    public static function parametresDeConformite(): array
    {
        return ['regime_tva', 'option_debits_tva'];
    }

    protected function magasinIdDesParametres(): int
    {
        return $this->getKey();
    }

    /**
     * Le code s'enregistre toujours en majuscules, sans espace autour ;
     * laissé vide à la création, il est proposé depuis le nom (voir
     * booted()).
     *
     * @return Attribute<string, string|null>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (?string $code): ?string => $code === null ? null : CodeMagasin::normaliser($code),
        );
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
