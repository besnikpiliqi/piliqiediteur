<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Database\Factories\ClotureCaissePersonnelleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Relevé « Fermer ma caisse » d'UN vendeur (voir FermerSaCaisseAction) --
 * tout membre qui vend ou encaisse, le propriétaire et les responsables
 * compris --, en quatre
 * rubriques séparées (B18) :
 *  - les ventes : celles qu'il a émises et qu'aucune de ses fermetures
 *    n'a encore comptées, y compris celles d'un jour précédent ; leur
 *    nombre et leur TTC vendu, avec le HT et la TVA (total_ttc, total_ht,
 *    total_tva). Une vente reprise depuis par un avoir y compte en
 *    entier : le relevé ne déduit pas les avoirs, que la clôture de la
 *    journée déduit le jour de leur émission (voir CloturerCaisseAction) ;
 *  - les encaissements, par mode de paiement : les paiements qu'il a
 *    enregistrés depuis sa dernière fermeture, sur ces ventes comme sur
 *    des factures plus anciennes, et, en négatif, les contre-passations
 *    de ses paiements, quel qu'en soit l'auteur (voir
 *    Paiement::scopeNonFermesParLeVendeur()) -- ce que doit contenir
 *    chaque tiroir ;
 *  - le crédit d'avoir imputé sur ces ventes à la fermeture (credit_avoir) :
 *    ce qui les a réglées sans entrer en caisse ;
 *  - ce qu'il en reste dû à la fermeture (reste_du).
 *
 * Sa période (periode_debut) part de la borne de sa dernière fermeture, ou
 * du début de la journée de sa borne s'il n'en a encore fait aucune, ou de
 * la plus ancienne vente ou du plus ancien paiement compté s'il est
 * antérieur ; elle compte les opérations jusqu'à sa borne (jusqu_au, voir
 * FermerSaCaisseAction).
 *
 * Qui l'a fermée (mode_fermeture, fermee_par, voir ModeFermetureCaisse et
 * libelleDeFermeture()) : le vendeur lui-même, un responsable pour lui, ou
 * personne, d'office, après la clôture de la journée ou à minuit (voir
 * FermerLesCaissesPersonnellesAction) : une caisse personnelle restée
 * ouverte ne bloque jamais la clôture de la journée.
 *
 * Un outil de réconciliation de fin de service, PAS un document fiscal.
 * Contrairement à ClotureCaisse (la clôture officielle de la journée du
 * magasin, réservée au propriétaire ou à qui il en délègue la permission),
 * quiconque vend ou encaisse peut toujours fermer SA PROPRE caisse, sans
 * permission dédiée (voir ClotureCaissePersonnellePolicy::fermer()).
 * Toutes ses ventes comptent, aux particuliers comme aux
 * professionnels : l'objectif est de vérifier sa propre caisse, pas
 * l'e-reporting. Les ventes et les paiements comptés sont notés dans des
 * tables à part (voir factures() et paiements()), jamais sur les documents
 * eux-mêmes : une facture déjà incluse ici reste pleinement éligible à la
 * clôture officielle de la journée.
 */
class ClotureCaissePersonnelle extends Model
{
    use FigeLeNomDeSesAuteurs;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'vendeur_id' => 'vendeur_nom',
            'fermee_par' => 'fermee_par_nom',
        ];
    }

    /** @use HasFactory<ClotureCaissePersonnelleFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'clotures_caisse_personnelles';

    // Tout le reste (périodes, rubriques) n'est jamais en mass assignment,
    // uniquement via FermerSaCaisseAction.
    protected $fillable = [];

    protected $casts = [
        'mode_fermeture' => ModeFermetureCaisse::class,
        'periode_debut' => 'datetime',
        'jusqu_au' => 'datetime',
        'cloturee_a' => 'datetime',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
        'encaissements' => 'array',
        'credit_avoir' => 'decimal:2',
        'reste_du' => 'decimal:2',
    ];

    /**
     * Qui a fermé la caisse, tel que le relevé le dit : « Fermée par le
     * vendeur », « Fermée par Claire Martin » (un responsable), ou
     * « Fermée automatiquement ».
     */
    public function libelleDeFermeture(): string
    {
        return match ($this->mode_fermeture) {
            ModeFermetureCaisse::Vendeur => 'Fermée par le vendeur',
            ModeFermetureCaisse::Responsable => 'Fermée par '.$this->fermee_par_nom,
            ModeFermetureCaisse::Automatique => 'Fermée automatiquement',
        };
    }

    /**
     * Les ventes comptées par cette fermeture -- chacune ne l'est jamais
     * que par une seule (voir Facture::scopeNonFermeesParLeVendeur()).
     */
    public function factures(): BelongsToMany
    {
        return $this->belongsToMany(Facture::class, 'clotures_caisse_personnelles_factures');
    }

    /**
     * Les paiements comptés par cette fermeture, contre-passations
     * comprises -- chacun ne l'est jamais que par une seule (voir
     * Paiement::scopeNonFermesParLeVendeur()).
     */
    public function paiements(): BelongsToMany
    {
        return $this->belongsToMany(Paiement::class, 'clotures_caisse_personnelles_paiements');
    }

    /**
     * Le total encaissé, tous modes confondus, contre-passations déduites.
     */
    public function totalEncaisse(): float
    {
        return app(CalculateurLigne::class)->sommer($this->encaissements);
    }

    /**
     * « Espèces : 30,00 € », un par mode de paiement encaissé, dans
     * l'ordre des modes.
     *
     * @return list<string>
     */
    public function encaissementsEnClair(): array
    {
        return array_values(array_map(
            fn (string $mode, string $montant): string => ModePaiement::from($mode)->getLabel().' : '.number_format((float) $montant, 2, ',', ' ').' €',
            array_keys($this->encaissements),
            $this->encaissements,
        ));
    }
}
