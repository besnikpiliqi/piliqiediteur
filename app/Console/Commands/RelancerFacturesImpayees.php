<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Relances automatiques des factures impayées (voir bootstrap/app.php),
 * pour chaque magasin qui les a activées (Paramètres > Relances), magasin
 * par magasin : une commande n'a aucun TenantContext au démarrage. Seul un
 * magasin réel qui peut vendre écrit à ses clients (voir
 * Magasin::peutEcrireAuxClients()) : actif, non suspendu, d'une société
 * active et validée, son établissement confirmé, jamais la démonstration ;
 * la requête ne lit que ceux-là, et chacun se contrôle encore avant ses
 * relances.
 *
 * Une facture échue, pas entièrement réglée, dont le client a une adresse
 * e-mail -- même archivé, il doit toujours la facture --, reçoit la
 * relance qui suit sa dernière, quand elle est due (voir
 * Magasin::relanceDue()) : dans l'ordre, au plus une par jour, jamais deux
 * fois le même niveau. Une relance manuelle compte comme les autres ; une
 * relance en échec repart (voir Facture::relancesComptees()).
 */
#[Signature('app:relancer-factures-impayees')]
#[Description('Relance par e-mail les factures échues et impayées des magasins qui ont activé les relances automatiques')]
class RelancerFacturesImpayees extends Command
{
    public function handle(TenantContext $tenantContext, EnvoyerDocumentAction $envoyer): int
    {
        $totalRelancees = 0;

        Magasin::query()
            ->where('est_demonstration', false)
            ->where('statut', StatutMagasin::Actif)
            ->whereNull('suspendu_le')
            ->whereNotNull('etablissement_verifie_le')
            ->whereHas('societe', fn (Builder $societes) => $societes
                ->where('statut', StatutSociete::Active)
                ->whereNotNull('validee_le')
                ->whereNull('refusee_le'))
            ->where('relances_automatiques', true)
            ->orderBy('id')
            ->each(function (Magasin $magasin) use ($tenantContext, $envoyer, &$totalRelancees) {
                if (! $magasin->peutEcrireAuxClients()) {
                    return;
                }

                $totalRelancees += $tenantContext->runAs($magasin, fn (): int => $this->relancerLesFacturesDe($magasin, $envoyer));
            });

        $this->info("{$totalRelancees} relance(s) envoyée(s).");

        return self::SUCCESS;
    }

    private function relancerLesFacturesDe(Magasin $magasin, EnvoyerDocumentAction $envoyer): int
    {
        $relancees = 0;

        Facture::query()
            ->with('client')
            ->whereIn('statut', StatutFacture::quiAttendentUnReglement())
            ->whereDate('date_echeance', '<', today())
            ->whereHas('client', fn (Builder $client) => $client->whereNotNull('email')->where('email', '!=', ''))
            ->lazyById()
            ->each(function (Facture $facture) use ($magasin, $envoyer, &$relancees) {
                $due = $magasin->relanceDue(
                    $facture->niveauDeRelanceAtteint() + 1,
                    (int) $facture->date_echeance->diffInDays(today()),
                    $facture->joursDepuisLaDerniereRelance(),
                );

                if (! $due || ! $facture->estEchueEtImpayee()) {
                    return;
                }

                try {
                    $envoyer->relanceAutomatique($facture);
                    $relancees++;
                } catch (LogicException|RefusFiscalException $exception) {
                    // Réglée ou relancée par quelqu'un d'autre entre la
                    // lecture et la relance, adresse du client invalide
                    // (InvalidArgumentException est une LogicException),
                    // facture numérotée sans enregistrement signé, refus
                    // fiscal signalé en plus au journal de l'application :
                    // les autres factures partent quand même.
                    if ($exception instanceof RefusFiscalException) {
                        report($exception);
                    }

                    $this->warn("Facture {$facture->numero} non relancée : {$exception->getMessage()}");
                }
            });

        return $relancees;
    }
}
