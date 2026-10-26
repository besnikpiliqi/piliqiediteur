<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Le propriétaire dépose une pièce quand ses courriers recommandés ne lui
 * sont pas parvenus, une fois son nouveau courrier parti (voir
 * CourriersDeVerification::piecesPossibles()), pour que l'éditeur traite
 * son cas à la main : extrait Kbis ou extrait du registre national des
 * entreprises de moins de trois mois, pièce d'identité du représentant
 * légal. Le fichier se range chiffré (voir PiecesDeVerification), avec
 * l'empreinte SHA-256 de ce qui a été déposé ; il s'efface dès la décision
 * de l'éditeur, au plus tard trente jours après le dépôt. L'éditeur est
 * prévenu qu'un dossier l'attend. Des pièces ne valident jamais la
 * société : l'éditeur envoie un nouveau courrier, dont seul le code la
 * valide (voir ExaminerLesPiecesAction).
 */
class DeposerUnePieceAction
{
    public const TAILLE_MAXIMALE_EN_OCTETS = 10 * 1024 * 1024;

    public const PIECES_EN_ATTENTE_AU_PLUS = 10;

    public const MESSAGE_VIDE_OU_TROP_GROS = 'La pièce doit être un fichier de 10 Mo au plus.';

    public const MESSAGE_TROP_DE_PIECES = 'Dix pièces attendent déjà l\'examen de l\'éditeur.';

    public const MESSAGE_PAS_ENCORE = 'Des pièces se déposent une fois le nouveau courrier parti, tant que l\'inscription attend son code.';

    public const MESSAGE_SANS_AUTORISATION = 'Seul le propriétaire de la société dépose des pièces.';

    public function __construct(
        private readonly PiecesDeVerification $pieces,
        private readonly JournalAudit $journalAudit,
        private readonly AlerteEditeur $alerteEditeur,
        private readonly CourriersDeVerification $courriers,
    ) {}

    /**
     * @param  string  $contenu  le contenu du fichier déposé
     *
     * @throws VerificationRefuseeException
     */
    public function handle(User $proprietaire, Societe $societe, TypePiece $type, string $contenu): PieceVerification
    {
        $piece = app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe, $type, $contenu): PieceVerification {
            $proprietaire = app(LecteurDesDroits::class)->exigerDansLaSociete($proprietaire, 'verifierLaSociete', $societe, $societe, new VerificationRefuseeException(self::MESSAGE_SANS_AUTORISATION));

            if (! $this->courriers->piecesPossibles($societe->refresh())) {
                throw new VerificationRefuseeException(self::MESSAGE_PAS_ENCORE);
            }

            if ($contenu === '' || strlen($contenu) > self::TAILLE_MAXIMALE_EN_OCTETS) {
                throw new VerificationRefuseeException(self::MESSAGE_VIDE_OU_TROP_GROS);
            }

            if (PieceVerification::query()->whereNull('effacee_le')->count() >= self::PIECES_EN_ATTENTE_AU_PLUS) {
                throw new VerificationRefuseeException(self::MESSAGE_TROP_DE_PIECES);
            }

            $chemin = $this->pieces->ranger($societe, $contenu);

            try {
                return $this->enregistrer($proprietaire, $type, $contenu, $chemin);
            } catch (Throwable $erreur) {
                Storage::disk(PiecesDeVerification::DISQUE)->delete($chemin);

                throw $erreur;
            }
        });

        $this->alerteEditeur->alerter($societe, 'Pièces déposées pour un courrier recommandé non reçu', [
            "Une pièce ({$type->getLabel()}) attend votre examen : php artisan editeur:societe:pieces {$societe->getKey()}",
            "Ensuite, un nouveau courrier : php artisan editeur:societe:examiner {$societe->getKey()} ; ou la clôture de l'inscription : php artisan editeur:societe:refuser {$societe->getKey()}",
        ]);

        return $piece;
    }

    /**
     * La ligne de la pièce et son inscription au journal d'audit ; le
     * fichier déjà rangé s'efface si elles échouent.
     */
    private function enregistrer(User $proprietaire, TypePiece $type, string $contenu, string $chemin): PieceVerification
    {
        return DB::transaction(function () use ($proprietaire, $type, $contenu, $chemin): PieceVerification {
            $piece = tap((new PieceVerification)->forceFill([
                'type' => $type,
                'chemin' => $chemin,
                'empreinte' => hash('sha256', $contenu),
                'deposee_par' => $proprietaire->getKey(),
                'deposee_le' => now(),
            ]))->save();

            $this->journalAudit->inscrire(ActionAuditee::PieceDeposee, $proprietaire, null, null, ['type_piece' => $type->value, 'empreinte' => $piece->empreinte], $piece);

            return $piece;
        });
    }
}
