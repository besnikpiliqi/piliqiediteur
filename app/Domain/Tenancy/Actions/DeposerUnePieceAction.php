<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\FichierControle;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Services\AlerteEditeur;
use App\Domain\Tenancy\Services\CourriersDeVerification;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
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
 * légal. Le fichier est d'abord contrôlé comme tout fichier reçu (voir
 * ControleDesFichiers, lot FI) : un refus pour une raison de sécurité
 * s'inscrit au journal d'audit de la société, et une menace trouvée par
 * l'antivirus prévient le propriétaire ; accepté, il se range chiffré (voir
 * PiecesDeVerification), avec son nom réécrit, son type, sa taille et
 * l'empreinte SHA-256 du fichier conservé (une photo se réenregistre sans
 * ses métadonnées) ; le journal d'audit garde aussi le nom tel que reçu,
 * l'empreinte et la taille du fichier reçu. Il s'efface dès la décision de
 * l'éditeur, au plus tard trente jours après le dépôt. L'éditeur est
 * prévenu qu'un dossier l'attend. Des pièces ne valident jamais la
 * société : l'éditeur envoie un nouveau courrier, dont seul le code la
 * valide (voir ExaminerLesPiecesAction).
 */
class DeposerUnePieceAction
{
    public const PIECES_EN_ATTENTE_AU_PLUS = 10;

    public const MESSAGE_TROP_DE_PIECES = 'Dix pièces attendent déjà l\'examen de l\'éditeur.';

    public const MESSAGE_PAS_ENCORE = 'Des pièces se déposent une fois le nouveau courrier parti, tant que l\'inscription attend son code.';

    public const MESSAGE_SANS_AUTORISATION = 'Seul le propriétaire de la société dépose des pièces.';

    public function __construct(
        private readonly PiecesDeVerification $pieces,
        private readonly JournalAudit $journalAudit,
        private readonly AlerteEditeur $alerteEditeur,
        private readonly CourriersDeVerification $courriers,
        private readonly ControleDesFichiers $controle,
    ) {}

    /**
     * @throws VerificationRefuseeException
     * @throws FichierRefuseException quand le fichier est refusé par ses contrôles
     */
    public function handle(User $proprietaire, Societe $societe, TypePiece $type, PieceDeposee $depot): PieceVerification
    {
        $piece = app(TenantContext::class)->runAsSociete($societe, function () use ($proprietaire, $societe, $type, $depot): PieceVerification {
            $proprietaire = app(LecteurDesDroits::class)->exigerDansLaSociete($proprietaire, 'verifierLaSociete', $societe, $societe, new VerificationRefuseeException(self::MESSAGE_SANS_AUTORISATION));

            if (! $this->courriers->piecesPossibles($societe->refresh())) {
                throw new VerificationRefuseeException(self::MESSAGE_PAS_ENCORE);
            }

            if (PieceVerification::query()->whereNull('effacee_le')->count() >= self::PIECES_EN_ATTENTE_AU_PLUS) {
                throw new VerificationRefuseeException(self::MESSAGE_TROP_DE_PIECES);
            }

            $fichier = $this->controler($proprietaire, $societe, $type, $depot);
            $chemin = $this->pieces->ranger($societe, $fichier->contenu);

            try {
                return $this->enregistrer($proprietaire, $type, $fichier, $chemin);
            } catch (Throwable $erreur) {
                Storage::disk(PiecesDeVerification::DISQUE)->delete($chemin);

                throw $erreur;
            }
        });

        $cle = CleDuModele::de($societe);
        $this->alerteEditeur->alerter($societe, 'Pièces déposées pour un courrier recommandé non reçu', [
            "Une pièce ({$type->getLabel()}) attend votre examen : php artisan editeur:societe:pieces {$cle}",
            "Ensuite, un nouveau courrier : php artisan editeur:societe:examiner {$cle} ; ou la clôture de l'inscription : php artisan editeur:societe:clore {$cle} --motif=refus",
        ]);

        return $piece;
    }

    /**
     * Le fichier contrôlé ; refusé pour une raison de sécurité, son refus
     * s'inscrit au journal d'audit, dans sa propre transaction, et une
     * menace prévient le propriétaire.
     *
     * @throws FichierRefuseException
     */
    private function controler(User $proprietaire, Societe $societe, TypePiece $type, PieceDeposee $depot): FichierControle
    {
        try {
            return $this->controle->controler($depot);
        } catch (FichierRefuseException $refus) {
            if ($refus->raison->estDeSecurite()) {
                DB::transaction(function () use ($proprietaire, $societe, $type, $refus): void {
                    Societe::verrouillerAvantSonJournalDAudit(CleDuModele::de($societe));
                    $this->journalAudit->inscrire(ActionAuditee::PieceDeVerificationRefusee, $proprietaire, null, null, ['type_piece' => $type->value, ...$refus->valeursAuditees()], $societe);
                });
            }

            if ($refus->raison === RaisonDuRefus::Menace) {
                $societe->proprietaire()->first()?->notify(new AvisDeLAntivirus($societe, 'Fichier refusé par l\'antivirus', 'Le fichier « '.NomDeFichier::pourUnMessage($refus->nomRecu)." » déposé pour l'éditeur ({$type->getLabel()}) a été refusé : l'antivirus y a trouvé la menace « {$refus->menace} ». Rien n'a été enregistré."));
            }

            throw $refus;
        }
    }

    /**
     * La ligne de la pièce et son inscription au journal d'audit ; le
     * fichier déjà rangé s'efface si elles échouent.
     */
    private function enregistrer(User $proprietaire, TypePiece $type, FichierControle $fichier, string $chemin): PieceVerification
    {
        return DB::transaction(function () use ($proprietaire, $type, $fichier, $chemin): PieceVerification {
            $piece = tap((new PieceVerification)->forceFill([
                'type' => $type,
                'nom' => $fichier->nom,
                'type_mime' => $fichier->type,
                'taille' => $fichier->taille(),
                'chemin' => $chemin,
                'empreinte' => $fichier->empreinte(),
                'analysee_le' => $fichier->analyseLe,
                'deposee_par' => $proprietaire->getKey(),
                'deposee_le' => now(),
            ]))->save();

            $this->journalAudit->inscrire(ActionAuditee::PieceDeposee, $proprietaire, null, null, ['type_piece' => $type->value, 'nom' => $piece->nom, 'empreinte' => $piece->empreinte, ...$fichier->valeursRecues()], $piece);

            return $piece;
        });
    }
}
