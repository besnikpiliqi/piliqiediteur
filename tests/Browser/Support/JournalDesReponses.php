<?php

namespace Tests\Browser\Support;

use Illuminate\Foundation\Http\Events\RequestHandled;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Chaque réponse que le serveur de l'application a donnée au navigateur
 * pendant un test en navigateur (pages, requêtes de Livewire,
 * téléchargements), notée à la fin de son traitement (RequestHandled) :
 * une réponse en 4xx ou 5xx fait échouer la vérification suivante, sauf si
 * le test l'attendait (voir tolerer()). Une requête de Livewire se nomme
 * par ses composants et les méthodes appelées, pour dire quel geste a
 * échoué, et une erreur du serveur par son exception.
 */
final class JournalDesReponses
{
    /**
     * @var list<array{requete: string, statut: int}>
     */
    private array $reponses = [];

    /**
     * Les réponses déjà vérifiées.
     */
    private int $verifiees = 0;

    /**
     * Les refus attendus, chacun une seule fois : un statut et un morceau du
     * chemin de la requête.
     *
     * @var list<array{statut: int, chemin: string}>
     */
    private array $attendus = [];

    public function noter(RequestHandled $evenement): void
    {
        $requete = $evenement->request;
        $description = $requete->method().' /'.ltrim($requete->path(), '/');

        if ($requete->is('livewire/update')) {
            $appels = [];

            foreach ((array) $requete->input('components', []) as $composant) {
                $instantane = json_decode((string) ($composant['snapshot'] ?? ''), true);
                $nom = is_array($instantane) ? (string) ($instantane['memo']['name'] ?? '?') : '?';
                $methodes = array_map(fn (mixed $appel): string => is_array($appel) ? (string) ($appel['method'] ?? '?') : '?', (array) ($composant['calls'] ?? []));
                $appels[] = $nom.($methodes === [] ? '' : ' → '.implode(', ', $methodes));
            }

            $description .= ' ('.implode(' ; ', $appels).')';
        }

        $exception = $evenement->response->exception ?? null;

        if ($exception instanceof Throwable && $evenement->response->getStatusCode() >= 500) {
            $description .= ' : '.$exception::class.' : '.$exception->getMessage().' ('.$exception->getFile().':'.$exception->getLine().')';
        }

        $this->reponses[] = ['requete' => $description, 'statut' => $evenement->response->getStatusCode()];
    }

    /**
     * Le prochain refus de ce statut sur une requête dont le chemin contient
     * ce morceau est attendu par le test.
     */
    public function tolerer(int $statut, string $chemin = ''): void
    {
        $this->attendus[] = ['statut' => $statut, 'chemin' => $chemin];
    }

    /**
     * Aucune réponse en 4xx ou 5xx depuis la vérification précédente, hors
     * les refus attendus.
     */
    public function verifier(): void
    {
        $inattendues = [];

        foreach (array_slice($this->reponses, $this->verifiees) as $reponse) {
            if ($reponse['statut'] < 400 || $this->etaitAttendue($reponse)) {
                continue;
            }

            $inattendues[] = $reponse['statut'].' '.$reponse['requete'];
        }

        $this->verifiees = count($this->reponses);

        if ($inattendues !== []) {
            Assert::fail('Le serveur a répondu par un refus ou une erreur que le test n\'attendait pas :'.PHP_EOL.implode(PHP_EOL, $inattendues));
        }
    }

    /**
     * Les refus attendus qui ne sont pas arrivés.
     *
     * @return list<string>
     */
    public function attendusNonArrives(): array
    {
        return array_map(fn (array $attendu): string => $attendu['statut'].' '.$attendu['chemin'], $this->attendus);
    }

    /**
     * Toutes les réponses notées, dans l'ordre.
     *
     * @return list<array{requete: string, statut: int}>
     */
    public function reponses(): array
    {
        return $this->reponses;
    }

    /**
     * @param  array{requete: string, statut: int}  $reponse
     */
    private function etaitAttendue(array $reponse): bool
    {
        foreach ($this->attendus as $indice => $attendu) {
            if ($attendu['statut'] === $reponse['statut'] && str_contains($reponse['requete'], $attendu['chemin'])) {
                unset($this->attendus[$indice]);
                $this->attendus = array_values($this->attendus);

                return true;
            }
        }

        return false;
    }
}
