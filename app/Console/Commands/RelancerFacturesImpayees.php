<?php

namespace App\Console\Commands;

use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\StatutMagasin;
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
 * par magasin : une commande n'a aucun TenantContext au démarrage.
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
            ->where('statut', StatutMagasin::Active)
            ->where('relances_automatiques', true)
            ->each(function (Magasin $magasin) use ($tenantContext, $envoyer, &$totalRelancees) {
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
                    $envoyer->relancer($facture, $facture->client->email, null);
                    $relancees++;
                } catch (LogicException $exception) {
                    // Réglée ou relancée par quelqu'un d'autre entre la
                    // lecture et la relance, adresse du client invalide
                    // (InvalidArgumentException est une LogicException) :
                    // les autres factures partent quand même.
                    $this->warn("Facture {$facture->numero} non relancée : {$exception->getMessage()}");
                }
            });

        return $relancees;
    }
}
