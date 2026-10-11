<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TeteJournalAudit;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Commande planifiée chaque nuit (voir bootstrap/app.php) : efface les
 * entrées du journal d'audit dont la durée de conservation est passée
 * (expire_le, selon leur catégorie, voir CategorieAudit), société par
 * société, sous la société de chacune, chaîne par chaîne, par le début de
 * la chaîne et dans l'ordre des séquences : la première entrée qui n'a
 * pas expiré arrête l'effacement de sa chaîne. La tête de la chaîne,
 * verrouillée comme pour une inscription, retient le point de reprise (la
 * séquence et l'empreinte de la dernière entrée effacée), d'où repart la
 * vérification (voir VerificateurJournalAudit). Une entrée sans date
 * d'expiration ne s'efface jamais ; la base refuse tout autre effacement
 * (voir DeclencheursDeProtection::effacementApresExpiration()). La trace
 * d'une entrée effacée, d'un an, s'efface avec elle si elle demeure.
 *
 * La panne d'une société n'arrête pas les suivantes : elle est signalée
 * (report()), et reprise au passage suivant.
 */
#[Signature('app:purger-journal-audit')]
#[Description('Efface les entrées expirées du journal d\'audit, par le début de chaque chaîne')]
class PurgerJournalAudit extends Command
{
    public function handle(TenantContext $tenantContext): int
    {
        $effacees = 0;
        $enEchec = 0;

        Societe::query()->orderBy('id')->each(function (Societe $societe) use ($tenantContext, &$effacees, &$enEchec): void {
            try {
                $effacees += $tenantContext->runAsSociete($societe, fn (): int => $this->purgerLaSociete($societe));
            } catch (Throwable $exception) {
                report($exception);
                $enEchec++;
            }
        });

        Log::info('Entrées expirées du journal d\'audit effacées.', ['nombre' => $effacees]);
        $this->info("{$effacees} entrée(s) du journal d'audit effacée(s).");

        if ($enEchec > 0) {
            $this->error("{$enEchec} société(s) en échec, reprise(s) au prochain passage.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function purgerLaSociete(Societe $societe): int
    {
        $effacees = 0;

        foreach (TeteJournalAudit::query()->where('societe_id', $societe->getKey())->orderBy('id')->pluck('id') as $teteId) {
            $effacees += DB::transaction(function () use ($teteId): int {
                $tete = TeteJournalAudit::query()->whereKey($teteId)->lockForUpdate()->firstOrFail();
                $expirees = EntreeAudit::query()
                    ->where('categorie', $tete->categorie->value)
                    ->when($tete->magasin_id === null, fn ($requete) => $requete->whereNull('magasin_id'), fn ($requete) => $requete->where('magasin_id', $tete->magasin_id))
                    ->where('sequence', '>', $tete->reprise_sequence ?? 0)
                    ->orderBy('sequence')
                    ->get(['id', 'sequence', 'empreinte', 'expire_le'])
                    ->takeWhile(fn (EntreeAudit $entree): bool => $entree->expire_le !== null && $entree->expire_le->lessThanOrEqualTo(now()));

                if ($expirees->isEmpty()) {
                    return 0;
                }

                $identifiants = $expirees->pluck('id')->all();
                $effacables = EntreeAudit::query()
                    ->whereKey($identifiants)
                    ->where('expire_le', '<=', HorodatageUtc::enBase(now()))
                    ->whereRaw(DeclencheursDeProtection::conditionDExpiration())
                    ->count();

                if ($effacables !== count($identifiants)) {
                    return 0;
                }

                $derniere = $expirees->last();
                TraceAudit::query()->whereIn('entree_audit_id', $identifiants)->delete();
                $tete->forceFill(['reprise_sequence' => $derniere->sequence, 'reprise_empreinte' => $derniere->empreinte])->save();

                return EntreeAudit::query()->whereKey($identifiants)->delete();
            });
        }

        return $effacees;
    }
}
