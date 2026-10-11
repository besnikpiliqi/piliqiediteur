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

/**
 * La consultation par l'éditeur des pièces déposées par une société (voir
 * DeposerUnePieceAction) : déchiffrées dans un dossier temporaire privé,
 * le temps de l'examen, effacé à la fin de la commande quoi qu'il arrive ;
 * la consultation s'inscrit au journal d'audit. La décision se prend
 * ensuite par editeur:societe:valider ou editeur:societe:refuser.
 */
#[Signature('editeur:societe:pieces {societe : Identifiant ou uuid de la société} '.ProcedureDeLEditeur::OPTIONS_COMMUNES)]
#[Description('Déchiffre le temps de l\'examen les pièces déposées par une société, et journalise la consultation')]
class ConsulterLesPieces extends ProcedureDeLEditeur
{
    protected function resume(Societe $societe): array
    {
        return ['Les pièces seront déchiffrées dans un dossier temporaire, effacé à la fin de l\'examen.'];
    }

    protected function executer(Societe $societe, InterventionDeLEditeur $editeur): string
    {
        $pieces = app(TenantContext::class)->runAsSociete($societe, fn () => PieceVerification::query()->whereNull('effacee_le')->orderBy('id')->get());

        if ($pieces->isEmpty()) {
            throw new LogicException('Aucune pièce n\'attend d\'examen pour cette société.');
        }

        $dossier = storage_path('app/private/examen-'.bin2hex(random_bytes(8)));
        File::ensureDirectoryExists($dossier, 0700);

        try {
            foreach ($pieces as $piece) {
                File::put("{$dossier}/piece-{$piece->getKey()}-{$piece->type->value}", app(PiecesDeVerification::class)->dechiffrer($piece));
                chmod("{$dossier}/piece-{$piece->getKey()}-{$piece->type->value}", 0600);
            }

            app(TenantContext::class)->runAsSociete($societe, fn () => app(JournalAudit::class)->inscrire(ActionAuditee::PiecesConsultees, null, null, null, [
                'pieces' => $pieces->map(fn (PieceVerification $piece): string => $piece->type->value)->values()->all(),
            ], $societe, $editeur));

            $this->line("Pièces déchiffrées dans {$dossier}");
            $this->ask('Appuyez sur Entrée une fois l\'examen terminé : le dossier sera effacé');
        } finally {
            File::deleteDirectory($dossier);
        }

        return 'Examen terminé, dossier effacé.';
    }
}
