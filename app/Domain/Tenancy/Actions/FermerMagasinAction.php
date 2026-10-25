<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
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
 * La société ferme l'un de ses magasins réels, temporairement, pour
 * cessation d'activité ou pour cession, ou cède un magasin qu'elle a déjà
 * fermé, temporairement ou pour cessation.
 *
 * Qui : le propriétaire, ou un administrateur autorisé à gérer les
 * magasins, pour un magasin de sa portée, tant que la société n'est pas en
 * lecture seule (voir MagasinDeLaSocietePolicy::fermerLeMagasin()), en
 * redonnant son mot de passe (voir MotDePasseRedemande) ; la cession, le
 * propriétaire seul (voir MagasinDeLaSocietePolicy::cederLeMagasin()), en
 * retapant le nom du magasin, et seulement une fois la société validée
 * (avant, le magasin n'a aucune activité à céder et se ferme pour
 * cessation). Jamais un responsable ni un expert-comptable. Un magasin
 * suspendu par l'éditeur se ferme et se cède aussi, et reste suspendu ;
 * le magasin de démonstration se ferme par FermerLaDemonstrationAction.
 *
 * En une transaction, le registre du magasin verrouillé en tête puis sa
 * ligne, relue pour refuser un magasin fermé ou cédé entre-temps :
 *
 * - Un magasin ouvert se ferme : ses journées de caisse en attente se
 *   clôturent jusqu'au jour même, au nom du système, comme à minuit (voir
 *   CloturerLesJourneesAction::parLeSysteme()), jusqu'à l'instant que
 *   donne le verrou du registre (voir RegistreFiscal::verrouiller()) ; une
 *   vente qui attendait le registre passe donc avant, dans la dernière
 *   clôture, ou bien est refusée ensuite (voir RegistreFiscal). Son statut
 *   passe à fermé, avec son motif (JET 270 statut_magasin, motif compris) ;
 *   dans une société validée, sa période d'activité se ferme avec ce motif
 *   (voir PeriodesDActivite) et l'événement MagasinFerme part une fois la
 *   transaction validée (son abonnement s'arrête, étape 12) ; la fermeture
 *   s'inscrit au journal d'audit (MagasinFerme).
 * - Un magasin déjà fermé, temporairement ou pour cessation, se cède sans
 *   être rouvert ni recontrôlé au répertoire Sirene : seul son motif de
 *   fermeture devient la cession (JET 270 motif_fermeture, de l'ancien
 *   motif à la cession ; journal d'audit MagasinCede). Sa période
 *   d'activité close ne se modifie jamais (voir PeriodeActivite) : elle
 *   garde le motif de sa fin, la date de la cession se lisant au journal
 *   des événements et au journal d'audit. Ses journées sont déjà
 *   clôturées, ses caisses personnelles déjà fermées, et son abonnement
 *   déjà arrêté, à sa fermeture.
 *
 * Toute cession, d'un magasin ouvert ou fermé, sort son stock : chaque
 * produit qui suit son stock et en a sort tout entier au coût moyen (voir
 * StockLedgerService::sortirPourCession(), dont le magasin cédé est la
 * source) ; la valeur hors taxe de ce stock s'inscrit au journal d'audit
 * avec la cession, et un produit en stock négatif y est seulement listé,
 * sans mouvement ; le magasin cédé perd aussi l'indication
 * d'établissement principal qu'il portait (voir
 * ChoisirEtablissementPrincipalAction), retrait noté dans la même entrée
 * d'audit. Rien d'autre ne suit : factures, paiements et archives restent
 * où ils sont. Un magasin cédé ne se ferme, ne se cède et ne se rouvre
 * plus (voir Magasin::aEteCede() et RouvrirMagasinAction).
 *
 * Après la transaction : les caisses personnelles d'un magasin qui vient
 * de fermer, restées ouvertes, se ferment d'office, au nom du système,
 * « fermée automatiquement » (R5), jusqu'à ce même instant du verrou du
 * registre, et non celui de l'appel : une vente faite pendant que la
 * fermeture contrôlait son auteur ou attendait le registre entre dans le
 * relevé comme dans le ticket Z, et aucune caisse ne reste ouverte ; un
 * échec est signalé sans défaire la fermeture. Pour une cession, le
 * propriétaire reçoit la valeur du stock sorti, à rapprocher du prix de
 * cession. Les affectations de l'équipe restent : l'équipe d'un magasin
 * fermé reste gérable, et l'expert-comptable le lit toujours.
 *
 * Fermé, le magasin passe en lecture seule et ne produit plus de ticket Z
 * (voir JourneesDeCaisse) ; il se rouvre, sauf après une cession (voir
 * RouvrirMagasinAction). Les clôtures mensuelle et de l'exercice et
 * l'archive signées finales d'une fermeture définitive (cessation,
 * cession, y compris celle d'un magasin fermé temporairement) viendront
 * avec ces clôtures (étape 3) ; une fermeture temporaire ne clôture que
 * ses journées.
 */
class FermerMagasinAction
{
    public const MESSAGE_DEJA_FERME = 'Ce magasin est déjà fermé.';

    public const MESSAGE_DEJA_CEDE = 'Ce magasin a déjà été cédé.';

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

        $resultat = $contexte->runAsSociete($societe, function () use ($auteur, $magasin, $societe, $motif, $motDePasse, $confirmation, $contexte): array {
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

            self::verifierQuIlSeFerme($magasin, $motif);

            return $contexte->runAs($magasin, fn (): array => $this->operateur->pendant($auteur, fn (): array => $this->registre->sousVerrou($magasin, fn (): array => $this->fermer($auteur, $magasin, $motif))));
        });

        if ($resultat['fermeture'] !== null) {
            try {
                $contexte->runAs($magasin, fn () => $this->fermerLesCaisses->handle($magasin, $resultat['fermeture']));
            } catch (Throwable $erreur) {
                report($erreur);
            }
        }

        if ($resultat['cession'] !== null) {
            $societe->proprietaire()->firstOrFail()->notify(new StockDuMagasinCede($magasin->fresh(), $resultat['cession']['valeur_ht'], $resultat['cession']['produits_en_stock_negatif']));
        }
    }

    /**
     * Sous le verrou du registre du magasin actif, sa ligne verrouillée :
     * la fermeture d'un magasin ouvert, ou la cession d'un magasin fermé.
     *
     * @return array{fermeture: CarbonImmutable|null, cession: array{valeur_ht: string, produits_en_stock_negatif: list<string>}|null} l'instant du verrou du registre, jusqu'auquel se ferment les caisses personnelles du magasin qui vient de fermer (null pour un magasin qui l'était déjà), et, pour une cession, la valeur du stock sorti et les produits restés en stock négatif
     *
     * @throws LogicException
     */
    private function fermer(User $auteur, Magasin $magasin, MotifFermeture $motif): array
    {
        $instant = $this->registre->verrouiller($magasin)->utc;
        $verrouille = Magasin::query()->whereKey($magasin->getKey())->lockForUpdate()->firstOrFail();
        self::verifierQuIlSeFerme($verrouille, $motif);

        if ($verrouille->estFerme()) {
            return ['fermeture' => null, 'cession' => self::resume($this->cederUnMagasinFerme($auteur, $verrouille))];
        }

        $principalRetire = $motif === MotifFermeture::Cession && $verrouille->est_etablissement_principal;
        $this->cloturerLesJournees->parLeSysteme($verrouille, Calendrier::deParis()->jourDe($instant));
        $verrouille->forceFill([
            'statut' => StatutMagasin::Ferme,
            'motif_fermeture' => $motif,
            ...($principalRetire ? ['est_etablissement_principal' => false] : []),
        ])->save();
        $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
            'parametre' => 'statut_magasin',
            'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
            'ancienne_valeur' => StatutMagasin::Actif->value,
            'nouvelle_valeur' => StatutMagasin::Ferme->value,
            'motif' => $motif->value,
        ]);

        $periode = $verrouille->societe->estValidee() ? $this->periodesDActivite->fermer($verrouille, $motif, $auteur) : null;
        $cession = $motif === MotifFermeture::Cession ? $this->sortirLeStock($verrouille) : null;

        $this->journalAudit->inscrire(ActionAuditee::MagasinFerme, $auteur, $verrouille, [
            'statut' => StatutMagasin::Actif->value,
            ...($principalRetire ? ['est_etablissement_principal' => true] : []),
        ], [
            'statut' => StatutMagasin::Ferme->value,
            'motif' => $motif->value,
            ...($principalRetire ? ['est_etablissement_principal' => false] : []),
            ...($cession ?? []),
        ], $verrouille);

        if ($periode !== null) {
            MagasinFerme::dispatch($verrouille, $periode);
        }

        return ['fermeture' => $instant, 'cession' => $cession === null ? null : self::resume($cession)];
    }

    /**
     * La cession d'un magasin déjà fermé, temporairement ou pour cessation,
     * sa ligne verrouillée : son motif de fermeture devient la cession, au
     * journal des événements comme au journal d'audit, son stock sort et il
     * perd l'indication d'établissement principal ; sa période d'activité
     * close reste telle qu'elle est.
     *
     * @return array{valeur_stock_sorti_ht: string, produits_sortis: int, produits_en_stock_negatif: list<string>}
     */
    private function cederUnMagasinFerme(User $auteur, Magasin $verrouille): array
    {
        $motifPrecedent = $verrouille->motif_fermeture;
        $principalRetire = (bool) $verrouille->est_etablissement_principal;
        $verrouille->forceFill([
            'motif_fermeture' => MotifFermeture::Cession,
            ...($principalRetire ? ['est_etablissement_principal' => false] : []),
        ])->save();
        $this->registre->journaliser(CodeEvenement::ParametreDeConformite, [
            'parametre' => 'motif_fermeture',
            'magasin' => ['id' => $verrouille->getKey(), 'nom' => $verrouille->nom],
            'ancienne_valeur' => $motifPrecedent?->value,
            'nouvelle_valeur' => MotifFermeture::Cession->value,
        ]);

        $cession = $this->sortirLeStock($verrouille);

        $this->journalAudit->inscrire(ActionAuditee::MagasinCede, $auteur, $verrouille, [
            'motif' => $motifPrecedent?->value,
            ...($principalRetire ? ['est_etablissement_principal' => true] : []),
        ], [
            'motif' => MotifFermeture::Cession->value,
            ...($principalRetire ? ['est_etablissement_principal' => false] : []),
            ...$cession,
        ], $verrouille);

        return $cession;
    }

    /**
     * Le magasin se ferme-t-il pour ce motif ? Jamais une fois cédé ; déjà
     * fermé, seulement pour être cédé.
     *
     * @throws LogicException
     */
    private static function verifierQuIlSeFerme(Magasin $magasin, MotifFermeture $motif): void
    {
        if ($magasin->aEteCede()) {
            throw new LogicException(self::MESSAGE_DEJA_CEDE);
        }

        if ($magasin->estFerme() && $motif !== MotifFermeture::Cession) {
            throw new LogicException(self::MESSAGE_DEJA_FERME);
        }
    }

    /**
     * Le stock du magasin cédé sort, produit par produit dans l'ordre de
     * leurs identifiants, archivés compris (ce qu'il en reste en stock
     * existe toujours), chacun au coût moyen, le magasin étant la source
     * de chaque sortie ; sa valeur hors taxe est la somme de la valeur de
     * chaque produit, arrondie au centime (voir
     * CalculateurLigne::valeurAuCoutMoyen()), comme la valeur du stock que
     * montre la liste des produits.
     *
     * @return array{valeur_stock_sorti_ht: string, produits_sortis: int, produits_en_stock_negatif: list<string>}
     */
    private function sortirLeStock(Magasin $magasin): array
    {
        $produits = Produit::withTrashed()->where('suivi_stock', true)->where('stock_quantite', '<>', 0)->orderBy('id')->get();
        $sorties = $produits
            ->filter(fn (Produit $produit): bool => $produit->stock_quantite > 0)
            ->map(fn (Produit $produit): array => [$produit, $this->stock->sortirPourCession($produit, $magasin, 'Cession du magasin')])
            ->filter(fn (array $sortie): bool => $sortie[1] !== null);
        $valeur = $this->calculateur->totaliser($sorties->map(fn (array $sortie): array => $this->valeur(...$sortie)));

        return [
            'valeur_stock_sorti_ht' => number_format($valeur['total_ht'], 2, '.', ''),
            'produits_sortis' => $sorties->count(),
            'produits_en_stock_negatif' => $produits
                ->filter(fn (Produit $produit): bool => $produit->stock_quantite < 0)
                ->map(fn (Produit $produit): string => "{$produit->reference} {$produit->nom} ({$produit->stock_quantite})")
                ->values()
                ->all(),
        ];
    }

    /**
     * Ce que le propriétaire reçoit d'une cession : la valeur hors taxe du
     * stock sorti et les produits restés en stock négatif.
     *
     * @param  array{valeur_stock_sorti_ht: string, produits_sortis: int, produits_en_stock_negatif: list<string>}  $cession
     * @return array{valeur_ht: string, produits_en_stock_negatif: list<string>}
     */
    private static function resume(array $cession): array
    {
        return ['valeur_ht' => $cession['valeur_stock_sorti_ht'], 'produits_en_stock_negatif' => $cession['produits_en_stock_negatif']];
    }

    /**
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    private function valeur(Produit $produit, MouvementStock $sortie): array
    {
        return $this->calculateur->valeurAuCoutMoyen($sortie->quantite, (float) $sortie->cout_unitaire_ht, (float) $produit->taux_tva);
    }
}
