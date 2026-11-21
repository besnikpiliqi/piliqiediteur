<?php

namespace Tests\Browser\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;

/**
 * Ce que partagent les cas de test en navigateur du site réel
 * (TestCaseDuNavigateur) et du site de démonstration
 * (TestCaseDuNavigateurDeLaDemonstration) : le noyau qui rend à chaque
 * requête l'état d'un processus neuf (voir NoyauDUneRequeteNeuve), le
 * journal des réponses du serveur (voir JournalDesReponses), qui fait
 * échouer tout refus ou toute erreur que le test n'attendait pas, au plus
 * tard à la fin du test, l'onglet d'un navigateur neuf et le balayage des
 * pages et des actions.
 */
trait PiloteLeNavigateur
{
    protected JournalDesReponses $journal;

    /**
     * À appeler à la fin du setUp() du cas de test, une fois l'heure du
     * test figée : la session vit en base, comme en production, et chaque
     * réponse du serveur s'inscrit au journal du test.
     */
    protected function preparerLeNavigateur(): void
    {
        config(['session.driver' => 'database']);
        $this->app->extend(Kernel::class, fn (Kernel $noyau): Kernel => $noyau instanceof NoyauDUneRequeteNeuve ? $noyau : new NoyauDUneRequeteNeuve($noyau, fn (): array => $this->prepareCookiesForRequest()));
        $this->journal = new JournalDesReponses;
        Event::listen(RequestHandled::class, $this->journal->noter(...));
    }

    /**
     * À appeler au début du tearDown() du cas de test : un test réussi
     * échoue encore sur un refus ou une erreur qu'il n'attendait pas, ou sur
     * un refus attendu qui n'est pas arrivé.
     */
    protected function verifierLeJournal(): void
    {
        if (! $this->status()->isSuccess()) {
            return;
        }

        $this->journal->verifier();
        $this->assertSame([], $this->journal->attendusNonArrives(), 'Un refus attendu par le test n\'est pas arrivé.');
    }

    /**
     * Un onglet d'un navigateur neuf, sans témoin ni session, ouvert sur
     * cette adresse de l'application.
     */
    protected function naviguer(string $chemin): Navigation
    {
        return Navigation::ouvrir($chemin, $this->journal);
    }

    /**
     * Le prochain refus de ce statut sur une requête dont le chemin contient
     * ce morceau est attendu par le test.
     */
    protected function tolerer(int $statut, string $chemin = ''): void
    {
        $this->journal->tolerer($statut, $chemin);
    }

    /**
     * Balaye, dans cet onglet connecté, chaque page atteignable depuis ce
     * chemin dans ces préfixes, et chaque action visible de chacune (voir
     * Balayeur) ; puis vérifie que tout ce qui a été ouvert est déclaré
     * dans le code (voir ActionsDeLInterface), et que ce balayage a ouvert
     * chaque action que l'inventaire des actions dit couverte par ce test,
     * dont le fichier est donné (__FILE__). Rend les pages balayées.
     *
     * @param  list<string>  $prefixes
     * @return list<string>
     */
    protected function balayer(Navigation $onglet, string $depart, array $prefixes, string $fichierDuTest): array
    {
        $couverture = new CouvertureDuBalayage;
        $couverture->ecouter();
        $pages = (new Balayeur($onglet, $couverture))->balayer($depart, $prefixes);
        $bilan = $couverture->bilan();

        if ($bilan['inconnus'] !== []) {
            $this->fail('Le balayage a ouvert des actions que la lecture du code ne trouve pas (voir ActionsDeLInterface) :'.PHP_EOL.implode(PHP_EOL, $bilan['inconnus']));
        }

        $manquantes = [];

        foreach (Inventaire::promisesPar(Inventaire::reference($fichierDuTest, $this->getPrintableTestCaseMethodName())) as $fichier => $actions) {
            foreach (array_diff($actions, $bilan['couverts'][$fichier] ?? []) as $action) {
                $manquantes[] = $fichier.' : '.$action;
            }
        }

        if ($manquantes !== []) {
            $this->fail('L\'inventaire des actions dit ce balayage couvrir des actions qu\'il n\'a pas ouvertes :'.PHP_EOL.implode(PHP_EOL, $manquantes));
        }

        return $pages;
    }
}
