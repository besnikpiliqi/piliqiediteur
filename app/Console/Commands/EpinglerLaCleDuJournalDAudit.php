<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Providers\SiteDePiliqiServiceProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Épingle les clés du journal d'audit du site de démonstration (lot
 * DEMO-COPIE, C.12) : l'empreinte SHA-256 de la clé courante
 * (JOURNAL_AUDIT_CLE) et de chaque clé précédente
 * (JOURNAL_AUDIT_CLES_PRECEDENTES) s'ajoute à
 * resources/journal-audit/cles-demonstration.txt, jamais la clé. Le site
 * de démonstration refuse de démarrer avec une clé dont l'empreinte n'y
 * est pas, et le site réel avec une clé dont l'empreinte y est : un
 * journal d'audit de démonstration ne se chaîne jamais avec la clé du
 * réel. Sur un poste, ce fichier ne se versionne pas, comme les clés de
 * caisse de développement ; seules les empreintes des clés de production
 * du site de démonstration entrent au dépôt. Seulement sur le site de
 * démonstration : php artisan --env=demonstration journal-audit:epingler-la-cle.
 */
#[Signature('journal-audit:epingler-la-cle')]
#[Description('Épingle l\'empreinte des clés du journal d\'audit du site de démonstration.')]
class EpinglerLaCleDuJournalDAudit extends Command
{
    public const MESSAGE_SANS_CLE = 'La clé du journal d\'audit n\'est pas réglée (JOURNAL_AUDIT_CLE) : tirez-en une propre à ce site (voir le README), écrivez-la dans le .env du site de démonstration, puis relancez cette commande.';

    public function handle(): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $cles = SiteDePiliqiServiceProvider::clesDAudit();

        if ($cles === []) {
            $this->error(self::MESSAGE_SANS_CLE);

            return self::FAILURE;
        }

        $fichier = ValeurSimple::enTexte(config('journal_audit.fichier_des_empreintes_de_demonstration'));
        $epinglees = is_file($fichier) ? array_map(trim(...), file($fichier) ?: []) : [];
        $ajoutees = 0;

        foreach ($cles as $nom => $cle) {
            $empreinte = SiteDePiliqiServiceProvider::empreinteDUneCleDAudit($cle);

            if (in_array($empreinte, $epinglees, true)) {
                $this->line('Clé '.$nom.' : déjà épinglée.');

                continue;
            }

            File::ensureDirectoryExists(dirname($fichier));
            File::append($fichier, $empreinte."\n");
            $epinglees[] = $empreinte;
            $ajoutees++;
            $this->info('Clé '.$nom.' épinglée « démonstration » : '.$empreinte);
        }

        $this->line($ajoutees.' empreinte(s) ajoutée(s) à '.$fichier.'.');

        return self::SUCCESS;
    }
}
