<?php

namespace App\Domain\Achats\Actions;

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
 * « Retirer » ou « Remplacer » une pièce du fournisseur d'une facture
 * d'achat encore en brouillon : avant la validation, une pièce déposée par
 * erreur se retire, ou se remplace par une autre dans la même transaction
 * (voir PiecesDuFournisseur) ; jointe à une facture validée ou à un avoir
 * émis, elle ne se retire plus. Le retrait, puis l'ajout, s'inscrivent au
 * journal d'audit, et la marque « pièce du fournisseur manquante » suit ce
 * qui reste joint. Le fichier qui remplace est contrôlé avant toute
 * transaction (voir PiecesDuFournisseur::controler()).
 *
 * Mêmes verrous que JoindreDesPiecesAction : la société, le magasin en
 * partage, l'auteur relu sous le verrou de sa ligne, qui doit pouvoir
 * retirer une pièce de ce brouillon (voir
 * FactureAchatPolicy::retirerUnePiece()), puis le document.
 */
class RetirerUnePieceAction
{
    public function __construct(
        private readonly PiecesDuFournisseur $pieces,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  PieceDeposee|null  $remplacante  la pièce qui la remplace ; aucune, elle se retire seulement
     *
     * @throws AuthorizationException
     * @throws LogicException quand le magasin est en lecture seule ou le document validé
     * @throws InvalidArgumentException quand la pièce qui remplace est refusée
     */
    public function handle(User $auteur, PieceDuFournisseur $piece, ?PieceDeposee $remplacante = null): void
    {
        $remplacante = $remplacante === null ? null : $this->pieces->controler($auteur, $piece->document(), [$remplacante])[0];

        DB::transaction(function () use ($auteur, $piece, $remplacante): void {
            $magasin = app(TenantContext::class)->magasin();
            Societe::verrouillerAvantSonJournalDAudit($magasin->societe_id);
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $piece = PieceDuFournisseur::query()->whereKey($piece->getKey())->first()
                ?? throw new LogicException('Cette pièce a déjà été retirée.');
            $document = $piece->document();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'retirerUnePiece', $magasin, $document);

            $this->operateur->pendant($compte, function () use ($compte, $piece, $document, $remplacante): void {
                $this->pieces->retirer($compte, $piece, mettreAJourLaMarque: $remplacante === null);

                if ($remplacante !== null) {
                    $this->pieces->joindre($compte, $document, [$remplacante]);
                }
            });
        });
    }
}
