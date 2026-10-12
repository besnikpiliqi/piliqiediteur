<?php

namespace App\Domain\Facturation\Models;

use App\Domain\CaisseSecurisee\Concerns\ProtegeLesDonneesValidees;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Le n° du bordereau de vente à l'exportation (BVE) noté sur le ticket
 * d'un voyageur résidant hors de l'Union (I3, voir
 * NoterBordereauDetaxeAction) : le ticket reste à la TVA française, et sa
 * TVA ne se régularise qu'après le visa du bordereau. Un n° noté ne se
 * modifie ni ne se supprime (voir ProtegeLesDonneesValidees et les
 * déclencheurs de la table) : une faute se corrige en notant le bon n°,
 * et le dernier noté fait foi (voir Facture::bordereauDetaxe()). Il ne
 * s'écrit que par forceCreate() depuis l'action, d'où un $fillable vide,
 * et ne s'imprime pas sur le ticket, qui reproduit le document tel
 * qu'émis.
 */
class BordereauDetaxe extends Model
{
    use BelongsToMagasin, FigeLeNomDeSesAuteurs, ProtegeLesDonneesValidees;

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'note_par' => 'note_par_nom',
        ];
    }

    /**
     * La détaxe exige plus de 100,00 € TTC de biens achetés (art. 24 bis
     * de l'annexe IV au CGI), en centimes.
     */
    public const SEUIL_TTC_EN_CENTIMES = 10000;

    protected $table = 'bordereaux_detaxe';

    public const UPDATED_AT = null;

    protected $fillable = [];

    public function estValidee(): bool
    {
        return $this->exists;
    }

    protected function regleDInalterabilite(): string
    {
        return 'Un n° de bordereau de détaxe noté ne se modifie ni ne se supprime : notez le bon n°, le dernier noté fait foi.';
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    /**
     * Les biens taxés que le voyageur emporte ne dépassent pas 100,00 €
     * TTC : la détaxe n'est pas refusée pour autant, les achats d'un
     * voyageur pouvant se regrouper sur trois jours consécutifs, mais le
     * magasin en est averti.
     */
    public function avertissement(): ?string
    {
        return $this->ttcDesBiensEmportesEnCentimes() <= self::SEUIL_TTC_EN_CENTIMES
            ? 'La détaxe exige plus de 100 € d\'achats TTC, éventuellement regroupés sur trois jours consécutifs.'
            : null;
    }

    /**
     * Le TTC, en centimes, des biens taxés que le voyageur emporte : les
     * lignes de biens du ticket qui portent de la TVA (voir
     * LigneFacture::scopeBiensTaxes()), moins ce que des avoirs émis en ont
     * repris. Ni un service ni un article rendu ne compte dans le seuil.
     */
    private function ttcDesBiensEmportesEnCentimes(): int
    {
        $calculateur = app(CalculateurLigne::class);
        $biens = $this->facture->lignes()->biensTaxes()
            ->with(['lignesAvoir' => fn (HasMany $reprises): HasMany => $reprises->whereHas('avoir', fn (Builder $avoirs): Builder => $avoirs->where('statut', StatutAvoir::Emise))])
            ->get();

        return $biens->sum(fn (LigneFacture $bien): int => $calculateur->enCentiemes($bien->montant_ttc)
            - $bien->lignesAvoir->sum(fn (LigneAvoir $reprise): int => $calculateur->enCentiemes($reprise->montant_ttc)));
    }
}
