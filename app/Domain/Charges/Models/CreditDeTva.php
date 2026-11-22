<?php

namespace App\Domain\Charges\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Concerns\FigeLeNomDeSesAuteurs;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Le crédit de TVA d'une déclaration que la société a faite hors de
 * PILIQI (lot TVA27, choix K2 de l'éditeur du 15/11/2026, règle du
 * 18/11/2026) : la période de la déclaration (un mois au mensuel, un
 * trimestre au trimestriel, par son premier mois « AAAA-MM »), d'une
 * période de son arrivée dans PILIQI ou d'une suivante, déjà terminée, et
 * son solde, en centimes : négatif pour un crédit, 0 sans crédit (voir
 * DeclarerUnCreditDeTvaAction). La plus récente de la cadence du régime
 * de la société sert d'ancre au report du crédit (voir
 * ReportDuCreditDeTva) ; les autres restent en historique. Le propriétaire
 * l'indique et le change ; son comptable (le cabinet lié ou
 * l'expert-comptable déclaré, quand la société lui accorde « Charges :
 * voir, ajouter, saisir et valider ») l'indique, le confirme ou le corrige
 * avec un motif, et ce qu'il fait vaut vérification (voir
 * CreditsDeTva::etat()). Une donnée de la société, qui ne s'efface jamais
 * (voir GardesDesCreditsDeTva) ; chaque geste s'inscrit au journal
 * d'audit.
 *
 * @property string $uuid
 * @property CadenceDeLaTva $cadence
 * @property string $periode
 * @property int $solde
 * @property int $saisi_par
 * @property string $saisi_par_nom
 * @property ValideePar $saisi_comme
 * @property CarbonImmutable $saisi_le
 * @property int|null $verifie_par
 * @property string|null $verifie_par_nom
 * @property ValideePar|null $verifie_comme
 * @property CarbonImmutable|null $verifie_le
 * @property string|null $motif
 */
class CreditDeTva extends Model
{
    use BelongsToSociete, FigeLeNomDeSesAuteurs;

    protected $table = 'credits_de_tva';

    protected $fillable = [];

    protected $casts = [
        'cadence' => CadenceDeLaTva::class,
        'solde' => 'integer',
        'saisi_par' => 'integer',
        'saisi_comme' => ValideePar::class,
        'saisi_le' => Instant::class,
        'verifie_par' => 'integer',
        'verifie_comme' => ValideePar::class,
        'verifie_le' => Instant::class,
    ];

    /**
     * @return array<string, string>
     */
    protected static function colonnesAuNomFige(): array
    {
        return [
            'saisi_par' => 'saisi_par_nom',
            'verifie_par' => 'verifie_par_nom',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CreditDeTva $credit): void {
            $credit->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * Une déclaration se désigne dans un geste par son uuid, jamais par son
     * identifiant.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Son crédit à reporter, en centimes : l'opposé de son solde.
     */
    public function credit(): int
    {
        return -$this->solde;
    }

    public function estVerifie(): bool
    {
        return $this->verifie_le !== null;
    }

    /**
     * « novembre 2026 » ou « 4e trimestre 2026 ».
     */
    public function libelleDeLaPeriode(): string
    {
        return $this->cadence->libelle($this->periode);
    }
}
