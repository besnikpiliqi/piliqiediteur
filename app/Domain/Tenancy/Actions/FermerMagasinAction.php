<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Stock\Services\StockLedgerService;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Events\MagasinFerme;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Notifications\StockDuMagasinCede;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * La société ferme l'un de ses magasins réels : temporairement, pour
 * cessation d'activité ou pour cession.
 *
 * Qui : le propriétaire, ou un administrateur autorisé à gérer les
 * magasins, pour un magasin de sa portée, tant que la société n'est pas en
 * lecture seule (voir MagasinDeLaSocietePolicy::fermerLeMagasin()), en
 * redonnant son mot de passe (voir MotDePasseRedemande) ; la cession, le
 * propriétaire seul (voir MagasinDeLaSocietePolicy::cederLeMagasin()), en
 * retapant le nom du magasin, et seulement une fois la société validée
 * (avant, le magasin n'a aucune activité à céder et se ferme pour
 * cessation). Jamais un responsable ni un expert-comptable. Un magasin
 * suspendu par l'éditeur se ferme aussi, et reste suspendu ; le magasin
 * de démonstration se ferme par FermerLaDemonstrationAction.
 *
 * En une transaction, le registre du magasin verrouillé en tête puis sa
 * ligne : ses journées de caisse en attente se clôturent jusqu'au jour
 * même, au nom du système, comme à minuit (voir
 * CloturerLesJourneesAction::parLeSysteme()), jusqu'à l'instant que donne
 * le verrou du registre (voir RegistreFiscal::verrouiller()) ; une vente
 * qui attendait le registre passe donc avant, dans la dernière clôture,
 * ou bien est refusée ensuite (voir RegistreFiscal). Son statut passe à
 * fermé (JET 270 statut_magasin) ; dans une société validée, sa période
 * d'activité se ferme avec ce motif (voir PeriodesDActivite) et
 * l'événement MagasinFerme part une fois la transaction validée (son
 * abonnement s'arrête, étape 12). Pour une cession, chaque produit qui
 * suit son stock et en a sort tout entier au coût moyen (voir
 * StockLedgerService::sortirPourCession(), dont la période close est la
 * source) ; la valeur hors taxe de ce stock s'inscrit au journal d'audit
 * avec la fermeture, et un produit en stock négatif y est seulement
 * listé, sans mouvement ; le magasin cédé perd aussi l'indication
 * d'établissement principal qu'il portait (voir
 * ChoisirEtablissementPrincipalAction), retrait noté dans la même entrée
 * d'audit. Rien d'autre ne suit : factures, paiements et archives restent
 * où ils sont.
 *
 * Après la transaction : les caisses personnelles restées ouvertes se
 * ferment d'office, au nom du système, « fermée automatiquement » (R5),
 * jusqu'à ce même instant du verrou du registre, et non celui de l'appel :
 * une vente faite pendant que la fermeture contrôlait son auteur ou
 * attendait le registre entre dans le relevé comme dans le ticket Z, et
 * aucune caisse ne reste ouverte ; un échec est signalé sans défaire la
 * fermeture. Pour une cession, le propriétaire reçoit la valeur du stock
 * sorti, à rapprocher du prix de cession. Les affectations de l'équipe
 * restent : l'équipe d'un magasin fermé reste gérable, et
 * l'expert-comptable le lit toujours.
 *
 * Fermé, le magasin passe en lecture seule et ne produit plus de ticket Z
 * (voir JourneesDeCaisse) ; il se rouvre, sauf après une cession (voir
 * RouvrirMagasinAction). Les clôtures mensuelle et de l'exercice et
 * l'archive signées finales d'une fermeture définitive (cessation,
 * cession) viendront avec ces clôtures (étape 3) ; une fermeture
 * temporaire ne clôture que ses journées.
 */
class FermerMagasinAction
{
    public const MESSAGE_DEJA_FERME = 'Ce magasin est déjà fermé.';

