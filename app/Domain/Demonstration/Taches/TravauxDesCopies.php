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
 * C.13) : un travail envoyé depuis une copie emporte sa référence et le
 * nom de sa base dans sa charge utile (CLE et CLE_DE_LA_BASE, voir
 * Queue::createPayloadUsing) ; au début de son exécution (JobProcessing),
 * avant de relire ses modèles, il retrouve sa copie au registre et l'ouvre
 * comme une requête (connexion, cache, verrous, disques), puis la referme
 * à sa fin (fermer()). Une copie inconnue, supprimée, échue ou qui ne se
 * sert pas (en migration, à refaire…), ou refaite depuis l'envoi du
 * travail (sa base n'est plus celle du travail : un identifiant de
 * l'ancienne base y désignerait une autre fiche, ou une autre personne) :
 * le travail est abandonné, sans nouvelle tentative, et rien ne s'écrit
 * ailleurs. Un travail exécuté dans la copie déjà ouverte (file synchrone
 * d'une requête) n'y change rien.
 */
final class TravauxDesCopies
{
    public const CLE = 'demonstration_copie';

    public const CLE_DE_LA_BASE = 'demonstration_base';

    /**
     * Ce qui rend la connexion et les disques tels qu'ils étaient avant le
     * travail en cours, ou null.
     */
    private ?Closure $fermeture = null;

    public function __construct(private readonly OuvertureDUneCopie $ouverture) {}

    /**
     * Les lignes des files (jobs, failed_jobs) des travaux envoyés depuis
     * cette copie : seulement ceux qui portent sa référence comme copie
     * d'origine, jamais un travail de l'administration qui la nomme.
     */
    public static function motifDesTravaux(string $reference): string
    {
        return '%"'.self::CLE.'":"'.$reference.'"%';
    }

    /**
     * La part de la charge utile d'un travail envoyé maintenant.
     *
     * @return array<string, string>
     */
    public static function chargeUtile(): array
    {
        $ouverte = OuvertureDUneCopie::courante();

        return $ouverte === null ? [] : [self::CLE => $ouverte['reference'], self::CLE_DE_LA_BASE => $ouverte['base']];
    }

    public function auDebut(JobProcessing $evenement): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $this->fermer();
        $chargeUtile = $evenement->job->payload();
        $reference = $chargeUtile[self::CLE] ?? null;

        if (! is_string($reference) || (OuvertureDUneCopie::courante()['reference'] ?? null) === $reference) {
            return;
        }

        $copie = preg_match('/^[0-9a-f]{32}$/', $reference) === 1 ? Copie::query()->where('reference', $reference)->first() : null;

        if ($copie === null || ! $copie->etat->seSert() || $copie->estEchue(CarbonImmutable::now()) || $copie->nom_de_la_base !== ($chargeUtile[self::CLE_DE_LA_BASE] ?? null)) {
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
