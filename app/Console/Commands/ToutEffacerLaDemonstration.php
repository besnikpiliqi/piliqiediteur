<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Bases\BasesDeLaDemonstration;
use App\Domain\Demonstration\Construction\DossiersDeLaDemonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Sur le site de démonstration du poste, quand la base réelle est recréée
 * (migrate:fresh --seed), avant la mise en production (lot DEMO-COPIE,
 * E.4) : toutes les copies, leurs dossiers, leurs archives et le registre
 * des copies (liens, témoins, messages reçus, travaux) sont effacés ; les
 * bases modèles restent. Une base de copie que le registre ne connaissait
 * pas est un orphelin, que demonstration:nettoyer supprime la nuit. Les sociétés, leurs demandes et leurs liens ont
 * disparu avec la base réelle : demonstration:renvoyer-les-demandes, sur
 * le site réel, recrée les copies des sociétés semées, avec de nouveaux
 * liens. Le poste n'a pas de données à garder : en production, la
 * commande refuse, et rien n'est effacé.
 */
#[Signature('demonstration:tout-effacer')]
#[Description('Efface toutes les copies de démonstration, leurs archives et le registre des copies (poste seulement)')]
class ToutEffacerLaDemonstration extends Command
{
    public const MESSAGE_EN_PRODUCTION = 'demonstration:tout-effacer ne sert que sur le poste, avant la mise en production : en production, les copies et les archives de leur caisse se gardent. Rien n\'a été effacé.';

    /**
     * Les tables du registre vidées, dans l'ordre de leurs références ; la
     * table des modèles reste.
     */
    public const TABLES = ['temoins', 'liens', 'archives', 'copies', 'messages_recus', 'jobs', 'job_batches', 'failed_jobs'];

    public function handle(BasesDeLaDemonstration $bases): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        if ($this->laravel->isProduction()) {
            $this->error(self::MESSAGE_EN_PRODUCTION);

            return self::FAILURE;
        }

        $registre = DB::connection('demo_admin');
        $copies = $registre->table('copies')->whereNotNull('nom_de_la_base')->pluck('nom_de_la_base')->all();

        foreach ($copies as $base) {
            $bases->supprimer((string) $base);
        }

        $registre->transaction(function () use ($registre): void {
            foreach (self::TABLES as $table) {
                $registre->table($table)->delete();
            }
        });
        File::deleteDirectory(DossiersDeLaDemonstration::racine().'/copies');
        File::deleteDirectory(DossiersDeLaDemonstration::racine().'/archives');

        $this->info(count($copies).' copie(s), leurs archives et le registre des copies effacés ; les bases modèles restent.');

        return self::SUCCESS;
    }
}
