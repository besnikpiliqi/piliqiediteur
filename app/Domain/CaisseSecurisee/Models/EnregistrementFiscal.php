<?php

namespace App\Domain\CaisseSecurisee\Models;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Une ligne du registre fiscal, écrite seulement par RegistreFiscal. Elle
 * ne se modifie ni ne se supprime : le modèle lève
 * DonneeFiscaleInalterableException (voir ProtegeLesDonneesValidees), et
 * les déclencheurs de la table refusent ce qui ne passe pas par lui.
 *
 * `contenu` est le JSON exact qui est signé : il se relit tel quel pour
 * vérifier la signature, jamais après un décodage suivi d'un nouvel
 * encodage. contenuSigne() en donne le décodage, pour l'afficher ou
 * l'exporter.
 *
 * @property ChaineFiscale $chaine
 * @property int $sequence
 * @property string $type
 * @property CarbonImmutable $horodatage
 * @property string $contenu
 * @property string|null $signature_precedente
 * @property string $signature
 * @property string $algorithme
 * @property string $cle_id
 * @property string $version_module
 */
class EnregistrementFiscal extends Model
{
    use BelongsToMagasin, ProtegeLesDonneesValidees;

    protected $table = 'enregistrements_fiscaux';

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'chaine' => ChaineFiscale::class,
        'sequence' => 'integer',
        'horodatage' => HorodatageUtc::class,
    ];

    /**
     * Le contenu signé décodé, une fois par instance.
     *
     * @var array<string, mixed>|null
     */
    private ?array $contenuDecode = null;

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Un enregistrement du registre fiscal ne se modifie ni ne se supprime : une correction s\'inscrit par un nouvel enregistrement.';
    }

    public function operateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operateur_id');
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * L'adresse IP et le navigateur d'un événement technique inscrit
     * pendant une requête HTTP, tant qu'ils ne sont pas effacés (voir
     * TraceEvenement) : jamais dans le contenu signé.
     */
    public function trace(): HasOne
    {
        return $this->hasOne(TraceEvenement::class, 'enregistrement_fiscal_id');
    }

    /**
     * Les enregistrements horodatés du jour $du au jour $au, bornes
     * comprises, chaque jour à l'heure de Paris, de minuit à minuit : la
     * colonne étant en UTC, ses bornes se convertissent en UTC (voir
     * HorodatageUtc). Une borne absente ne limite rien.
     */
    public function scopeHorodatesDuJourAuJour(Builder $query, ?string $du, ?string $au): Builder
    {
        $fuseau = config('app.timezone');

        return $query
            ->when(filled($du), fn (Builder $enregistrements): Builder => $enregistrements->where(
                'horodatage', '>=', HorodatageUtc::enBase(CarbonImmutable::parse($du, $fuseau)->startOfDay()),
            ))
            ->when(filled($au), fn (Builder $enregistrements): Builder => $enregistrements->where(
                'horodatage', '<=', HorodatageUtc::enBase(CarbonImmutable::parse($au, $fuseau)->endOfDay()),
            ));
    }

    /**
     * Le contenu signé, décodé : en-tête de l'enregistrement et données.
     *
     * @return array<string, mixed>
     */
    public function contenuSigne(): array
    {
        return $this->contenuDecode ??= json_decode($this->contenu, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Le code d'un événement technique ; null dans une autre chaîne.
     */
    public function codeEvenement(): ?CodeEvenement
    {
        return $this->chaine === ChaineFiscale::Evenement ? CodeEvenement::tryFrom((int) $this->type) : null;
    }

    /**
     * Ce qu'enregistre la ligne : le code et le libellé d'un événement
     * technique, sinon la nature du document (Ticket, Facture, Avoir,
     * Paiement, Contre-passation).
     */
    public function libelleDuType(): string
    {
        return $this->codeEvenement()?->libelleAvecCode() ?? Str::ucfirst(str_replace('_', '-', $this->type));
    }

    /**
     * L'opérateur tel que le contenu signé le nomme, ou « Système » pour
     * une écriture faite sans utilisateur connecté.
     */
    public function libelleDeLOperateur(): string
    {
        $operateur = $this->contenuSigne()['operateur'] ?? null;

        return is_array($operateur) ? "{$operateur['nom']} (n° {$operateur['id']})" : 'Système';
    }

    /**
     * Le document que référence le contenu signé, ou null : une vente par
     * sa nature et son numéro légal (« Ticket TK-LYON1-2026-000001 »), une
     * clôture du jour par le numéro de son ticket Z que signe son
     * événement (« Clôture Z-LYON1-2026-000001 ») ; un paiement, ou le
     * document d'un autre événement technique (un produit), par son
     * identifiant interne, nommé comme tel (« Produit (identifiant interne
     * 12) ») pour ne jamais passer pour un numéro légal.
     */
    public function libelleDuDocument(): ?string
    {
        $contenu = $this->contenuSigne();
        $document = $contenu['document'] ?? null;

        if (! is_array($document)) {
            return null;
        }

        $estUneCloture = $this->chaine === ChaineFiscale::Evenement && $document['type'] === (new ClotureCaisse)->getMorphClass();
        $nature = match (true) {
            $estUneCloture => 'Clôture',
            $this->chaine === ChaineFiscale::Evenement => Str::ucfirst(str_replace('_', ' ', $document['type'])),
            default => $this->libelleDuType(),
        };
        $numeroLegal = match (true) {
            $estUneCloture => $contenu['donnees']['details']['numero'] ?? null,
            $this->chaine === ChaineFiscale::Vente => $contenu['donnees']['document']['numero'] ?? null,
            default => null,
        };

        return $numeroLegal === null
            ? "{$nature} (identifiant interne {$document['id']})"
            : "{$nature} {$numeroLegal}";
    }
}
