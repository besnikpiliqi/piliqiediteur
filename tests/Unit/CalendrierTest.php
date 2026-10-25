<?php

namespace Tests\Unit;

use App\Domain\Calendrier\Calendrier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le calendrier de Paris, sans base ni application : chaque instant y est
 * écrit avec son décalage, et chaque attente se compare comme un instant
 * (horodatage Unix) ou comme un jour « AAAA-MM-JJ ».
 */
class CalendrierTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function minuitsDeParis(): array
    {
        return [
            'veille du passage à l\'heure d\'hiver, dernière seconde' => ['2026-10-24T21:59:59Z', '2026-10-24'],
            'minuit du 25 octobre, à l\'heure d\'été' => ['2026-10-24T22:00:00Z', '2026-10-25'],
            'fin de la journée de 25 heures' => ['2026-10-25T22:59:59Z', '2026-10-25'],
            'minuit du 26 octobre, à l\'heure d\'hiver' => ['2026-10-25T23:00:00Z', '2026-10-26'],
            'veille du passage à l\'heure d\'été, dernière seconde' => ['2027-03-27T22:59:59Z', '2027-03-27'],
            'minuit du 28 mars, à l\'heure d\'hiver' => ['2027-03-27T23:00:00Z', '2027-03-28'],
            'dernière seconde avant l\'heure sautée' => ['2027-03-28T00:59:59Z', '2027-03-28'],
            'première seconde après l\'heure sautée' => ['2027-03-28T01:00:00Z', '2027-03-28'],
            'nuit d\'été, écrite avec le décalage de Paris' => ['2026-07-15T00:30:00+02:00', '2026-07-15'],
            'nuit d\'été, écrite avec un autre décalage' => ['2026-07-15T03:30:00+05:00', '2026-07-15'],
        ];
    }

    /**
     * Le jour d'un instant est celui de Paris, quel que soit le fuseau de
     * la date donnée : il change à minuit de Paris, en été comme en
     * hiver, et l'heure sautée de mars ne change pas de jour.
     */
    #[DataProvider('minuitsDeParis')]
    public function test_le_jour_de_paris_change_a_minuit_de_paris_en_ete_et_en_hiver(string $instant, string $jour): void
    {
        $this->assertSame($jour, Calendrier::deParis()->jourDe(new DateTimeImmutable($instant)));
    }

    public function test_l_annee_de_paris_change_a_minuit_de_paris_le_31_decembre(): void
    {
        $calendrier = Calendrier::deParis();

        $this->assertSame(['2026', '2027'], [
            $calendrier->anneeDe(new DateTimeImmutable('2026-12-31T22:59:59Z')),
            $calendrier->anneeDe(new DateTimeImmutable('2026-12-31T23:00:00Z')),
        ]);
    }

    /**
     * Un jour va de son minuit au minuit du lendemain, exclu : 25 heures
     * le 25 octobre 2026, 23 heures le 28 mars 2027, 24 heures sinon.
     */
    public function test_les_bornes_d_un_jour_font_25_heures_le_25_octobre_et_23_heures_le_28_mars(): void
    {
        $calendrier = Calendrier::deParis();
        $bornes = static fn (string $jour): array => array_map(static fn (DateTimeInterface $borne): int => $borne->getTimestamp(), $calendrier->bornesDuJour($jour));

        $this->assertSame(self::horodatages('2026-10-24T22:00:00Z', '2026-10-25T23:00:00Z'), $bornes('2026-10-25'));
        $this->assertSame(self::horodatages('2027-03-27T23:00:00Z', '2027-03-28T22:00:00Z'), $bornes('2027-03-28'));
        $this->assertSame(self::horodatages('2026-07-14T22:00:00Z', '2026-07-15T22:00:00Z'), $bornes('2026-07-15'));
        $this->assertSame([25 * 3600, 23 * 3600, 24 * 3600], array_map(static fn (string $jour): int => $bornes($jour)[1] - $bornes($jour)[0], ['2026-10-25', '2027-03-28', '2026-07-15']));
        $this->assertSame(self::horodatages('2026-12-31T23:00:00Z'), [$calendrier->debutDuJour('2027-01-01')->getTimestamp()]);
    }

    /**
     * Les deux passages de 2 h 30 le 25 octobre sont deux instants
     * distincts du même jour, à la même heure affichée.
     */
    public function test_les_deux_passages_de_l_heure_repetee_ont_le_meme_jour_et_des_instants_distincts(): void
    {
        $calendrier = Calendrier::deParis();
        $premier = new DateTimeImmutable('2026-10-25T00:30:00Z');
        $second = new DateTimeImmutable('2026-10-25T01:30:00Z');

        $this->assertSame(['2026-10-25', '2026-10-25'], [$calendrier->jourDe($premier), $calendrier->jourDe($second)]);
        $this->assertSame(['02:30', '02:30'], [$calendrier->afficher($premier, 'H:i'), $calendrier->afficher($second, 'H:i')]);
        $this->assertSame(3600, $second->getTimestamp() - $premier->getTimestamp());
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function heuresMurales(): array
    {
        return [
            'heure sautée au passage à l\'heure d\'été' => ['2027-03-28 02:30', null],
            'heure répétée au passage à l\'heure d\'hiver' => ['2026-10-25 02:30:00', null],
            'début de l\'heure répétée' => ['2026-10-25 02:00', null],
            'dernière seconde avant l\'heure répétée' => ['2026-10-25 01:59:59', '2026-10-24T23:59:59Z'],
            'première heure après l\'heure répétée' => ['2026-10-25 03:00', '2026-10-25T02:00:00Z'],
            'première heure après l\'heure sautée' => ['2027-03-28 03:00', '2027-03-28T01:00:00Z'],
            'heure d\'été' => ['2026-07-14 10:00', '2026-07-14T08:00:00Z'],
            'heure d\'hiver' => ['2026-12-15 10:00:30', '2026-12-15T09:00:30Z'],
            'date qui n\'existe pas' => ['2026-02-30 10:00', null],
            'heure mal écrite' => ['2026-10-09 10h00', null],
        ];
    }

    /**
     * Une heure murale de Paris donne son seul instant ; une heure que
     * Paris saute ou répète, ou une heure mal écrite, est refusée.
     */
    #[DataProvider('heuresMurales')]
    public function test_une_heure_de_paris_inexistante_ou_repetee_est_refusee(string $heure, ?string $instant): void
    {
        if ($instant === null) {
            $this->expectException(InvalidArgumentException::class);
        }

        $this->assertSame((new DateTimeImmutable((string) $instant))->getTimestamp(), Calendrier::deParis()->heureMurale($heure)->getTimestamp());
    }

    /**
     * Un délai en jours garde l'heure murale de Paris (décision de
     * l'éditeur, Q2) : trente jours après le 20 octobre 2026 à 10 h, le 19
     * novembre à 10 h, soit 721 heures, le passage à l'heure d'hiver tombant
     * entre les deux ; et trente jours avant, le chemin inverse.
     */
    public function test_un_decalage_en_jours_garde_l_heure_de_paris_a_travers_un_changement_d_heure(): void
    {
        $calendrier = Calendrier::deParis();
        $envoi = $calendrier->heureMurale('2026-10-20 10:00');
        $expiration = $calendrier->decaler($envoi, CarbonInterval::days(30));

        $this->assertSame($calendrier->heureMurale('2026-11-19 10:00')->getTimestamp(), $expiration->getTimestamp());
        $this->assertSame(721 * 3600, $expiration->getTimestamp() - $envoi->getTimestamp());
        $this->assertSame($envoi->getTimestamp(), $calendrier->decaler($expiration, CarbonInterval::days(-30))->getTimestamp());
        $this->assertSame($calendrier->heureMurale('2027-10-20 10:00')->getTimestamp(), $calendrier->decaler($envoi, CarbonInterval::years(1))->getTimestamp());
    }

    public function test_un_decalage_en_mois_ne_deborde_pas_en_fin_de_mois(): void
    {
        $calendrier = Calendrier::deParis();

        $this->assertSame(
            [$calendrier->heureMurale('2026-02-28 12:00')->getTimestamp(), $calendrier->heureMurale('2029-02-28 12:00')->getTimestamp(), $calendrier->heureMurale('2027-04-30 23:30')->getTimestamp()],
            [
                $calendrier->decalerEnMoisSansDebordement($calendrier->heureMurale('2026-01-31 12:00'), 1)->getTimestamp(),
                $calendrier->decalerEnMoisSansDebordement($calendrier->heureMurale('2028-02-29 12:00'), 12)->getTimestamp(),
                $calendrier->decalerEnMoisSansDebordement($calendrier->heureMurale('2026-10-31 23:30'), 6)->getTimestamp(),
            ],
        );
    }

    /**
     * L'affichage et la date ISO 8601 d'un contenu signé portent l'heure
     * de Paris et son décalage, et afficher() n'ajoute aucune mention aux
     * deux passages de l'heure répétée.
     */
    public function test_l_affichage_et_l_iso8601_portent_l_heure_et_le_decalage_de_paris(): void
    {
        $calendrier = Calendrier::deParis();
        $premier = new DateTimeImmutable('2026-10-25T00:30:00Z');
        $second = new DateTimeImmutable('2026-10-25T01:30:00Z');

        $this->assertSame(
            ['25/10/2026 02:30', '2026-10-25T02:30:00+02:00', '25/10/2026 02:30', '2026-10-25T02:30:00+01:00', '15/07/2026 à 00:30'],
            [
                $calendrier->afficher($premier, 'd/m/Y H:i'),
                $calendrier->iso8601($premier),
                $calendrier->afficher($second, 'd/m/Y H:i'),
                $calendrier->iso8601($second),
                $calendrier->afficher(new DateTimeImmutable('2026-07-14T22:30:00Z'), 'd/m/Y à H:i'),
            ],
        );
    }

    public function test_l_horodatage_signe_est_en_utc(): void
    {
        $this->assertSame(
            ['20261009080000Z', '20261025003000Z', '20261025013000Z'],
            [
                Calendrier::horodatageSigne(Calendrier::deParis()->heureMurale('2026-10-09 10:00')),
                Calendrier::horodatageSigne(new DateTimeImmutable('2026-10-25T02:30:00+02:00')),
                Calendrier::horodatageSigne(new DateTimeImmutable('2026-10-25T02:30:00+01:00')),
            ],
        );
    }

    /**
     * Les jours se calculent en jours entiers, sans fuseau, et le jour où
     * l'on est se lit à Paris.
     */
    public function test_les_jours_s_ajoutent_et_se_comptent_en_jours_entiers(): void
    {
        $this->assertSame(
            ['2026-10-25', '2026-10-26', '2027-03-29', '2027-01-01', '2028-02-29'],
            [
                Calendrier::ajouterJours('2026-10-24', 1),
                Calendrier::ajouterJours('2026-10-25', 1),
                Calendrier::ajouterJours('2027-03-27', 2),
                Calendrier::ajouterJours('2026-12-31', 1),
                Calendrier::ajouterJours('2028-03-01', -1),
            ],
        );
        $this->assertSame([30, -30, 0, 365], [
            Calendrier::joursEntre('2026-10-09', '2026-11-08'),
            Calendrier::joursEntre('2026-11-08', '2026-10-09'),
            Calendrier::joursEntre('2026-10-25', '2026-10-25'),
            Calendrier::joursEntre('2026-03-01', '2027-03-01'),
        ]);
        $this->assertSame(
            ['2026-10-19', '2026-10-19', '2026-10-01', '2026-01-01', '2027-01-10', '2026-02-10', '25/10/2026'],
            [
                Calendrier::premierJourDeLaSemaine('2026-10-25'),
                Calendrier::premierJourDeLaSemaine('2026-10-19'),
                Calendrier::premierJourDuMois('2026-10-25'),
                Calendrier::premierJourDeLAnnee('2026-10-25'),
                Calendrier::jourDuMoisSuivant('2026-12-15', 10),
                Calendrier::jourDuMoisSuivant('2026-01-31', 10),
                Calendrier::afficherJour('2026-10-25', 'd/m/Y'),
            ],
        );

        CarbonImmutable::setTestNow(new CarbonImmutable('2026-07-14T22:30:00Z'));
        $this->assertSame('2026-07-15', Calendrier::deParis()->aujourdhui());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function joursMalFormes(): array
    {
        return [
            '29 février d\'une année non bissextile' => ['2026-02-29'],
            'mois 13' => ['2026-13-01'],
            'jour sur un chiffre' => ['2026-10-9'],
            'espace en trop' => ['2026-10-09 '],
            'date qui porte une heure' => ['2026-10-09T00'],
            'sans tirets' => ['20261009'],
            'vide' => [''],
        ];
    }

    /**
     * Un jour mal formé n'est pas un jour : jourValide() le refuse, et un
     * calcul de jours ne le lit jamais.
     */
    #[DataProvider('joursMalFormes')]
    public function test_un_jour_mal_forme_n_est_pas_un_jour_valide(string $jour): void
    {
        $this->assertNull(Calendrier::jourValide($jour));
        $this->expectException(InvalidArgumentException::class);

        Calendrier::ajouterJours($jour, 1);
    }

    public function test_un_jour_bien_forme_est_un_jour_valide(): void
    {
        $this->assertSame(['2028-02-29', '2026-10-25'], [Calendrier::jourValide('2028-02-29'), Calendrier::jourValide('2026-10-25')]);
    }

    /**
     * @return list<int>
     */
    private static function horodatages(string ...$instants): array
    {
        return array_map(static fn (string $instant): int => (new DateTimeImmutable($instant))->getTimestamp(), $instants);
    }
}
