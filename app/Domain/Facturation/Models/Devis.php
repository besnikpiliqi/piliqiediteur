<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Concerns\EstEnvoyeParEmail;
use App\Domain\Facturation\Concerns\NoteSaNatureDOperation;
use App\Domain\Facturation\Concerns\VentileLaTvaParTaux;
use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\DevisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use LogicException;

class Devis extends Model implements DocumentEnvoyable
{
    /** @use HasFactory<DevisFactory> */
    use BelongsToMagasin, EstEnvoyeParEmail, HasFactory, NoteSaNatureDOperation, SoftDeletes, VentileLaTvaParTaux;

    public const REFUS_SUPPRESSION = 'Ce devis ne peut pas être supprimé : seul un brouillon jamais envoyé se supprime, un devis numéroté reste.';

    public const REFUS_INTROUVABLE = 'Ce devis n\'existe pas, ou plus, dans ce magasin.';

    protected $table = 'devis';

    // numero, statut, date_emission, les totaux, le type du client, la
    // franchise de TVA, la nature d'opération et la livraison sont
    // volontairement absents : ils ne sont écrits que par les actions des
    // devis (CreerDevisAction, ModifierDevisAction, EmettreDevisAction...),
    // jamais par mass assignment depuis une requête utilisateur.
    protected $fillable = [
        'client_id',
        'date_validite',
        'devise',
        'conditions_paiement',
        'notes',
    ];

    /**
     * La vente en France tant qu'aucune nature n'est notée, comme en base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'nature_operation' => 'vente_france',
    ];

    protected $casts = [
        'statut' => StatutDevis::class,
        'type_client' => TypeClient::class,
        'franchise_en_base' => 'boolean',
        'nature_operation' => NatureOperation::class,
        'livraison_date' => JourCivil::class,
        'transport_par' => TransportPar::class,
        'date_emission' => Instant::class,
        'date_validite' => JourCivil::class,
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
     * Le dernier rempart, derrière SupprimerDevisAction et
     * DevisPolicy::delete() : un devis
     * numéroté reste, quel que soit le chemin qui tente de le supprimer.
     * Son état est relu en base, sous un verrou tenu jusqu'à l'écriture de
     * la suppression : un devis chargé brouillon (lignes cochées d'une
     * suppression en masse, autre onglet), puis numéroté par une autre
     * requête avant d'être supprimé, reste lui aussi (voir
     * EmettreDevisAction, qui le relit sous le même verrou). Un devis
     * introuvable dans le magasin actif, ou déjà supprimé, ne se supprime
     * pas non plus.
     *
     * @throws LogicException
     */
    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $enBase = static::query()->whereKey($this->getKey())->lockForUpdate()->first()
                ?? throw new LogicException(self::REFUS_INTROUVABLE);

            if (! $enBase->estSupprimable()) {
                throw new LogicException(self::REFUS_SUPPRESSION);
            }

            return parent::delete();
        });
    }

    /**
     * Un document légal garde son client, même archivé depuis (voir les
     * SoftDeletes de Client) : il se réimprime et se renvoie. Un devis ne
     * fige pas son client : il imprime sa fiche telle qu'elle est, son nom,
     * son adresse et ses identifiants. Le type écrit au dernier
     * enregistrement du devis (type_client, voir estPourUnProfessionnel())
     * ne change pas ses montants : son PDF présente le client selon ce type,
     * avec ses identifiants pour un professionnel, et la facture qui en
     * sortira le reprend (voir CreerFactureDepuisDevisAction). Un devis ne
     * part pas si la fiche a changé de type depuis (voir
     * EmettreDevisAction).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class)->orderBy('ordre')->orderBy('id');
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
     * Le type du client écrit au dernier enregistrement du devis : il
     * décide de la présentation du client et des natures d'opération
     * permises, jamais des montants, les mêmes pour tout client (voir
     * ContexteDeVente::refuserSiLeTypeDeClientAChange()).
     */
    public function estPourUnProfessionnel(): bool
    {
        return $this->type_client === TypeClient::Professionnel;
    }

    /**
     * Quel que soit son statut : un brouillon part au statut « Envoyé »
     * (voir EnvoyerDocumentAction et EmettreDevisAction), un devis déjà
     * envoyé, accepté, refusé ou expiré peut toujours être renvoyé à son
     * client tel qu'il l'a reçu.
     */
    public function peutEtreEnvoye(): bool
    {
        return true;
    }

    /**
     * Un devis n'est pas signé au registre fiscal, même numéroté : il
     * imprime le magasin tel qu'il est aujourd'hui, sur son PDF comme dans
     * l'e-mail qui le porte.
     */
    public function emetteur(): Emetteur
    {
        return Emetteur::duMagasin($this->magasin);
    }

    /**
     * Un brouillon se modifie, et un devis envoyé ou accepté aussi : il
     * repasse alors en brouillon, son acceptation effacée, et garde son
     * numéro (voir StatutDevis::transitionsAutorisees() et
     * ModifierDevisAction). Un devis refusé ou expiré reste tel quel : une
     * nouvelle proposition passe par un nouveau devis. Un devis transformé
     * en facture (voir CreerFactureDepuisDevisAction) aussi : la facture
     * reprend ses lignes et ses montants.
     */
    public function estModifiable(): bool
    {
        return ($this->statut === StatutDevis::Brouillon || $this->statut->peutTransitionnerVers(StatutDevis::Brouillon))
            && ! $this->estExpire()
            && $this->factures()->doesntExist();
    }

    /**
     * Expiré, ou envoyé et à la validité déjà dépassée : il le sera au
     * prochain passage de la nuit (voir ExpirerLesDevisAction), qui ne fait
     * qu'écrire ce statut.
     */
    public function estExpire(): bool
    {
        return $this->statut === StatutDevis::Expire
            || ($this->statut === StatutDevis::Envoye && $this->validiteDepassee());
    }

    /**
     * Seul un brouillon jamais envoyé, donc jamais numéroté : un devis qui
     * a reçu son numéro reste, même repassé en brouillon, refusé ou
     * expiré, et sa série ne présente aucun trou.
     */
    public function estSupprimable(): bool
    {
        return $this->statut === StatutDevis::Brouillon && $this->numero === null;
    }

    /**
     * La date de validité est le dernier jour du calendrier de son magasin
     * où le client peut accepter le devis : il ne peut plus l'être le
     * lendemain (voir AccepterDevisAction), et un devis envoyé expire alors
     * (voir ExpirerLesDevisAction).
     */
    public function validiteDepassee(): bool
    {
        return $this->date_validite !== null && $this->date_validite < $this->magasin->calendrier()->aujourdhui();
    }
}
