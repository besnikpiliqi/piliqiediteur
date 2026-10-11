<?php

namespace App\Console\Commands\Editeur;

use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\File;
use LogicException;

use function Laravel\Prompts\pause;

/**
 * La consultation par l'éditeur des pièces déposées par une société (voir
 * DeposerUnePieceAction) : déchiffrées dans un dossier temporaire privé,
 * le temps de l'examen, effacé à la fin de la commande, sur une erreur, à
 * l'arrêt du processus (exit, erreur fatale), quand l'opérateur
 * l'interrompt par Ctrl+C (lu comme une touche par l'invite, qui arrête la
 * commande) et sur un signal d'arrêt (SIGINT, SIGTERM, SIGHUP, traités dès
 * que le processus reprend la main). Un dossier resté malgré tout
 * (processus tué sans pouvoir réagir) s'efface au plus tard une heure
 * après (voir app:effacer-pieces-verification). La consultation s'inscrit
 * au journal d'audit. La décision se prend ensuite par
 * editeur:societe:valider ou editeur:societe:refuser.
 */
#[Signature('editeur:societe:pieces {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Déchiffre le temps de l\'examen les pièces déposées par une société, et journalise la consultation')]
class ConsulterLesPieces extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Les pièces seront déchiffrées dans un dossier temporaire, effacé à la fin de l\'examen, et au plus tard une heure après.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $pieces = app(TenantContext::class)->runAsSociete($societe, fn () => PieceVerification::query()->whereNull('effacee_le')->orderBy('id')->get());

        if ($pieces->isEmpty()) {
            throw new LogicException('Aucune pièce n\'attend d\'examen pour cette société.');
        }

        $dossier = PiecesDeVerification::nouveauDossierDExamen();
        $effacer = static fn (): bool => File::deleteDirectory($dossier);
        File::ensureDirectoryExists($dossier, 0700);
        register_shutdown_function($effacer);
        $this->trap(fn (): array => [SIGINT, SIGTERM, SIGHUP], function (int $signal) use ($effacer): never {
            $effacer();

            exit(128 + $signal);
        });

        try {
            foreach ($pieces as $piece) {
                File::put("{$dossier}/piece-{$piece->getKey()}-{$piece->type->value}", app(PiecesDeVerification::class)->dechiffrer($piece));
                chmod("{$dossier}/piece-{$piece->getKey()}-{$piece->type->value}", 0600);
            }

            app(TenantContext::class)->runAsSociete($societe, fn () => app(JournalAudit::class)->inscrire(ActionAuditee::PiecesConsultees, null, null, null, [
                'pieces' => $pieces->map(fn (PieceVerification $piece): string => $piece->type->value)->values()->all(),
            ], $societe, $editeur));

            $this->line("Pièces déchiffrées dans {$dossier}");
            pause('Appuyez sur Entrée une fois l\'examen terminé : le dossier sera effacé');
        } finally {
            $effacer();
        }

        return 'Examen terminé, dossier effacé.';
    }
}
