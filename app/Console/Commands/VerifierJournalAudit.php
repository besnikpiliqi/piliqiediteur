<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\VerificateurJournalAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Vérifie les chaînes du journal d'audit d'une société, ou de chaque
 * société (voir VerificateurJournalAudit), et échoue à la moindre
 * anomalie, qu'elle liste. Planifiée chaque nuit (voir bootstrap/app.php).
 */
#[Signature('app:verifier-journal-audit {societe? : L\'identifiant ou l\'uuid de la société (par défaut, chaque société)}')]
#[Description('Vérifie les chaînes du journal d\'audit : séquences, empreintes, têtes et clés précédentes')]
class VerifierJournalAudit extends Command
{
    public function handle(VerificateurJournalAudit $verificateur): int
    {
        $societes = $this->societesAVerifier();

        if ($societes->isEmpty() && $this->argument('societe') !== null) {
            $this->error("Aucune société « {$this->argument('societe')} ».");

            return self::FAILURE;
        }

        $anomalies = 0;

        foreach ($societes as $societe) {
            foreach ($verificateur->verifier($societe) as $anomalie) {
                $this->error("Société n° {$societe->getKey()} : {$anomalie}");
                $anomalies++;
            }
        }

        if ($anomalies > 0) {
            return self::FAILURE;
        }

        $this->info("Journal d'audit intègre ({$societes->count()} société(s)).");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Societe>
     */
    private function societesAVerifier(): Collection
    {
        $identifiant = $this->argument('societe');

        if ($identifiant === null) {
            return Societe::query()->orderBy('id')->get();
        }

        $societe = match (true) {
            ctype_digit((string) $identifiant) => Societe::query()->find((int) $identifiant),
            Str::isUuid((string) $identifiant) => Societe::query()->firstWhere('uuid', $identifiant),
            default => null,
        };

        return $societe === null ? collect() : collect([$societe]);
    }
}
