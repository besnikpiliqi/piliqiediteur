<?php

namespace App\Domain\Tenancy\Isolation;

use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * La vérification de l'isolation de toute la base (protocole de rigueur,
 * point 6, demande de l'éditeur du 15/11/2026) : par la base (voir
 * ControlesDeLaBase, couche « SQL ») et par l'application (voir
 * ControlesDeLApplication, couche « Laravel »), qui doivent être d'accord.
 * Elle parcourt toute la base, ne lit que des identifiants internes, n'écrit
 * rien et ne rend jamais une donnée personnelle (voir ProblemeDIsolation).
 * La lancent la commande app:verifier-isolation, la fin du semis et la
 * tâche de chaque nuit.
 */
final class VerificationDeLIsolation
{
    public function __construct(private readonly TenantContext $contexte) {}

    public function verifier(?ConnectionInterface $base = null): RapportDIsolation
    {
        $base ??= DB::connection();
        $schema = new SchemaDeLaBase($base);
        $this->contexte->clear();

        try {
            return new RapportDIsolation([
                ...(new ControlesDeLaBase($base, $schema))->tous(),
                ...(new ControlesDeLApplication($base, $this->contexte))->tous(),
            ]);
        } finally {
            $this->contexte->clear();
        }
    }
}
