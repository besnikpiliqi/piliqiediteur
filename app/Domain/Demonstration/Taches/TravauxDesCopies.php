<?php

namespace App\Domain\Demonstration\Taches;

use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Chaque travail d'une copie s'exécute dans sa copie (lot DEMO-COPIE,
 * C.13) : un travail envoyé depuis une copie emporte sa référence dans sa
 * charge utile (CLE, voir Queue::createPayloadUsing) ; au début de son
 * exécution (JobProcessing), avant de relire ses modèles, il retrouve sa
 * copie au registre et l'ouvre comme une requête (connexion, cache,
 * verrous, disques), puis la referme à sa fin (fermer()). Une copie
 * inconnue, supprimée, échue ou qui ne se sert pas (en migration, à
 * refaire…) : le travail est abandonné, sans nouvelle tentative, et rien
 * ne s'écrit ailleurs. Un travail exécuté dans la copie déjà ouverte (file
 * synchrone d'une requête) n'y change rien.
 */
final class TravauxDesCopies
{
    public const CLE = 'demonstration_copie';

    /**
     * Ce qui rend la connexion et les disques tels qu'ils étaient avant le
     * travail en cours, ou null.
     */
    private ?Closure $fermeture = null;

    public function __construct(private readonly OuvertureDUneCopie $ouverture) {}

    /**
     * La part de la charge utile d'un travail envoyé maintenant.
     *
     * @return array<string, string>
     */
    public static function chargeUtile(): array
    {
        $ouverte = OuvertureDUneCopie::courante();

        return $ouverte === null ? [] : [self::CLE => $ouverte['reference']];
    }

    public function auDebut(JobProcessing $evenement): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $this->fermer();
        $reference = $evenement->job->payload()[self::CLE] ?? null;

        if (! is_string($reference) || (OuvertureDUneCopie::courante()['reference'] ?? null) === $reference) {
            return;
        }

        $copie = preg_match('/^[0-9a-f]{32}$/', $reference) === 1 ? Copie::query()->where('reference', $reference)->first() : null;

        if ($copie === null || ! $copie->etat->seSert() || $copie->estEchue(CarbonImmutable::now())) {
            self::abandonner($evenement->job);

            return;
        }

        $this->fermeture = $this->ouverture->ouvrirJusquA($copie);
    }

    /**
     * Referme la copie du travail qui vient de finir, réussi, échoué ou
     * remis dans sa file.
     */
    public function fermer(): void
    {
        $fermeture = $this->fermeture;
        $this->fermeture = null;

        if ($fermeture !== null) {
            $fermeture();
        }
    }

    private static function abandonner(Job $travail): void
    {
        $travail->delete();
    }
}