    public const MESSAGE_MOTIF = 'Un magasin se ferme temporairement, pour cessation d\'activité ou pour cession.';

    public const MESSAGE_CONFIRMATION = 'Pour céder ce magasin, retapez exactement son nom.';

    public const MESSAGE_CESSION_AVANT_VALIDATION = 'Un magasin ne se cède qu\'une fois la société validée : avant, il n\'a aucune activité à céder, fermez-le pour cessation.';

    private const MOTIFS_DE_LA_SOCIETE = [MotifFermeture::Temporaire, MotifFermeture::Cessation, MotifFermeture::Cession];

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly CloturerLesJourneesAction $cloturerLesJournees,
        private readonly FermerLesCaissesPersonnellesAction $fermerLesCaisses,
        private readonly PeriodesDActivite $periodesDActivite,
        private readonly StockLedgerService $stock,
        private readonly CalculateurLigne $calculateur,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * @param  string|null  $confirmation  pour une cession, le nom du magasin retapé
     *
     * @throws AuthorizationException quand l'auteur ne peut pas fermer, ou céder, ce magasin
     * @throws LogicException quand le mot de passe, le motif, la confirmation ou l'état du magasin ne le permettent pas
     */
    public function handle(User $auteur, Magasin $magasin, MotifFermeture $motif, #[SensitiveParameter] string $motDePasse, ?string $confirmation = null): void
    {
        $societe = $magasin->societe;
        $contexte = app(TenantContext::class);

        if ($contexte->aUneSociete() && $contexte->societe()->getKey() !== $societe->getKey()) {
            throw new AuthorizationException(LecteurDesDroits::REFUS_DANS_LA_SOCIETE);
        }

        $fermeture = $contexte->runAsSociete($societe, function () use ($auteur, $magasin, $societe, $motif, $motDePasse, $confirmation, $contexte): array {
            $auteur = app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, $motif === MotifFermeture::Cession ? 'cederLeMagasin' : 'fermerLeMagasin', $societe, $magasin);
            MotDePasseRedemande::exiger($auteur, $motDePasse);

            if (! in_array($motif, self::MOTIFS_DE_LA_SOCIETE, true)) {
                throw new LogicException(self::MESSAGE_MOTIF);
            }

            if ($motif === MotifFermeture::Cession && ! $societe->estValidee()) {
                throw new LogicException(self::MESSAGE_CESSION_AVANT_VALIDATION);
            }

            if ($motif === MotifFermeture::Cession && trim((string) $confirmation) !== $magasin->nom) {
                throw new LogicException(self::MESSAGE_CONFIRMATION);
            }

            if ($magasin->estFerme()) {
                throw new LogicException(self::MESSAGE_DEJA_FERME);
            }

            return $contexte->runAs($magasin, fn (): array => $this->operateur->pendant($auteur, fn (): array => $this->registre->sousVerrou($magasin, fn (): array => $this->fermer($auteur, $magasin, $motif))));
        });

        try {
            $contexte->runAs($magasin, fn () => $this->fermerLesCaisses->handle($magasin, $fermeture['instant']));
        } catch (Throwable $erreur) {
            report($erreur);
        }

        if ($fermeture['cession'] !== null) {
            $societe->proprietaire()->firstOrFail()->notify(new StockDuMagasinCede($magasin->fresh(), $fermeture['cession']['valeur_ht'], $fermeture['cession']['produits_en_stock_negatif']));
        }
    }

