<?php

namespace App\Console\Commands;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sur le site de démonstration, chaque jour : les travaux échoués du
 * registre s'effacent au bout de 30 jours (lot DEMO-COPIE, C.1 et C.17) ;
 * ceux d'une copie s'effacent déjà avec elle (voir FinDesCopies).
 */
#[Signature('demonstration:purger-les-travaux-echoues')]
#[Description('Efface les travaux échoués du site de démonstration depuis plus de 30 jours.')]
class PurgerLesTravauxEchouesDeLaDemonstration extends Command
{
    public const JOURS = 30;

    public function handle(): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $effaces = DB::connection('demo_admin')->table('failed_jobs')->where('failed_at', '<', Calendrier::deParis()->decaler(CarbonImmutable::now(), CarbonInterval::days(-self::JOURS)))->delete();
        $this->info("{$effaces} travail(aux) échoué(s) effacé(s).");

        return self::SUCCESS;
    }
}
