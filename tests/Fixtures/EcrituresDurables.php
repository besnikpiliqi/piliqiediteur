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
 * et validée jusqu'à ce niveau ; celles d'une transaction annulée jusqu'à
 * ce niveau ne le sont pas.
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
     * Les écritures des transactions ouvertes après le départ, en attente
     * de leur validation ou de leur annulation.
     *
     * @var list<string>
     */
    private array $enAttente = [];

    public function __construct(Dispatcher $evenements, private readonly int $niveauDeDepart)
    {
        $evenements->listen(QueryExecuted::class, function (QueryExecuted $requete): void {
            if (preg_match(self::ECRITURE, $requete->sql) !== 1) {
                return;
            }

            if ($requete->connection->transactionLevel() > $this->niveauDeDepart) {
                $this->enAttente[] = $requete->sql;
            } else {
                $this->durables[] = $requete->sql;
            }
        });

        $evenements->listen(TransactionCommitted::class, function (TransactionCommitted $validation): void {
            if ($validation->connection->transactionLevel() <= $this->niveauDeDepart) {
                array_push($this->durables, ...$this->enAttente);
                $this->enAttente = [];
            }
        });

        $evenements->listen(TransactionRolledBack::class, function (TransactionRolledBack $annulation): void {
            if ($annulation->connection->transactionLevel() <= $this->niveauDeDepart) {
                $this->enAttente = [];
            }
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
}
