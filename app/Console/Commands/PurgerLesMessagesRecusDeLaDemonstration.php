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
 * Sur le site de démonstration, chaque jour : les nonces des messages
 * reçus du site réel s'effacent après un jour (lot DEMO-COPIE, C.1) ; un
 * message plus vieux que cinq minutes est de toute façon refusé (C.7).
 */
#[Signature('demonstration:purger-les-messages-recus')]
#[Description('Efface les nonces des messages reçus du site réel depuis plus d\'un jour.')]
class PurgerLesMessagesRecusDeLaDemonstration extends Command
{
    public function handle(): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $effaces = DB::connection('demo_admin')->table('messages_recus')->where('recu_le', '<', Calendrier::deParis()->decaler(CarbonImmutable::now(), CarbonInterval::days(-1)))->delete();
        $this->info("{$effaces} message(s) reçu(s) effacé(s).");

        return self::SUCCESS;
    }
}
