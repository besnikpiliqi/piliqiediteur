<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;
use App\Domain\Facturation\Services\ArchiveDesDocuments;
use App\Domain\Facturation\Services\DocumentsDeLaPeriode;
use App\Domain\Facturation\Services\DocumentTrouve;
use App\Domain\Facturation\Services\RecapitulatifDesDocuments;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutDuTelechargement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Notifications\AvisDuTelechargementDesDocuments;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Prépare le fichier d'un téléchargement des documents par période (voir
 * DemanderLeTelechargementDesDocumentsAction), dans la file d'attente :
 * relit, dans la société de la demande, les documents choisis que la
 * personne voit encore et où elle télécharge encore (voir
 * DocumentsDeLaPeriode::choisis()), écrit le ZIP de leurs PDF (voir
 * ArchiveDesDocuments) ou leur récapitulatif CSV (voir
 * RecapitulatifDesDocuments) sur le disque privé « telechargements »,
 * rangé par société, sous un nom tiré au hasard, puis, dans une seule
 * transaction, marque la demande prête et inscrit le téléchargement au
 * journal d'audit de cette société (auteur, magasins, période, types,
 * origine, nombre de documents, format), y compris pour un compte d'un
 * cabinet lié ; la personne en est prévenue dans sa cloche (voir
 * AvisDuTelechargementDesDocuments). Un fichier dont l'écriture ou la
 * transaction échoue s'efface.
 *
 * Aucun document encore accessible, ou un compte qui n'agit plus : la
 * demande échoue, sans nouvelle tentative, la personne sachant pourquoi ;
 * une erreur imprévue fait réessayer, puis échouer de même (voir
 * failed()). Idempotent : une demande qui n'est plus en préparation ne se
 * prépare plus, et ShouldBeUnique n'en lance jamais deux préparations à
 * la fois.
 */
class PreparerLeTelechargementDesDocumentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const DISQUE = 'telechargements';

    public const MOTIF_COMPTE = 'Votre compte n\'a plus accès à ces documents : le fichier n\'a pas été préparé.';

    public const MOTIF_ERREUR = 'Le fichier n\'a pas pu être préparé : demandez-le de nouveau.';

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [60];

    /**
     * Une archive de nombreux documents se prépare en plusieurs minutes.
     */
    public int $timeout = 1800;

    public function __construct(public readonly int $telechargementId) {}

    public function uniqueId(): string
    {
        return (string) $this->telechargementId;
    }

    public function handle(TenantContext $contexte, DocumentsDeLaPeriode $documents, ArchiveDesDocuments $archive, RecapitulatifDesDocuments $recapitulatif, JournalAudit $journal): void
    {
        $telechargement = TelechargementDeDocuments::query()->withoutGlobalScopes()->find($this->telechargementId);

        if ($telechargement === null || $telechargement->statut !== StatutDuTelechargement::EnPreparation) {
            return;
        }

        $societe = Societe::query()->findOrFail($telechargement->societe_id);
        $compte = $telechargement->demandeur;

        $contexte->runAsSociete($societe, function () use ($telechargement, $societe, $compte, $documents, $archive, $recapitulatif, $journal): void {
            if (! $compte->peutAgir()) {
                $this->echouer($telechargement, self::MOTIF_COMPTE);

                return;
            }

            $magasins = Magasin::query()
                ->where('societe_id', $societe->getKey())
                ->whereIn('id', $telechargement->magasins)
                ->orderBy('est_demonstration')
                ->orderBy('nom')
                ->orderBy('id')
                ->get();
            $choisis = $documents->choisis($compte, $magasins, (string) $telechargement->du, (string) $telechargement->au, $telechargement->typesDemandes(), $telechargement->origine, $telechargement->selection ?? []);

            if ($choisis === []) {
                $this->echouer($telechargement, ArchiveDesDocuments::AUCUN_DOCUMENT);

                return;
            }

            $chemin = $societe->getKey().'/'.Str::random(40).'.'.$telechargement->format->value;

            try {
                $this->ecrire($telechargement, $compte, $choisis, $chemin, $archive, $recapitulatif);
                $this->marquerPret($telechargement, $societe, $compte, $choisis, $chemin, $magasins, $journal);
            } catch (Throwable $erreur) {
                Storage::disk(self::DISQUE)->delete($chemin);

                throw $erreur;
            }
        });
    }

    /**
     * Dans une seule transaction : la demande prête, avec son fichier, et
     * son entrée au journal d'audit de la société ; la personne en est
     * prévenue. Une demande qui n'est plus en préparation garde son état,
     * et le fichier écrit s'efface.
     *
     * @param  list<DocumentTrouve>  $choisis
     * @param  Collection<int, Magasin>  $magasins
     */
    private function marquerPret(TelechargementDeDocuments $telechargement, Societe $societe, User $compte, array $choisis, string $chemin, Collection $magasins, JournalAudit $journal): void
    {
        DB::transaction(function () use ($telechargement, $societe, $compte, $choisis, $chemin, $magasins, $journal): void {
            Societe::verrouillerAvantSonJournalDAudit($societe->getKey());
            $relu = TelechargementDeDocuments::query()->whereKey($telechargement->getKey())->lockForUpdate()->firstOrFail();

            if ($relu->statut !== StatutDuTelechargement::EnPreparation) {
                Storage::disk(self::DISQUE)->delete($chemin);

                return;
            }

            $relu->forceFill([
                'statut' => StatutDuTelechargement::Pret,
                'chemin' => $chemin,
                'taille' => Storage::disk(self::DISQUE)->size($chemin),
                'nombre_documents' => count($choisis),
                'prepare_le' => now(),
            ])->save();

            $journal->inscrire(ActionAuditee::DocumentsTelecharges, $compte, null, null, [
                'magasins' => $magasins->filter(fn (Magasin $magasin): bool => collect($choisis)->contains(fn (DocumentTrouve $document): bool => $document->magasinId === (int) $magasin->getKey()))
                    ->map(fn (Magasin $magasin): string => $magasin->code)
                    ->values()
                    ->all(),
                'du' => (string) $relu->du,
                'au' => (string) $relu->au,
                'types' => array_map(fn (TypeDeDocumentTelechargeable $type): string => $type->value, $relu->typesDemandes()),
                'origine' => $relu->origine->value,
                'nombre_documents' => count($choisis),
                'format' => $relu->format->value,
            ], $relu);

            $compte->notify(new AvisDuTelechargementDesDocuments($relu));
        });
    }

    /**
     * Une erreur imprévue, après toutes ses tentatives : la demande échoue,
     * la personne le sait.
     */
    public function failed(?Throwable $exception): void
    {
        $telechargement = TelechargementDeDocuments::query()->withoutGlobalScopes()->find($this->telechargementId);

        if ($telechargement !== null && $telechargement->statut === StatutDuTelechargement::EnPreparation) {
            $this->echouer($telechargement, self::MOTIF_ERREUR);
        }
    }

    /**
     * Écrit le fichier de ces documents à ce chemin du disque : l'archive
     * se compose d'abord dans un fichier local temporaire, effacé ensuite.
     *
     * @param  list<DocumentTrouve>  $choisis
     */
    private function ecrire(TelechargementDeDocuments $telechargement, User $compte, array $choisis, string $chemin, ArchiveDesDocuments $archive, RecapitulatifDesDocuments $recapitulatif): void
    {
        $du = (string) $telechargement->du;
        $au = (string) $telechargement->au;

        if ($telechargement->format === FormatDuTelechargement::Csv) {
            Storage::disk(self::DISQUE)->put($chemin, $recapitulatif->ecrire($compte, $choisis, $du, $au));

            return;
        }

        $local = storage_path('framework/cache/telechargement-'.Str::random(32).'.zip');

        try {
            $archive->ecrire($compte, $choisis, $du, $au, $local);
            $flux = fopen($local, 'rb');

            if ($flux === false) {
                throw new RuntimeException('L\'archive des documents ne se relit pas.');
            }

            try {
                Storage::disk(self::DISQUE)->writeStream($chemin, $flux);
            } finally {
                fclose($flux);
            }
        } finally {
            File::delete($local);
        }
    }

    /**
     * La demande échoue pour ce motif, et la personne le sait.
     */
    private function echouer(TelechargementDeDocuments $telechargement, string $motif): void
    {
        $echec = DB::transaction(function () use ($telechargement, $motif): ?TelechargementDeDocuments {
            $relu = TelechargementDeDocuments::query()->withoutGlobalScopes()->whereKey($telechargement->getKey())->lockForUpdate()->first();

            if ($relu === null || $relu->statut !== StatutDuTelechargement::EnPreparation) {
                return null;
            }

            $relu->forceFill(['statut' => StatutDuTelechargement::Echec, 'motif_echec' => $motif])->save();

            return $relu;
        });

        $echec?->demandeur->notify(new AvisDuTelechargementDesDocuments($echec));
    }
}
