<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * La procédure de l'éditeur qui annule la cession d'un magasin faite par
 * erreur (editeur:magasin:annuler-cession), procédure grave lancée par
 * deux opérateurs distincts, et seulement si aucune opération n'a suivi
 * la cession dans le magasin : aucun document émis, aucun paiement, aucun
 * autre mouvement de stock que les sorties de la cession.
 *
 * Sous le verrou du registre du magasin, puis de sa ligne : chaque
 * produit que la cession avait fait sortir rentre en stock, la même
 * quantité au même coût moyen que sa sortie (voir
 * StockLedgerService::rentrerApresAnnulationDeCession()) ; le magasin
 * retrouve le motif de fermeture qu'il avait avant sa cession (temporaire
 * ou cessation, lu à l'entrée d'audit de la cession d'un magasin déjà
 * fermé), ou, cédé alors qu'il était ouvert, devient fermé temporairement
 * : la société le rouvre elle-même, après le contrôle de son
 * établissement au répertoire Sirene, comme toute réouverture (voir
 * RouvrirMagasinAction), sa période d'activité close à la cession restant
 * ce qu'elle est (voir PeriodeActivite). Le changement de motif s'inscrit
 * à son journal des événements (JET 270 motif_fermeture), et l'annulation
 * au journal d'audit, avec la valeur hors taxe du stock rentré et
 * l'intervention de l'éditeur. L'indication d'établissement principal
 * retirée à la cession ne revient pas : la société la redonne si elle le
 * veut. Rien d'autre ne bouge : factures, paiements et archives restent
 * où ils sont.
 */
class AnnulerCessionAction
{
    public const MESSAGE_DEUX_OPERATEURS = 'L\'annulation d\'une cession exige deux opérateurs distincts de l\'éditeur.';

    public const MESSAGE_PAS_CEDE = 'Ce magasin n\'a pas été cédé.';

    public const MESSAGE_OPERATION_POSTERIEURE = 'Une opération a suivi la cession dans ce magasin (un document, un paiement ou un mouvement de stock) : la cession ne s\'annule plus.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly StockLedgerService $stock,
        private readonly CalculateurLigne $calculateur,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @return MotifFermeture le motif de fermeture que retrouve le magasin
     *
     * @throws LogicException quand la cession ne s'annule pas
     */
    public function handle(Magasin $magasin, InterventionDeLEditeur $editeur): MotifFermeture
    {
        if (count(array_unique($editeur->operateurs)) < 2) {
            throw new LogicException(self::MESSAGE_DEUX_OPERATEURS);
        }

        return app(TenantContext::class)->runAsSociete($magasin->societe, fn (): MotifFermeture => app(TenantContext::class)->runAs($magasin, fn (): MotifFermeture => $this->operateur->pendantLeSysteme(fn (): MotifFermeture => $this->registre->sousVerrou($magasin, function () use ($magasin, $editeur): MotifFermeture {
            $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();

            if (! $verrouille->aEteCede()) {
                throw new LogicException(self::MESSAGE_PAS_CEDE);
            }

            $cession = $this->entreeDeLaCession($verrouille);
            $instant = $cession->created_at;
            $sorties = MouvementStock::query()
                ->where('type', TypeMouvementStock::SortieCession->value)
                ->where('source_type', $verrouille->getMorphClass())
                ->where('source_id', $verrouille->getKey())
                ->orderBy('id')
                ->get();

            $this->exigerAucuneOperationApres($instant, $sorties->modelKeys());
            $motif = $cession->action === ActionAuditee::MagasinCede
                ? (MotifFermeture::tryFrom((string) ($cession->anciennes_valeurs['motif'] ?? '')) ?? MotifFermeture::Temporaire)
                : MotifFermeture::Temporaire;

            $rentrees = $sorties->map(function (MouvementStock $sortie) use ($verrouille): array {
                $produit = Produit::withTrashed()->findOrFail($sortie->produit_id);
                $this->stock->rentrerApresAnnulationDeCession($produit, $sortie, $verrouille, 'Annulation de la cession du magasin');

                return $this->calculateur->valeurAuCoutMoyen($sortie->quantite, (float) $sortie->cout_unitaire_ht, (float) $produit->taux_tva);
            });
            $valeur = $this->calculateur->totaliser($rentrees);

            $verrouille->annulerSaCessionParLaProcedure($motif);
            $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
                'parametre' => 'motif_fermeture',
                'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
                'ancienne_valeur' => MotifFermeture::Cession->value,
                'nouvelle_valeur' => $motif->value,
            ]);
            $this->journalAudit->inscrire(ActionAuditee::CessionAnnulee, null, $verrouille, ['motif' => MotifFermeture::Cession->value], [
                'motif' => $motif->value,
                'produits_rentres' => $sorties->count(),
                'valeur_stock_rentre_ht' => number_format($valeur['total_ht'], 2, '.', ''),
            ], $verrouille, $editeur);

            return $motif;
        }))));
    }

    /**
     * L'entrée d'audit de la cession du magasin : la fermeture pour cession
     * d'un magasin ouvert, ou la cession d'un magasin déjà fermé, qui garde
     * son motif d'avant ; la plus récente, une cession annulée pouvant
     * avoir été suivie d'une autre.
     *
     * @throws LogicException
     */
    private function entreeDeLaCession(Magasin $magasin): EntreeAudit
    {
        return EntreeAudit::query()
            ->where('magasin_id', $magasin->getKey())
            ->whereIn('action', [ActionAuditee::MagasinFerme->value, ActionAuditee::MagasinCede->value])
            ->orderByDesc('id')
            ->get()
            ->first(fn (EntreeAudit $entree): bool => ($entree->nouvelles_valeurs['motif'] ?? null) === MotifFermeture::Cession->value)
            ?? throw new LogicException(self::MESSAGE_PAS_CEDE);
    }

    /**
     * Aucune opération après la cession dans le magasin actif : aucun
     * document émis, aucun paiement enregistré, aucun autre mouvement de
     * stock que ses sorties.
     *
     * @param  list<int>  $sorties  les sorties de la cession
     *
     * @throws LogicException
     */
    private function exigerAucuneOperationApres(CarbonImmutable $instant, array $sorties): void
    {
        $operations = Facture::query()->where('date_emission', '>', $instant)->exists()
            || Avoir::query()->where('date_emission', '>', $instant)->exists()
            || Paiement::query()->where('created_at', '>', $instant)->exists()
            || MouvementStock::query()->whereKeyNot($sorties)->where('created_at', '>', $instant)->exists();

        if ($operations) {
            throw new LogicException(self::MESSAGE_OPERATION_POSTERIEURE);
        }
    }
}
