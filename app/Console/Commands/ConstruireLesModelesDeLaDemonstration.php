<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Construction\ConstruireUnModele;
use App\Domain\Demonstration\Exceptions\ConstructionEnEchec;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Les bases modèles du site de démonstration, refaites chaque nuit à
 * 01 h 10, heure de Paris, sur un seul serveur, par demo_admin (lot
 * DEMO-COPIE, C.2) : pour chaque type, l'un après l'autre. Un échec garde
 * l'ancien modèle en service et passe au type suivant ; la commande rend
 * alors un code d'échec, et l'échec paraît dans l'état publié du site.
 * Elle n'existe que sur le site de démonstration.
 */
#[Signature('demonstration:construire-les-modeles {type?* : commerce, services ou cabinet (par défaut, les trois)}')]
#[Description('Refait les bases modèles du site de démonstration, une par type d\'activité.')]
class ConstruireLesModelesDeLaDemonstration extends Command
{
    public const MESSAGE_TYPE_INCONNU = 'Type inconnu : « %s » (commerce, services ou cabinet).';

    public function handle(ConstruireUnModele $construction): int
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $types = [];

        foreach ($this->argument('type') ?: array_map(static fn (TypeDActivite $type): string => $type->value, TypeDActivite::cases()) as $valeur) {
            $type = TypeDActivite::tryFrom($valeur);

            if ($type === null) {
                $this->error(sprintf(self::MESSAGE_TYPE_INCONNU, $valeur));

                return self::FAILURE;
            }

            $types[] = $type;
        }

        $echecs = 0;

        foreach ($types as $type) {
            try {
                $modele = $construction->construire($type);
                $this->info('Modèle « '.$type->value.' » en service : '.$modele->nom_de_la_base.' ('.$modele->duree_en_secondes.' s, '.number_format((int) $modele->taille, 0, ',', ' ').' octets).');
            } catch (ConstructionEnEchec $echec) {
                $echecs++;
                $this->error($echec->getMessage());
            }
        }

        return $echecs === 0 ? self::SUCCESS : self::FAILURE;
    }
}
