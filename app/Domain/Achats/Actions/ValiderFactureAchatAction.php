<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Events\FactureAchatValidee;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreFactureAction côté vente : la facture d'achat ne
 * reçoit son numéro interne qu'à la validation, moment où le stock est
 * incrémenté (voir FactureAchatValidee/IncrementerStockApresValidationFactureAchat).
 * Chacun de ses produits existants doit encore être au catalogue : une
 * réception ne se perd jamais en silence. Son fournisseur aussi : archivé
 * depuis la saisie, il ne deviendrait pas le fournisseur de ses produits
 * (voir Produit::rattacherAuFournisseur()) sans être restauré. Ses
 * nouveaux produits naissent alors, avant son numéro (voir
 * ConstructeurLigneAchat::creerLesNouveauxProduits()) : un refus (prix de
 * vente manquant, référence prise entre-temps) annule toute la validation,
 * sans consommer ni numéro de facture d'achat ni référence de produit. Un
 * magasin suspendu ou fermé ne valide plus d'achat (voir
 * Magasin::verifierQuIlEstOuvert()).
 *
 * Son auteur est relu sous le verrou de sa ligne, avant la facture d'achat
 * et ses produits (voir LecteurDesDroits) : il doit pouvoir la valider ;
 * un droit retiré pendant que son écran restait ouvert est vu. Les
 * mouvements de stock de la réception notent son nom.
 */
class ValiderFactureAchatAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly ConstructeurLigneAchat $constructeurLignes,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, FactureAchat $factureAchat): FactureAchat
    {
        return DB::transaction(function () use ($auteur, $factureAchat) {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->findOrFail($magasin->getKey())->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'valider', $magasin, $factureAchat);

            return $this->operateur->pendant($compte, fn (): FactureAchat => $this->valider($factureAchat));
        });
    }

    /**
     * @throws LogicException
     */
    private function valider(FactureAchat $factureAchat): FactureAchat
    {
        $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->firstOrFail();

        if (! $factureAchat->statut->peutTransitionnerVers(StatutFactureAchat::Validee)) {
            throw new LogicException("Impossible de valider une facture d'achat au statut [{$factureAchat->statut->value}].");
        }

        if ($factureAchat->fournisseur->trashed()) {
            throw new LogicException("Le fournisseur « {$factureAchat->fournisseur->nom} » a été archivé : restaurez-le depuis la liste des fournisseurs avant de valider cette facture d'achat.");
        }

        if (($produitArchive = $factureAchat->produitArchive()) !== null) {
            throw new LogicException("Le produit « {$produitArchive->nom} » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture, avant de la valider.");
        }

        $this->constructeurLignes->creerLesNouveauxProduits($factureAchat);

        $numero = $this->numerotation->suivant($factureAchat->magasin, TypeDocument::FactureAchat, now()->format('Y'));

        $factureAchat->forceFill([
            'numero' => $numero,
            'statut' => StatutFactureAchat::Validee,
            'date_validation' => now(),
        ])->save();

        // Synchrone et dans la même transaction, voir le commentaire de
        // FactureAchatValidee ; chaque ligne y arrive avec son produit,
        // nouveau compris.
        FactureAchatValidee::dispatch($factureAchat->load('lignes'));

        return $factureAchat;
    }
}
