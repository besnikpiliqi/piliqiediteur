<?php

namespace Tests\Browser;

use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Pest\Browser\Support\ComputeUrl;
use Tests\Browser\Support\Navigation;
use Tests\Browser\Support\PiloteLeNavigateur;
use Tests\DemonstrationTestCase;

/**
 * Le cas de test des tests en navigateur du site de démonstration
 * (tests/Browser/Demonstration, lot DEMO-COPIE, G.2 et G.3) : le module
 * browser de Pest sert, depuis le processus du test, l'application du
 * test, refaite ici en mode démonstration (voir DemonstrationTestCase) ;
 * le navigateur parle donc au site de démonstration, avec son registre,
 * ses copies du modèle de test, ses pages fermées et son bandeau. Comme
 * pour le site réel (voir TestCaseDuNavigateur), chaque requête part de
 * l'état d'un processus neuf et chaque réponse s'inscrit au journal du
 * test. La base par défaut reste la base sentinelle, vide : aucune
 * transaction de RefreshDatabase ne s'y ouvre, chaque copie vit dans sa
 * propre base, supprimée à la fin du test.
 */
abstract class TestCaseDuNavigateurDeLaDemonstration extends DemonstrationTestCase
{
    use PiloteLeNavigateur;

    /**
     * L'instant auquel chaque test commence : un vendredi, à 10 h à Paris,
     * en heure d'hiver, après la construction des modèles de test.
     */
    protected static function instantDuTest(): CarbonImmutable
    {
        return self::aParis('2026-11-20 10:00:00');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::instantDuTest());
        $this->preparerLeNavigateur();
    }

    protected function tearDown(): void
    {
        $this->verifierLeJournal();

        parent::tearDown();
    }

    /**
     * Un navigateur neuf suit le lien de cette copie (/d#secret), comme le
     * visiteur qui l'a reçu : la page lit le secret après « # », l'envoie à
     * POST /entrer, efface l'adresse, puis mène à « Choisissez qui vous
     * êtes » (ou au compte choisi à la visite précédente). Rend l'onglet,
     * sur la page où l'entrée l'a mené.
     */
    protected function suivreLeLien(string $secret, ?Navigation $onglet = null): Navigation
    {
        $onglet ??= $this->naviguer('/robots.txt');
        $onglet->page()->goto(ComputeUrl::from('/d').'#'.$secret);

        return $onglet->attendreQue('location.pathname !== "/d"', 'le lien n\'a pas ouvert la démonstration')
            ->attendre()
            ->verifier();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $geste
     * @return T
     */
    public function dansLaCopie(Copie $copie, Closure $geste): mixed
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, $geste);
    }

    /**
     * Le compte de la copie qui porte ce nom.
     */
    protected function compteDeLaCopie(Copie $copie, string $nom): User
    {
        return $this->dansLaCopie($copie, fn (): User => User::query()->where('name', $nom)->sole());
    }
}
