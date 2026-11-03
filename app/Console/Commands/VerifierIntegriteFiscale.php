<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Actions\VerifierIntegriteAction;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\CaisseSecurisee\Services\RapportIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * Vérifie l'intégrité de la caisse sécurisée (voir VerifierIntegriteAction)
 * d'un magasin, ou de chaque magasin qui a des données fiscales, et
 * échoue au moindre défaut.
 *
 * Planifiée chaque nuit à 3 h, heure de Paris (voir bootstrap/app.php) :
 * la vérification est incrémentale, depuis la précédente, sauf une fois
 * par mois, le jour du mois propre à chaque magasin (voir
 * jourDeLaVerificationComplete()), où elle reprend tout le registre ; les
 * vérifications complètes se répartissent ainsi sur le mois au lieu de
 * tomber toutes la même nuit. --complet vérifie tout, quel que soit le
 * jour.
 */
#[Signature('caisse:verifier {magasin? : L\'identifiant ou l\'uuid du magasin (par défaut, chaque magasin qui a des données fiscales)} {--complet : Vérifie tout le registre au lieu de reprendre après la dernière vérification}')]
#[Description('Vérifie l\'intégrité de la caisse sécurisée : chaînes signées, documents et numérotation')]
class VerifierIntegriteFiscale extends Command
{
    /**
     * Le jour du mois où la vérification planifiée du magasin est
     * complète : de 1 à 28, pour que chaque mois en ait un.
     */
    public static function jourDeLaVerificationComplete(Magasin $magasin): int
    {
        return ($magasin->getKey() - 1) % 28 + 1;
    }

    public function handle(TenantContext $tenantContext, VerifierIntegriteAction $verifier): int
    {
        $magasins = $this->magasinsAVerifier();

        if ($magasins === null) {
            $this->error("Aucun magasin « {$this->argument('magasin')} ».");

            return self::FAILURE;
        }

        $lignes = [];
        $echecs = 0;

        foreach ($magasins as $magasin) {
            $complet = $this->option('complet') || (int) Calendrier::afficherJour(Calendrier::deParis()->aujourdhui(), 'j') === self::jourDeLaVerificationComplete($magasin);

            try {
                $rapport = $tenantContext->runAs($magasin, fn () => $verifier->parLeSysteme($magasin, $complet));
            } catch (RefusFiscalException $refus) {
                $echecs++;
                $lignes[] = [$magasin->nom, '—', '—', 'Refusée : '.$refus->getMessage()];

                continue;
            }

            $lignes[] = [
                $magasin->nom,
                $rapport->complet ? 'Complète' : 'Incrémentale',
                (string) $rapport->nombreDEnregistrementsVerifies(),
                $rapport->estConforme() ? 'Aucune' : (string) $rapport->nombreDAnomalies(),
            ];

            if (! $rapport->estConforme()) {
                $echecs++;
                $this->afficherLesAnomalies($magasin, $rapport);
            }
        }

        $this->table(['Magasin', 'Vérification', 'Enregistrements vérifiés', 'Anomalies'], $lignes);

        if ($echecs > 0) {
            $this->error("Intégrité de la caisse sécurisée : {$echecs} magasin(s) en défaut.");

            return self::FAILURE;
        }

        $this->info('Intégrité de la caisse sécurisée vérifiée : aucune anomalie.');

        return self::SUCCESS;
    }

    /**
     * Les anomalies du magasin que garde le rapport, comme le journal des
     * événements (voir RapportIntegrite::ANOMALIES_RETENUES), puis le
     * nombre des autres : une clé retirée du dépôt n'inonde pas la sortie
     * de la commande.
     */
    private function afficherLesAnomalies(Magasin $magasin, RapportIntegrite $rapport): void
    {
        foreach ($rapport->anomalies() as $anomalie) {
            $this->error("{$magasin->nom} [{$anomalie->code->value}] {$anomalie->message}");
        }

        $autres = $rapport->nombreDAnomalies() - count($rapport->anomalies());

        if ($autres > 0) {
            $this->error("{$magasin->nom} : et {$autres} autre(s) anomalie(s).");
        }
    }

    /**
     * Le magasin demandé, par identifiant ou uuid (null s'il n'existe pas),
     * ou chaque magasin qui a des données fiscales : un registre, un
     * document numéroté ou un paiement. Un magasin qui n'a encore rien
     * vendu n'a rien à vérifier, et son registre n'est pas ouvert pour
     * autant. Chaque sous-requête est filtrée par magasin elle-même.
     *
     * @return Collection<int, Magasin>|null
     */
    private function magasinsAVerifier(): ?Collection
    {
        $identifiant = $this->argument('magasin');

        if ($identifiant !== null) {
            $magasin = Magasin::trouverParIdentifiantOuUuid((string) $identifiant);

            return $magasin === null ? null : collect([$magasin]);
        }

        $donneesDuMagasin = static fn (string $table, bool $numerote = false): Closure => static fn (QueryBuilder $lignes): QueryBuilder => $lignes
            ->from($table)
            ->whereColumn("{$table}.magasin_id", 'magasins.id')
            ->when($numerote, static fn (QueryBuilder $documents): QueryBuilder => $documents->whereNotNull("{$table}.numero"));

        return Magasin::query()
            ->where(fn (Builder $magasins): Builder => $magasins
                ->whereExists($donneesDuMagasin('tetes_registre_fiscal'))
                ->orWhereExists($donneesDuMagasin('enregistrements_fiscaux'))
                ->orWhereExists($donneesDuMagasin('factures', true))
                ->orWhereExists($donneesDuMagasin('avoirs', true))
                ->orWhereExists($donneesDuMagasin('paiements')))
            ->orderBy('id')
            ->get();
    }
}
