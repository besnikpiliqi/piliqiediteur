<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;

/**
 * Les écritures en base qu'un test rend durables depuis le début de la
 * surveillance : chaque requête qui écrit (insertion, modification,
 * suppression, création ou changement d'un objet de la base…) faite au
 * niveau de transaction de départ, ou dans une transaction ouverte ensuite
 * et validée, niveau après niveau, jusqu'à ce niveau ; celles d'une
 * transaction ou d'un point de sauvegarde annulés ne le sont pas, même
 * quand la transaction qui les entoure est validée ensuite.
 *
 * Un test dont la classe n'a pas de base de test (ni RefreshDatabase, ni
 * DatabaseMigrations, voir TestCase) n'en laisse aucune : ses lignes
 * resteraient dans la base que partagent les tests de son processus, et,
 * premier de son processus à toucher la base, il écrirait dans une base
 * que rien n'a encore migrée.
 */
final class EcrituresDurables
{
    /**
     * Le début d'une requête qui écrit dans la base ou change ses objets.
     */
    private const ECRITURE = '/^\s*(insert|update|delete|truncate|merge|copy|create|alter|drop|comment|grant|revoke)\b/i';

    /**
     * @var list<string>
     */
    private array $durables = [];

    /**
     * Les écritures en attente, par connexion puis par niveau de
     * transaction ouvert au-dessus du départ : validé, un niveau verse les
     * siennes dans le niveau qui l'entoure, ou dans les écritures durables
     * au retour au départ ; annulé, il les perd. Un niveau qui disparaît
     * sans avoir été validé a été annulé.
     *
     * @var array<string, array<int, list<string>>>
     */
    private array $enAttente = [];

    public function __construct(Dispatcher $evenements, private readonly int $niveauDeDepart)
    {
        $evenements->listen(QueryExecuted::class, function (QueryExecuted $requete): void {
            if (preg_match(self::ECRITURE, $requete->sql) !== 1) {
                return;
            }

            $niveau = $requete->connection->transactionLevel();
            $this->oublierLesNiveauxAuDessusDe($requete->connectionName, $niveau);

            if ($niveau > $this->niveauDeDepart) {
                $this->enAttente[$requete->connectionName][$niveau][] = $requete->sql;
            } else {
                $this->durables[] = $requete->sql;
            }
        });

        $evenements->listen(TransactionCommitted::class, function (TransactionCommitted $validation): void {
            $niveau = $validation->connection->transactionLevel();
            $validees = $this->enAttente[$validation->connectionName][$niveau + 1] ?? [];
            $this->oublierLesNiveauxAuDessusDe($validation->connectionName, $niveau);

            if ($niveau > $this->niveauDeDepart) {
                $this->enAttente[$validation->connectionName][$niveau] = [...($this->enAttente[$validation->connectionName][$niveau] ?? []), ...$validees];
            } else {
                array_push($this->durables, ...$validees);
            }
        });

        $evenements->listen(TransactionRolledBack::class, function (TransactionRolledBack $annulation): void {
            $this->oublierLesNiveauxAuDessusDe($annulation->connectionName, $annulation->connection->transactionLevel());
        });
    }

    /**
     * @return list<string>
     */
    public function durables(): array
    {
        return $this->durables;
    }

    /**
     * Le refus d'un test sans base de test qui a rendu des écritures
     * durables, qui les nomme, ou null s'il n'en a rendu aucune.
     */
    public function refusSansBaseDeTest(string $test): ?string
    {
        if ($this->durables === []) {
            return null;
        }

        return sprintf(
            "%s écrit en base sans base de test : sa classe doit utiliser RefreshDatabase (ou DatabaseMigrations), sinon ses lignes restent dans la base que partagent les tests de son processus, et, premier de son processus à la toucher, il écrit dans une base que rien n'a encore migrée. Écritures :\n%s",
            $test,
            implode("\n", $this->durables),
        );
    }

    /**
     * Oublie les écritures des niveaux de cette connexion au-dessus de ce
     * niveau, annulés ; le niveau atteint garde les siennes.
     */
    private function oublierLesNiveauxAuDessusDe(string $connexion, int $niveau): void
    {
        $this->enAttente[$connexion] = array_filter(
            $this->enAttente[$connexion] ?? [],
            fn (int $niveauEnAttente): bool => $niveauEnAttente <= $niveau,
            ARRAY_FILTER_USE_KEY,
        );
    }
}
