<?php

namespace Database\Seeders\Semis;

use App\Domain\Calendrier\Calendrier;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use RuntimeException;

/**
 * L'horloge du semis : l'histoire semée se déroule dans l'ordre, du
 * premier au dernier jour, jour après jour, chacun dans le calendrier de
 * Paris, celui de tous les magasins semés (en métropole), et finit la
 * veille de l'instant où le semis commence. Chaque geste se date ainsi
 * après le précédent, jamais avant le dernier verrou du registre de son
 * magasin (voir RegistreFiscal). À chaque minuit franchi, à 00 h 05, les
 * tâches que le planificateur passe chaque heure s'exécutent, comme sur un
 * serveur qui tourne : la clôture des journées et la fermeture d'office
 * des caisses personnelles, le passage en retard des factures et
 * l'expiration des devis. À la fin, l'horloge revient à l'instant du début
 * (les tâches rattrapent la dernière journée), puis telle qu'elle était
 * avant le semis : figée au même instant dans un test, libre sinon.
 */
final class HorlogeDuSemis
{
    /**
     * Les tâches horaires du calendrier des magasins que le semis exécute à
     * chaque minuit franchi (voir bootstrap/app.php).
     */
    private const TACHES = ['app:cloturer-caisses-automatiquement', 'app:marquer-factures-en-retard', 'app:expirer-devis'];

    private readonly ?CarbonImmutable $avant;

    private readonly CarbonImmutable $fin;

    private readonly string $premierJour;

    private CarbonImmutable $maintenant;

    /**
     * @param  int  $jours  le nombre de jours de l'histoire semée, le dernier étant la veille du semis
     */
    public function __construct(public readonly int $jours)
    {
        $avant = Carbon::getTestNow();
        $this->avant = $avant === null ? null : CarbonImmutable::instance($avant);
        $this->fin = CarbonImmutable::now()->startOfSecond();
        $this->premierJour = Calendrier::ajouterJours(Calendrier::deParis()->jourDe($this->fin), -$jours);
        $this->maintenant = Calendrier::deParis()->debutDuJour($this->premierJour);
    }

    /**
     * Le jour de rang $rang de l'histoire (0 le premier, $jours - 1 la
     * veille du semis), « AAAA-MM-JJ ».
     */
    public function jour(int $rang): string
    {
        if ($rang < 0 || $rang >= $this->jours) {
            throw new LogicException("Le jour {$rang} n'est pas dans l'histoire semée (0 à ".($this->jours - 1).').');
        }

        return Calendrier::ajouterJours($this->premierJour, $rang);
    }

    /**
     * Avance l'horloge à cette heure murale (« HH:MM ») de ce jour de
     * l'histoire, en exécutant les tâches horaires à chaque minuit franchi.
     *
     * @throws LogicException quand l'heure demandée précède l'heure du semis
     */
    public function aller(int $rang, string $heure): CarbonImmutable
    {
        return $this->avancerJusquA(Calendrier::deParis()->heureMurale($this->jour($rang).' '.$heure));
    }

    /**
     * L'instant où l'horloge du semis est.
     */
    public function maintenant(): CarbonImmutable
    {
        return $this->maintenant;
    }

    /**
     * Revient à l'instant du début du semis, tâches horaires rattrapées,
     * puis rend l'horloge telle qu'elle était avant lui.
     */
    public function terminer(): void
    {
        $this->avancerJusquA($this->fin);
        Carbon::setTestNow($this->avant);
    }

    /**
     * Rend l'horloge telle qu'elle était avant le semis, sans rien
     * exécuter : après un semis interrompu.
     */
    public function rendre(): void
    {
        Carbon::setTestNow($this->avant);
    }

    private function avancerJusquA(CarbonImmutable $instant): CarbonImmutable
    {
        if ($instant->lessThan($this->maintenant)) {
            throw new LogicException('Le semis ne remonte jamais le temps : '.self::aParis($instant).' précède '.self::aParis($this->maintenant).'.');
        }

        if ($instant->greaterThan($this->fin)) {
            throw new LogicException('Le semis ne date rien après son propre début ('.self::aParis($this->fin).').');
        }

        $calendrier = Calendrier::deParis();

        for ($jour = Calendrier::ajouterJours($calendrier->jourDe($this->maintenant), 1); $calendrier->debutDuJour($jour)->lessThanOrEqualTo($instant); $jour = Calendrier::ajouterJours($jour, 1)) {
            $taches = $calendrier->heureMurale($jour.' 00:05');

            if ($taches->greaterThan($instant)) {
                break;
            }

            $this->poser($taches);
            $this->executerLesTaches();
        }

        return $this->poser($instant);
    }

    /**
     * L'instant à l'heure de Paris, pour un message (« 13/11/2026 10:00:00 »).
     */
    private static function aParis(CarbonImmutable $instant): string
    {
        return Calendrier::deParis()->afficher($instant, 'd/m/Y H:i:s');
    }

    private function poser(CarbonImmutable $instant): CarbonImmutable
    {
        Carbon::setTestNow($instant);

        return $this->maintenant = $instant;
    }

    /**
     * @throws RuntimeException quand une tâche échoue
     */
    private function executerLesTaches(): void
    {
        foreach (self::TACHES as $tache) {
            if (Artisan::call($tache) !== 0) {
                throw new RuntimeException("La tâche {$tache} a échoué pendant le semis, le ".self::aParis($this->maintenant).' : '.trim(Artisan::output()));
            }
        }
    }
}
