<?php

namespace App\Domain\Calendrier;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;

/**
 * Le calendrier d'un fuseau, seule classe de l'application qui connaît un
 * fuseau : il donne le jour, l'année, le minuit et les bornes d'un jour,
 * les décalages en jours, mois ou années (la même heure murale n jours
 * plus tard), l'affichage d'un instant (avec, dans un journal, la mention
 * de l'heure qui se répète) et l'instant d'une heure murale.
 * Un calendrier porte son fuseau et ne le reçoit qu'à sa création, par sa
 * fabrique : deParis() pour tous les magasins.
 *
 * Un instant se compare et se calcule en lui-même, quel que soit son
 * fuseau ; un jour est une chaîne « AAAA-MM-JJ » du calendrier qui l'a
 * donné, et ne se convertit jamais d'un fuseau à un autre. Un instant que
 * rend un calendrier est au fuseau de l'application, comme now().
 */
final class Calendrier
{
    /**
     * Le seul nom de fuseau écrit dans l'application.
     */
    private const FUSEAU_DE_PARIS = 'Europe/Paris';

    private function __construct(private readonly string $fuseau) {}

    /**
     * Le calendrier de Paris.
     */
    public static function deParis(): self
    {
        return new self(self::FUSEAU_DE_PARIS);
    }

    /**
     * Le nom du fuseau du calendrier : celui du planificateur et de
     * l'affichage des colonnes de Filament.
     */
    public function fuseau(): string
    {
        return $this->fuseau;
    }

    /**
     * Le jour du calendrier où l'on est.
     */
    public function aujourdhui(): string
    {
        return $this->jourDe(CarbonImmutable::now());
    }

    /**
     * Le jour du calendrier de cet instant.
     */
    public function jourDe(DateTimeInterface $instant): string
    {
        return $this->dansLeFuseau($instant)->format('Y-m-d');
    }

    /**
     * L'année du calendrier de cet instant (« AAAA ») : celle des numéros
     * d'un document.
     */
    public function anneeDe(DateTimeInterface $instant): string
    {
        return $this->dansLeFuseau($instant)->format('Y');
    }

    /**
     * L'instant du minuit de ce jour du calendrier.
     *
     * @throws InvalidArgumentException
     */
    public function debutDuJour(string $jour): CarbonImmutable
    {
        return self::auFuseauDeLApplication(CarbonImmutable::createFromFormat('!Y-m-d', self::exigerUnJour($jour), $this->fuseau));
    }

    /**
     * Le minuit de ce jour et celui du lendemain, borne exclue : 23, 24 ou
     * 25 heures selon le changement d'heure.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws InvalidArgumentException
     */
    public function bornesDuJour(string $jour): array
    {
        return [$this->debutDuJour($jour), $this->debutDuJour(self::ajouterJours($jour, 1))];
    }

    /**
     * L'instant décalé d'une durée en jours, mois ou années, à la même
     * heure murale du calendrier : 30 jours après le 20 octobre à 10 h, le
     * 19 novembre à 10 h, une heure de plus ou de moins quand un
     * changement d'heure tombe entre les deux.
     */
    public function decaler(DateTimeInterface $instant, CarbonInterval $duree): CarbonImmutable
    {
        return self::auFuseauDeLApplication($this->dansLeFuseau($instant)->add($duree));
    }

    /**
     * Comme decaler(), de ce nombre de mois sans déborder sur le mois
     * suivant : un mois après le 31 janvier, le 28 ou le 29 février.
     */
    public function decalerEnMoisSansDebordement(DateTimeInterface $instant, int $mois): CarbonImmutable
    {
        return self::auFuseauDeLApplication($this->dansLeFuseau($instant)->addMonthsNoOverflow($mois));
    }

    /**
     * L'instant mis en forme à l'heure du calendrier (format de date de
     * PHP), sans aucune mention.
     */
    public function afficher(DateTimeInterface $instant, string $format): string
    {
        return $this->dansLeFuseau($instant)->format($format);
    }

    /**
     * Comme afficher(), suivi de « (heure d'été) » ou « (heure d'hiver) »
     * quand l'heure murale de l'instant se répète dans le fuseau du
     * calendrier, au passage à l'heure d'hiver : pour un journal, dont
     * l'ordre reste ainsi lisible (décision de l'éditeur, Q3). L'heure
     * sautée au passage à l'heure d'été n'existe pas : rien ne s'ajoute.
     */
    public function afficherDansUnJournal(DateTimeInterface $instant, string $format): string
    {
        $texte = $this->afficher($instant, $format);

        if (count($this->instantsDeLHeureMurale(self::heureEcrite($this->afficher($instant, 'Y-m-d H:i:s')))) < 2) {
            return $texte;
        }

        return $texte.($this->dansLeFuseau($instant)->isDST() ? " (heure d'été)" : " (heure d'hiver)");
    }

    /**
     * L'instant en ISO 8601, avec le décalage de l'heure du calendrier
     * (« 2026-10-09T10:00:00+02:00 ») : la date d'un contenu signé.
     */
    public function iso8601(DateTimeInterface $instant): string
    {
        return $this->dansLeFuseau($instant)->toIso8601String();
    }

    /**
     * L'instant d'une heure murale du calendrier (« AAAA-MM-JJ HH:MM » ou
     * « AAAA-MM-JJ HH:MM:SS ») ; une heure que le calendrier saute au
     * passage à l'heure d'été, ou qu'il répète au passage à l'heure
     * d'hiver, ne désigne pas un seul instant et est refusée.
     *
     * @throws InvalidArgumentException
     */
    public function heureMurale(string $heure): CarbonImmutable
    {
        $instants = $this->instantsDeLHeureMurale(self::heureEcrite($heure));

        if (count($instants) !== 1) {
            throw new InvalidArgumentException(sprintf('%s %s dans le fuseau %s.', $heure, $instants === [] ? "n'existe pas" : 'se répète', $this->fuseau));
        }

        return self::auFuseauDeLApplication(CarbonImmutable::createFromTimestamp($instants[0]));
    }

