<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * La recherche des cabinets d'expertise comptable vérifiés, par le
 * propriétaire d'une société qui désigne le sien (voir CabinetComptable et
 * DesignerUnCabinetAction) : une lecture d'autres sociétés que CLAUDE.md
 * permet, limitée aux cabinets vérifiés (validés, actifs, leur code
 * d'activité contrôlé, leur inscription à l'Ordre vérifiée, sans
 * radiation constatée), à leur identifiant, leur SIREN, leur dénomination
 * et la ville de leur siège, 20 au plus : par un SIREN exact de 9
 * chiffres, ou par au moins 3 caractères de leur dénomination, sans tenir
 * compte de la casse ni des accents.
 */
final class RechercheDesCabinets
{
    public const RESULTATS_AU_PLUS = 20;

    /**
     * @return Collection<int, Societe>
     */
    public function pour(string $terme): Collection
    {
        $terme = trim($terme);
        $parSiren = preg_match('/^\d{9}$/', $terme) === 1;

        if (! $parSiren && mb_strlen($terme) < 3) {
            return new Collection;
        }

        $requete = Societe::query()
            ->where('type_activite', TypeDActivite::Cabinet->value)
            ->whereNotNull('validee_le')
            ->where('statut', StatutSociete::Active->value)
            ->whereIn('id', Cabinet::query()->withoutGlobalScope(SocieteScope::class)
                ->whereNotNull('activite_controlee_le')
                ->whereNotNull('ordre_verifie_le')
                ->whereNull('ordre_retire_le')
                ->select('societe_id'));

        $parSiren ? $requete->where('siren', $terme) : RechercheSansAccents::appliquer($requete, $terme, ['raison_sociale']);

        return $requete->orderBy('raison_sociale')->orderBy('id')->limit(self::RESULTATS_AU_PLUS)
            ->get(['id', 'uuid', 'siren', 'raison_sociale', 'siege_code_postal', 'siege_ville']);
    }
}
