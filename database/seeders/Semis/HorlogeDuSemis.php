<?php

namespace Database\Seeders\Semis;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Demonstration\SiteDePiliqi;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * L'horloge du semis : l'histoire semée se déroule dans l'ordre, du
 * premier au dernier jour, jour après jour, chacun dans le calendrier de
 * Paris, et finit la veille de l'instant où le semis commence. Chaque
 * geste se date ainsi après le précédent, jamais avant le dernier verrou
 * du registre de son magasin (voir RegistreFiscal). Chaque jour, à 00 h 05
 * et à 06 h 05 (après le minuit des Antilles), les tâches que le
 * planificateur passe chaque heure s'exécutent, comme sur un serveur qui
 * tourne (voir TACHES) ; chaque premier du mois, PostgreSQL analyse les
 * tables (ANALYZE) après les tâches de 00 h 05, pour choisir ses plans de
 * requête sur des statistiques à jour, même quand tout le semis tient
 * dans une seule transaction (dans les tests), où l'analyse automatique ne
 * voit rien, et la revérification de toutes les sociétés au répertoire
 * Sirene passe à 06 h 05. À la fin, l'horloge revient à l'instant du début
 * (les tâches rattrapent la dernière journée), puis telle qu'elle était
 * avant le semis : figée au même instant dans un test, libre sinon.
 */
final class HorlogeDuSemis
{
    /**
     * Les tâches du seul site réel, qu'une base modèle du site de
     * démonstration ne passe pas (lot DEMO-COPIE, F.1) : elle n'a pas de
     * démonstrations des sociétés.
     */
    private const TACHES_DU_SITE_REEL = ['demonstration:marquer-les-fins'];

    /**
     * Les tâches horaires que le semis exécute chaque jour, à chaque heure
     * de Paris où elles passent (voir bootstrap/app.php) : à 00 h 05, celles
     * du calendrier des magasins, les relances des factures impayées, la
     * clôture des inscriptions sans code et l'expiration des invitations et
     * des demandes des cabinets ; à 06 h 05, après le minuit des Antilles,
     * la clôture des journées, pour que celle d'un magasin de la Martinique
     * se fasse avant ses ventes du lendemain. Les purges, qui effaceraient
     * ce que le semis veut montrer, n'y sont pas.
     *
     * @var array<string, list<string>>
     */
    private const TACHES = [
        '00:05' => [
            'app:cloturer-caisses-automatiquement',
            'app:marquer-factures-en-retard',
            'app:expirer-devis',
            'app:relancer-factures-impayees',
            'app:clore-inscriptions-sans-code',
            'app:expirer-invitations-cabinets',
            'demonstration:marquer-les-fins',
        ],
        '06:05' => ['app:cloturer-caisses-automatiquement'],
    ];

    private readonly ?CarbonImmutable $avant;

    private readonly CarbonImmutable $fin;

    private readonly string $premierJour;

    private CarbonImmutable $maintenant;

    /**
     * Ce qui se fait une fois, quand l'horloge entre dans les derniers jours
     * de l'histoire (voir auxDerniersJours()), et leur nombre.
     *
     * @var array{0: int, 1: Closure(): void}|null
     */
    private ?array $auxDerniersJours = null;

    /**
     * @param  int  $jours  le nombre de jours de l'histoire semée, le dernier étant la veille du semis
     */
    public function __construct(public readonly int $jours)
    {
        $this->avant = Carbon::hasTestNow() ? CarbonImmutable::instance(Carbon::getTestNow()) : null;
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
     * Le rang de ce jour (« AAAA-MM-JJ ») dans l'histoire, de 0 le premier
     * à $jours - 1 la veille du semis ; null pour un jour hors de
     * l'histoire.
     */
    public function rangDuJour(string $jour): ?int
    {
        $rang = Calendrier::joursEntre($this->premierJour, $jour);

        return $rang >= 0 && $rang < $this->jours ? $rang : null;
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
     * Fait ce geste une fois, à minuit (heure de Paris) du premier des
     * $jours derniers jours de l'histoire, avant les tâches de ce jour : le
     * semis d'un modèle du site de démonstration y rend leur vrai transport
     * aux e-mails, pour que la page « E-mails qui seraient partis » de
     * chaque copie neuve en montre de vrais exemples (lot DEMO-COPIE, F.4).
     *
     * @param  Closure(): void  $geste
     */
    public function auxDerniersJours(int $jours, Closure $geste): void
    {
        $this->auxDerniersJours = [$jours, $geste];
        $this->entrerDansLesDerniersJours();
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

        for ($jour = $calendrier->jourDe($this->maintenant); $calendrier->debutDuJour($jour)->lessThanOrEqualTo($instant); $jour = Calendrier::ajouterJours($jour, 1)) {
            foreach (array_keys(self::TACHES) as $heure) {
                $taches = $calendrier->heureMurale($jour.' '.$heure);

                if ($taches->lessThanOrEqualTo($this->maintenant)) {
                    continue;
                }

                if ($taches->greaterThan($instant)) {
                    break 2;
                }

                $this->poser($taches);
                $this->executerLesTaches($heure);
            }
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
        $this->maintenant = $instant;
        $this->entrerDansLesDerniersJours();

        return $instant;
    }

    private function entrerDansLesDerniersJours(): void
    {
        if ($this->auxDerniersJours === null) {
            return;
        }

        [$jours, $geste] = $this->auxDerniersJours;

        if ($this->maintenant->greaterThanOrEqualTo(Calendrier::deParis()->debutDuJour($this->jour(max(0, $this->jours - $jours))))) {
            $this->auxDerniersJours = null;
            $geste();
        }
    }

    /**
     * @throws RuntimeException quand une tâche échoue
     */
    private function executerLesTaches(string $heure): void
    {
        foreach (self::TACHES[$heure] as $tache) {
            if (in_array($tache, self::TACHES_DU_SITE_REEL, true) && SiteDePiliqi::estLaDemonstration()) {
                continue;
            }

            if (Artisan::call($tache) !== 0) {
                throw new RuntimeException("La tâche {$tache} a échoué pendant le semis, le ".self::aParis($this->maintenant).' : '.trim(Artisan::output()));
            }
        }

        $premierDuMois = str_ends_with(Calendrier::deParis()->jourDe($this->maintenant), '-01');

        if ($premierDuMois && $heure === '00:05') {
            DB::statement('analyze');
        }

        if ($premierDuMois && $heure === '06:05' && Artisan::call('app:reverifier-sirene', ['--toutes' => true]) !== 0) {
            throw new RuntimeException('La revérification mensuelle au répertoire Sirene a échoué pendant le semis, le '.self::aParis($this->maintenant).' : '.trim(Artisan::output()));
        }
    }
}
