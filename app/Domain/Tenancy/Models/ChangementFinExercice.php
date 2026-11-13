<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Calendrier\Casts\JourCivil;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\OrganeDeLaDecision;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Un changement légal de la date de clôture de l'exercice d'une société,
 * sur procès-verbal, fait par la procédure de l'éditeur (voir
 * ChangerFinExerciceAction) : l'ancien et le nouveau mois de fin
 * d'exercice, l'exercice en cours au jour de la procédure (son début, sa
 * fin avant et après le changement), la décision et l'organe qui l'a
 * prise, sa pièce, la référence de son dépôt au registre et, s'il y a
 * lieu, l'expert-comptable consulté, la justification et les opérateurs.
 * Il ne se modifie ni ne s'efface
 * jamais ; la base le refuse aussi. Il fixe les exercices de la société
 * (voir ExercicesDeLaSociete), que les clôtures d'exercice de l'étape 3
 * suivront.
 *
 * Ses jours (voir JourCivil) ne sont jamais nuls : leurs colonnes ne le
 * sont pas en base.
 *
 * @property array{operateurs: list<string>, piece: string, utilisateur_systeme: string, hote: string, version_module: string} $operateur
 * @property string $debut_exercice_en_cours
 * @property string $fin_exercice_en_cours_avant
 * @property string $fin_exercice_en_cours_apres
 * @property string $decision_du
 * @property OrganeDeLaDecision $organe_de_la_decision
 * @property string|null $depot_au_registre
 * @property string|null $duree_validee_par
 */
class ChangementFinExercice extends Model
{
    use BelongsToSociete;

    protected $table = 'changements_fin_exercice';

    /**
     * Son instant s'écrit par ChangerFinExerciceAction, en UTC (voir
     * Instant) : jamais par Eloquent, et il ne se modifie pas.
     */
    public $timestamps = false;

    protected $fillable = [];

    protected $casts = [
        'ancien_mois' => 'integer',
        'nouveau_mois' => 'integer',
        'debut_exercice_en_cours' => JourCivil::class,
        'fin_exercice_en_cours_avant' => JourCivil::class,
        'fin_exercice_en_cours_apres' => JourCivil::class,
        'decision_du' => JourCivil::class,
        'organe_de_la_decision' => OrganeDeLaDecision::class,
        'operateur' => 'array',
        'created_at' => Instant::class,
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Un changement légal de fin d\'exercice ne se modifie jamais.');
        });

        static::deleting(function (): never {
            throw new LogicException('Un changement légal de fin d\'exercice ne s\'efface jamais.');
        });
    }
}