    /**
     * Le jour, plus ou moins ce nombre de jours entiers.
     *
     * @throws InvalidArgumentException
     */
    public static function ajouterJours(string $jour, int $jours): string
    {
        return self::lireUnJour($jour)->modify(sprintf('%+d days', $jours))->format('Y-m-d');
    }

    /**
     * Le nombre de jours entiers du premier jour au second, négatif si le
     * second le précède.
     *
     * @throws InvalidArgumentException
     */
    public static function joursEntre(string $du, string $au): int
    {
        return (int) self::lireUnJour($du)->diff(self::lireUnJour($au))->format('%r%a');
    }

    /**
     * Le lundi de la semaine de ce jour.
     *
     * @throws InvalidArgumentException
     */
    public static function premierJourDeLaSemaine(string $jour): string
    {
        return self::ajouterJours($jour, 1 - (int) self::lireUnJour($jour)->format('N'));
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function premierJourDuMois(string $jour): string
    {
        return self::lireUnJour($jour)->format('Y-m-01');
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function premierJourDeLAnnee(string $jour): string
    {
        return self::lireUnJour($jour)->format('Y-01-01');
    }

    /**
     * Ce quantième du mois qui suit celui de ce jour : le 10 du mois qui
     * suit une livraison.
     *
     * @throws InvalidArgumentException
     */
    public static function jourDuMoisSuivant(string $jour, int $quantieme): string
    {
        return self::ajouterJours(self::lireUnJour($jour)->modify('first day of next month')->format('Y-m-d'), $quantieme - 1);
    }

    /**
     * Le jour mis en forme (format de date de PHP), tel quel, sans fuseau.
     *
     * @throws InvalidArgumentException
     */
    public static function afficherJour(string $jour, string $format): string
    {
        return self::lireUnJour($jour)->format($format);
    }

    /**
     * L'horodatage d'un contenu signé : « AAAAMMJJHHMMSSZ », en UTC.
     */
    public static function horodatageSigne(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format('YmdHis').'Z';
    }

    /**
     * L'invariant du démarrage : l'application et PHP sont en UTC, fuseau
     * de tous les instants (voir Casts\Instant). Un autre fuseau décalerait
     * en silence chaque instant écrit, lu ou comparé.
     *
     * @throws LogicException
     */
    public static function exigerUnFuseauUtc(): void
    {
        foreach (["config('app.timezone')" => config('app.timezone'), 'date_default_timezone_get()' => date_default_timezone_get()] as $source => $fuseau) {
            if ($fuseau !== 'UTC') {
                throw new LogicException("Le fuseau de l'application doit être UTC : {$source} vaut « {$fuseau} ». Le calendrier de chaque magasin passe par la classe Calendrier.");
            }
        }
    }

    /**
     * Le jour, s'il est un jour « AAAA-MM-JJ » du calendrier ; sinon null.
     */
    public static function jourValide(string $jour): ?string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $jour, $parties) === 1 && checkdate((int) $parties[2], (int) $parties[3], (int) $parties[1])
            ? $jour
            : null;
    }

    private function dansLeFuseau(DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->fuseau);
    }

    /**
     * Les instants (horodatages Unix) qu'une heure murale désigne dans le
     * fuseau du calendrier : un seul, aucun quand le fuseau la saute, deux
     * quand il la répète (voir heureMurale() et afficherDansUnJournal()).
     * Chaque décalage que le fuseau prend autour de cette heure donne un
     * instant candidat, retenu si le fuseau a bien ce décalage à cet
     * instant : un calcul en secondes UTC, sans aucune date écrite.
     *
     * @return list<int>
     */
    private function instantsDeLHeureMurale(DateTimeImmutable $murale): array
    {
        $fuseau = new DateTimeZone($this->fuseau);
        $secondes = $murale->getTimestamp();
        $instants = [];

        foreach ($fuseau->getTransitions($secondes - 2 * 86400, $secondes + 2 * 86400) as $transition) {
            $instant = $secondes - $transition['offset'];

            if ($fuseau->getOffset(new DateTimeImmutable('@'.$instant)) === $transition['offset']) {
                $instants[$instant] = $instant;
            }
        }

        return array_values($instants);
    }

    /**
     * L'heure écrite, lue comme une heure UTC à la seconde, pour calculer
     * sans fuseau.
     *
     * @throws InvalidArgumentException
     */
    private static function heureEcrite(string $heure): DateTimeImmutable
    {
        $format = strlen($heure) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s';
        $lue = DateTimeImmutable::createFromFormat('!'.$format, $heure, new DateTimeZone('UTC'));

        if ($lue === false || $lue->format($format) !== $heure) {
            throw new InvalidArgumentException("{$heure} n'est pas une heure « AAAA-MM-JJ HH:MM[:SS] ».");
        }

        return $lue;
    }

    /**
     * Le jour, à minuit UTC, pour calculer en jours entiers sans fuseau.
     *
     * @throws InvalidArgumentException
     */
    private static function lireUnJour(string $jour): DateTimeImmutable
    {
        return new DateTimeImmutable(self::exigerUnJour($jour).' 00:00:00', new DateTimeZone('UTC'));
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function exigerUnJour(string $jour): string
    {
        return self::jourValide($jour) ?? throw new InvalidArgumentException("{$jour} n'est pas un jour « AAAA-MM-JJ ».");
    }

    /**
     * Le même instant, au fuseau de l'application.
     */
    private static function auFuseauDeLApplication(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone(date_default_timezone_get());
    }
}