    /**
     * La fermeture elle-même, sous le verrou du registre du magasin actif.
     *
     * @return array{instant: CarbonImmutable, cession: array{valeur_ht: string, produits_en_stock_negatif: list<string>}|null} l'instant du verrou du registre, jusqu'auquel se ferment les caisses personnelles, et, pour une cession, la valeur du stock sorti et les produits restés en stock négatif
     *
     * @throws LogicException
     */
    private function fermer(User $auteur, Magasin $magasin, MotifFermeture $motif): array
    {
        $instant = $this->registre->verrouiller($magasin)->utc;
        $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();

        if ($verrouille->estFerme()) {
            throw new LogicException(self::MESSAGE_DEJA_FERME);
        }

        $principalRetire = $motif === MotifFermeture::Cession && $verrouille->est_etablissement_principal;
        $this->cloturerLesJournees->parLeSysteme($verrouille, $instant);
        $verrouille->forceFill(['statut' => StatutMagasin::Ferme, ...($principalRetire ? ['est_etablissement_principal' => false] : [])])->save();
        $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
            'parametre' => 'statut_magasin',
            'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
            'ancienne_valeur' => StatutMagasin::Actif->value,
            'nouvelle_valeur' => StatutMagasin::Ferme->value,
        ]);

        $periode = $verrouille->societe->estValidee() ? $this->periodesDActivite->fermer($verrouille, $motif, $auteur) : null;
        $cession = $motif === MotifFermeture::Cession && $periode !== null ? $this->sortirLeStock($periode) : null;

        $this->journalAudit->inscrire(ActionAuditee::MagasinFerme, $auteur, $verrouille, [
            'statut' => StatutMagasin::Actif->value,
            ...($principalRetire ? ['est_etablissement_principal' => true] : []),
        ], [
            'statut' => StatutMagasin::Ferme->value,
            'motif' => $motif->value,
            ...($principalRetire ? ['est_etablissement_principal' => false] : []),
            ...($cession === null ? [] : [
                'valeur_stock_sorti_ht' => $cession['valeur_ht'],
                'produits_sortis' => $cession['produits_sortis'],
                'produits_en_stock_negatif' => $cession['produits_en_stock_negatif'],
            ]),
        ], $verrouille);

        if ($periode !== null) {
            MagasinFerme::dispatch($verrouille, $periode);
        }

        return [
            'instant' => $instant,
            'cession' => $cession === null ? null : ['valeur_ht' => $cession['valeur_ht'], 'produits_en_stock_negatif' => $cession['produits_en_stock_negatif']],
        ];
    }

    /**
     * Le stock du magasin cédé sort, produit par produit dans l'ordre de
     * leurs identifiants, archivés compris (ce qu'il en reste en stock
     * existe toujours), chacun au coût moyen ; sa valeur hors taxe est la
     * somme de la valeur de chaque produit, arrondie au centime (voir
     * CalculateurLigne::valeurAuCoutMoyen()), comme la valeur du stock que
     * montre la liste des produits.
     *
     * @return array{valeur_ht: string, produits_sortis: int, produits_en_stock_negatif: list<string>}
     */
    private function sortirLeStock(PeriodeActivite $periode): array
    {
        $produits = Produit::withTrashed()->where('suivi_stock', true)->where('stock_quantite', '<>', 0)->orderBy('id')->get();
        $sorties = $produits
            ->filter(fn (Produit $produit): bool => $produit->stock_quantite > 0)
            ->map(fn (Produit $produit): array => [$produit, $this->stock->sortirPourCession($produit, $periode, 'Cession du magasin')])
            ->filter(fn (array $sortie): bool => $sortie[1] !== null);
        $valeur = $this->calculateur->totaliser($sorties->map(fn (array $sortie): array => $this->valeur(...$sortie)));

        return [
            'valeur_ht' => number_format($valeur['total_ht'], 2, '.', ''),
            'produits_sortis' => $sorties->count(),
            'produits_en_stock_negatif' => $produits
                ->filter(fn (Produit $produit): bool => $produit->stock_quantite < 0)
                ->map(fn (Produit $produit): string => "{$produit->reference} {$produit->nom} ({$produit->stock_quantite})")
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    private function valeur(Produit $produit, MouvementStock $sortie): array
    {
        return $this->calculateur->valeurAuCoutMoyen($sortie->quantite, (float) $sortie->cout_unitaire_ht, (float) $produit->taux_tva);
    }
}
