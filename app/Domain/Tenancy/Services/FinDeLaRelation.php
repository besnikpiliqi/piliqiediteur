<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\Reglage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La fin de la relation entre l'éditeur et une société : sa révocation
 * (voir RevoquerSocieteAction), et, à l'étape 12, sa résiliation. Ses
 * données de vérification (son registre des vérifications, ses codes de
 * courrier recommandé, les entrées de la vérification de son journal
 * d'audit) se gardent alors cinq ans, puis s'effacent (Q4, voir
 * config/conservation.php) : la société reçoit la fin de leur
 * conservation, que la base fige et que ses déclencheurs lisent (voir
 * DeclencheursDeProtection::effacementApresExpirationOuFinDeLaRelation()),
 * et chacun de ses codes la reçoit aussi (conserver_jusqu_au). Leurs
 * purges de chaque nuit les effacent ensuite (voir app:purger-verifications
 * et app:purger-journal-audit). Tout le reste se conserve dix ans.
 *
 * Écrit dans la transaction de l'appelant, la société relue sous son
 * verrou et active dans le contexte (ses codes s'y lisent par SocieteScope)
 * ; une fin déjà constatée ne change plus.
 */
class FinDeLaRelation
{
    /**
     * @return CarbonImmutable la fin de la conservation de ses données de vérification
     *
     * @throws LogicException hors d'une transaction, ou hors du contexte de cette société
     */
    public function constater(Societe $verrouillee): CarbonImmutable
    {
        if (DB::transactionLevel() === 0 || app(TenantContext::class)->societe()->getKey() !== $verrouillee->getKey()) {
            throw new LogicException('La fin de la relation se constate dans la transaction de son geste, dans le contexte de la société.');
        }

        if ($verrouillee->verification_conservee_jusqu_au !== null) {
            return $verrouillee->verification_conservee_jusqu_au;
        }

        $fin = Calendrier::deParis()->decaler(now(), CarbonInterval::years(Reglage::entier('conservation.verification_apres_la_fin_de_la_relation_en_annees')));
        $verrouillee->forceFill(['verification_conservee_jusqu_au' => $fin])->save();

        CodeConfirmation::query()->whereNull('conserver_jusqu_au')->orderBy('id')->lockForUpdate()->get()
            ->each(fn (CodeConfirmation $code): bool => $code->forceFill(['conserver_jusqu_au' => $fin])->save());

        return $fin;
    }
}
