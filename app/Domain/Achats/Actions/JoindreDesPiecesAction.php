<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * « Ajouter des pièces » sur la fiche d'une facture d'achat ou d'un avoir
 * fournisseur : joint la vraie facture ou la vraie note d'avoir du
 * fournisseur, en brouillon comme après la validation (voir
 * PiecesDuFournisseur), et lève la marque « pièce du fournisseur
 * manquante ». Chaque fichier est contrôlé avant toute transaction (voir
 * PiecesDuFournisseur::controler()).
 *
 * Dans une transaction, la ligne de la société se verrouille d'abord (les
 * pièces s'inscrivent au journal d'audit, voir
 * Societe::verrouillerAvantSonJournalDAudit()), puis celle du magasin, en
 * partage (un magasin en lecture seule ne reçoit plus de pièce, voir
 * Magasin::verifierQuIlEstOuvert()), puis son auteur, relu sous le verrou
 * de sa ligne : il doit pouvoir joindre une pièce à ce document (voir
 * FactureAchatPolicy::joindreUnePiece() et
 * AvoirFournisseurPolicy::joindreUnePiece()), un compte du cabinet lié
 * compris quand le client lui accorde la saisie des achats. La ligne du
 * document se verrouille ensuite.
 */
class JoindreDesPiecesAction
{
    public function __construct(
        private readonly PiecesDuFournisseur $pieces,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  list<PieceDeposee>  $pieces
     * @return list<PieceDuFournisseur>
     *
     * @throws AuthorizationException
     * @throws LogicException quand le magasin est en lecture seule
     * @throws InvalidArgumentException quand une pièce est refusée (FichierRefuseException pour ses contrôles) ou qu'aucune n'est donnée
     */
    public function handle(User $auteur, FactureAchat|AvoirFournisseur $document, array $pieces): array
    {
        if ($pieces === []) {
            throw new InvalidArgumentException('Choisissez au moins un fichier à joindre.');
        }

        $pieces = $this->pieces->controler($auteur, $document, $pieces);

        return DB::transaction(function () use ($auteur, $document, $pieces): array {
            $magasin = app(TenantContext::class)->magasin();
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'joindreUnePiece', $magasin, $document);

            return $this->operateur->pendant($compte, fn (): array => $this->pieces->joindre($compte, $document, $pieces));
        });
    }
}
