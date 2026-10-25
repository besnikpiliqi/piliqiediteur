<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\Societe;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Chaque heure (voir bootstrap/app.php), clôt les inscriptions dont aucun
 * code n'a été saisi trente jours après leur dernier courrier recommandé
 * (voir CloreLInscriptionAction::cloreSansCode()) : la société reste,
 * close et en lecture seule, et son SIREN est libéré. Les inscriptions se
 * choisissent par une lecture limitée à leur identifiant (aucune validée,
 * close ni révoquée, créée avant la limite, sans courrier de vérification
 * parti depuis), puis chacune se clôt dans sa propre société, sous le
 * verrou de sa ligne, qui relit ces conditions. La panne d'une inscription
 * n'arrête pas les suivantes.
 */
#[Signature('app:clore-inscriptions-sans-code')]
#[Description('Clôt les inscriptions sans code saisi trente jours après leur dernier courrier, et libère leur SIREN')]
class CloreInscriptionsSansCode extends Command
{
    public function handle(CloreLInscriptionAction $clore): int
    {
        $limite = Calendrier::deParis()->decaler(CarbonImmutable::now(), CarbonInterval::days(-CodeConfirmation::DUREE_DE_VALIDITE_EN_JOURS));
        $closes = 0;
        $enEchec = 0;

        Societe::query()
            ->whereNull('validee_le')
            ->whereNull('close_le')
            ->where('statut', '<>', StatutSociete::Revoquee->value)
            ->where('created_at', '<=', $limite)
            ->whereNotExists(fn ($courriers) => $courriers->from((new CodeConfirmation)->getTable())
                ->whereColumn('codes_confirmation.societe_id', 'societes.id')
                ->where('codes_confirmation.motif', MotifCode::Verification->value)
                ->where('codes_confirmation.envoye_le', '>', $limite))
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $societeId) use ($clore, $limite, &$closes, &$enEchec): void {
                try {
                    $closes += $clore->cloreSansCode(Societe::query()->findOrFail($societeId), $limite) ? 1 : 0;
                } catch (Throwable $erreur) {
                    report($erreur);
                    $enEchec++;
                }
            });

        $this->info("{$closes} inscription(s) close(s).");

        return $enEchec > 0 ? self::FAILURE : self::SUCCESS;
    }
}
