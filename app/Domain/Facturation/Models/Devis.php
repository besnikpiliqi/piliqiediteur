<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Catalogue\Models\Produit;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    // numero, statut, date_emission, l'émetteur figé, les totaux, le type
    // du client, la franchise de TVA, la nature d'opération et la livraison
    // sont
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
        'accepte_le' => Instant::class,
        'emetteur_fige' => 'array',
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
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return HasMany<LigneDevis, $this>
     */
    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class)->orderBy('ordre')->orderBy('id');
    }

    /**
     * @return HasMany<Facture, $this>
     */
    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    /**
     * Les liens secrets de ses envois par e-mail, un par envoi (voir
     * LienDuDevis).
     *
     * @return HasMany<LienDuDevis, $this>
     */
    public function liens(): HasMany
    {
        return $this->hasMany(LienDuDevis::class);
    }

    /**
     * La dernière réponse du client par un lien secret (voir
     * ReponseDuDevis) ; elle ne vaut pour la version actuelle du devis que
     * si elle suit son dernier envoi (voir reponseALaVersionEnvoyee()).
     *
     * @return HasOne<ReponseDuDevis, $this>
     */
    public function derniereReponse(): HasOne
    {
        return $this->hasOne(ReponseDuDevis::class)->latestOfMany();
    }

    /**
     * La réponse en ligne du client à la version que le devis a envoyée en
     * dernier, quand le devis l'a gardée : accepté ou refusé par elle, ou
     * transformé en facture après elle ; null sinon (aucune réponse en
     * ligne, ou un devis modifié et renvoyé depuis).
     */
    public function reponseALaVersionEnvoyee(): ?ReponseDuDevis
    {
        $reponse = $this->derniereReponse;

        return $reponse !== null
            && in_array($this->statut, [StatutDevis::Accepte, StatutDevis::Refuse, StatutDevis::Converti], true)
            && $this->date_emission !== null
            && $reponse->created_at->gte($this->date_emission)
            ? $reponse
            : null;
    }

    /**
     * Le lien secret qui vaut encore pour ce devis, s'il en a un : celui de
     * son dernier envoi par e-mail (voir LienDuDevis::estValable()).
     */
    public function lienValable(): ?LienDuDevis
    {
        $lien = $this->liens()->latest('id')->first();

        return $lien !== null && $lien->estValable($this) ? $lien : null;
    }

    /**
     * L'empreinte SHA-256 de la version du devis telle que son client la
     * reçoit : son numéro, son envoi, sa validité, son client, son
     * émetteur figé, sa nature d'opération, sa livraison, ses lignes et ses
     * totaux, encodés en JSON une seule fois. La réponse en ligne du client
     * la garde (voir ReponseDuDevis) : une version modifiée depuis a une
     * autre empreinte.
     */
    public function empreinteDeSaVersion(): string
    {
        $lignes = $this->lignes()->get()->map(fn (LigneDevis $ligne): array => [
            'produit_id' => $ligne->produit_id,
            'service_id' => $ligne->service_id,
            'nature' => $ligne->getRawOriginal('nature'),
            'reference' => $ligne->reference,
            'description' => $ligne->description,
            'quantite' => $ligne->quantite,
            'prix_unitaire_ht' => $ligne->getRawOriginal('prix_unitaire_ht'),
            'prix_unitaire_ttc' => $ligne->getRawOriginal('prix_unitaire_ttc'),
            'taux_tva' => $ligne->getRawOriginal('taux_tva'),
            'remise_pourcentage' => $ligne->getRawOriginal('remise_pourcentage'),
            'montant_ht' => $ligne->getRawOriginal('montant_ht'),
            'montant_tva' => $ligne->getRawOriginal('montant_tva'),
            'montant_ttc' => $ligne->getRawOriginal('montant_ttc'),
        ])->all();

        return hash('sha256', json_encode([
            'magasin_id' => $this->magasin_id,
            'numero' => $this->numero,
            'date_emission' => $this->getRawOriginal('date_emission'),
            'date_validite' => $this->getRawOriginal('date_validite'),
            'client_id' => $this->client_id,
            'type_client' => $this->getRawOriginal('type_client'),
            'franchise_en_base' => (bool) $this->franchise_en_base,
            'emetteur_fige' => $this->emetteur_fige,
            'nature_operation' => $this->getRawOriginal('nature_operation'),
            'livraison' => $this->only(['livraison_adresse', 'livraison_complement', 'livraison_code_postal', 'livraison_ville', 'livraison_pays', 'transport_par']),
            'livraison_date' => $this->getRawOriginal('livraison_date'),
            'conditions_paiement' => $this->conditions_paiement,
            'notes' => $this->notes,
            'lignes' => $lignes,
            'total_ht' => $this->getRawOriginal('total_ht'),
            'total_tva' => $this->getRawOriginal('total_tva'),
            'total_ttc' => $this->getRawOriginal('total_ttc'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Le stock disponible du magasin aujourd'hui de chaque produit suivi en
     * stock de ses lignes, et la quantité que ses lignes en demandent, les
     * lignes d'un même produit additionnées, par identifiant de produit
     * (un produit archivé depuis compris) : le devis ne réserve aucun stock
     * (décision de l'éditeur du 02/11/2026), sa fiche le montre sous chaque
     * ligne (voir FactureResource::lignesEntry()), l'avis de son
     * acceptation nomme les produits qui manquent (voir
     * stocksInsuffisants()), et l'émission de la facture le contrôle (voir
     * VerificateurDeStock). Dans l'ordre de ses lignes ; deux lectures,
     * quel que soit le nombre de lignes.
     *
     * @return array<int, array{produit: string, demande: int, disponible: int}>
     */
    public function stocksDeSesProduits(): array
    {
        $demandes = [];

        foreach ($this->lignes()->whereNotNull('produit_id')->get(['produit_id', 'quantite']) as $ligne) {
            $demandes[(int) $ligne->produit_id] = ($demandes[(int) $ligne->produit_id] ?? 0) + $ligne->quantite;
        }

        $produits = Produit::withTrashed()
            ->whereKey(array_keys($demandes))
            ->where('suivi_stock', true)
            ->get(['id', 'nom', 'stock_quantite'])
            ->keyBy(fn (Produit $produit): int => $produit->getKey());
        $stocks = [];

        foreach ($demandes as $id => $demande) {
            $produit = $produits->get($id);

            if ($produit !== null) {
                $stocks[$id] = ['produit' => $produit->nom, 'demande' => $demande, 'disponible' => $produit->stock_quantite];
            }
        }

        return $stocks;
    }

    /**
     * Les produits suivis en stock dont ses lignes demandent plus que le
     * stock disponible du magasin aujourd'hui, un par produit (voir
     * stocksDeSesProduits()).
     *
     * @return list<array{produit: string, demande: int, disponible: int}>
     */
    public function stocksInsuffisants(): array
    {
        return array_values(array_filter($this->stocksDeSesProduits(), fn (array $stock): bool => $stock['demande'] > $stock['disponible']));
    }

    /**
     * Sa fiche montre-t-elle le stock de ses lignes ? Tant que sa facture
     * n'est pas émise : brouillon, envoyé, accepté, ou transformé en une
     * facture encore brouillon ; ni refusé ni expiré.
     */
    public function montreLeStockDeSesLignes(): bool
    {
        return in_array($this->statut, [StatutDevis::Brouillon, StatutDevis::Envoye, StatutDevis::Accepte, StatutDevis::Converti], true)
            && $this->factures->every(fn (Facture $facture): bool => $facture->numero === null);
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
     * L'émetteur que le devis imprime, sur son PDF comme dans l'e-mail qui
     * le porte : celui qu'il a figé à son dernier envoi (voir
     * EmettreDevisAction), assurance professionnelle comprise, quoi que
     * deviennent ensuite la société et le magasin ; un brouillon, jamais
     * envoyé ou repassé en brouillon par une modification (voir
     * ModifierDevisAction), imprime la société et le magasin tels qu'ils
     * sont aujourd'hui. Un devis n'est pas signé au registre fiscal : cet
     * émetteur se garde sur sa ligne, que seules ses actions écrivent.
     */
    public function emetteur(): Emetteur
    {
        return $this->emetteur_fige === null
            ? Emetteur::duMagasin($this->magasin)
            : Emetteur::duContenuSigne($this->emetteur_fige);
    }

    /**
     * Un brouillon se modifie, et un devis envoyé ou accepté aussi : il
     * repasse alors en brouillon, son acceptation effacée, et garde son
     * numéro (voir StatutDevis::transitionsAutorisees() et
     * ModifierDevisAction). Un devis refusé ou expiré reste tel quel : une
     * nouvelle proposition passe par un nouveau devis. Un devis transformé
     * en facture (voir CreerFactureDepuisDevisAction) aussi, figé : la
     * facture reprend ses lignes et ses montants.
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
