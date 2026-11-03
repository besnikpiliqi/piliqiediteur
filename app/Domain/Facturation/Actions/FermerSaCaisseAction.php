<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ferme la caisse personnelle d'un vendeur -- quiconque vend ou encaisse,
 * ou a encore sa caisse ouverte dans le magasin, voir
 * ClotureCaissePersonnellePolicy::fermer() --, jamais la
 * clôture officielle de la journée du magasin (voir CloturerCaisseAction,
 * réservée à cloture_caisse.gerer). Le docblock de ClotureCaissePersonnelle
 * décrit les quatre rubriques du relevé (B18) : les ventes et leur TTC
 * vendu, les encaissements par mode de paiement, le crédit d'avoir utilisé
 * et le reste dû, la période qu'il couvre et ce qui le distingue de la
 * clôture de la journée. Avant de fermer, on voit ce relevé (voir
 * apercu()).
 *
 * Trois façons de fermer (voir ModeFermetureCaisse) :
 * - le vendeur ferme la sienne (handle(), auteur et vendeur confondus,
 *   voir ClotureCaissePersonnellePolicy::fermer()) ;
 * - une personne autorisée à clôturer la journée ferme celle d'un autre
 *   vendeur pour lui (handle(), voir
 *   ClotureCaissePersonnellePolicy::fermerLaCaisseDe()) ;
 * - elle est fermée d'office après la clôture de la journée et à minuit,
 *   par le système (fermerDOffice(), voir
 *   FermerLesCaissesPersonnellesAction).
 * L'auteur d'une fermeture est relu sous le verrou de sa ligne, prise avec
 * celle du vendeur par identifiant croissant (voir LecteurDesDroits) : une
 * autorisation retirée pendant que sa page restait ouverte est vue.
 * Le relevé compte les ventes émises jusqu'à sa borne et les encaissements
 * reçus jusqu'à la journée de sa borne (jusqu_au) : maintenant pour le
 * vendeur et le responsable, la fin de la journée clôturée pour une
 * fermeture d'office, si bien qu'une vente faite après minuit n'entre pas
 * dans la fermeture de la veille.
 *
 * Aucune condition de blocage : contrairement à CloturerCaisseAction, un
 * vendeur peut fermer sa caisse aussi souvent qu'il le souhaite, y compris
 * sans aucune nouvelle vente ni aucun paiement depuis la précédente fois
 * (relevé à zéro). Une fermeture d'office, elle, ne note rien quand il n'y
 * a rien à compter.
 *
 * Deux fermetures de la même caisse au même instant (double clic, deux
 * onglets, le vendeur et la fermeture d'office) se suivent : la seconde
 * attend le verrou que la première a pris sur la fiche du vendeur, puis
 * relit tout après elle. Sous PostgreSQL (READ COMMITTED), chaque lecture
 * qui suit le verrou voit donc la fermeture validée entre-temps : la
 * seconde part d'elle, sans compter une seconde fois ses ventes ou ses
 * paiements ni heurter la clé primaire de
 * clotures_caisse_personnelles_factures ou de
 * clotures_caisse_personnelles_paiements. FOR NO KEY UPDATE, et non FOR
 * UPDATE : les ventes et les paiements que le vendeur enregistre pendant
 * ce temps vérifient leur clé étrangère vers sa fiche sans l'attendre. Un
 * paiement ne revient qu'au relevé d'un seul vendeur (voir
 * Paiement::scopeNonFermesParLeVendeur()) : la fermeture d'un autre
 * vendeur ne le dispute jamais.
 */
class FermerSaCaisseAction
{
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly LecteurDesDroits $lecteur,
        private readonly ActionsUniques $actionsUniques,
    ) {}

    /**
     * L'auteur ferme sa propre caisse, ou celle d'un autre vendeur pour
     * lui s'il est autorisé à clôturer la journée : tout ce qui n'est pas
     * encore compté jusqu'à maintenant. La demande porte un jeton (R3,
     * voir ActionsUniques), cherché sous le verrou des lignes de l'auteur
     * et du vendeur : répétée après une réponse perdue, elle rend le relevé
     * déjà fermé, au lieu d'en fermer un second, à zéro.
     *
     * @param  string|null  $jeton  le jeton de la demande (R3)
     *
     * @throws AuthorizationException quand l'auteur n'est pas, ou plus, autorisé à fermer cette caisse
     */
    public function handle(User $auteur, User $vendeur, ?string $jeton = null): ClotureCaissePersonnelle
    {
        $saPropreCaisse = $auteur->is($vendeur);

        return $this->actionsUniques->executer($jeton, ActionsUniques::FERMER_UNE_CAISSE, $auteur, ['vendeur_id' => $vendeur->getKey()], ActionsUniques::verrouDe($auteur, $vendeur), fn (): ClotureCaissePersonnelle => DB::transaction(function () use ($auteur, $vendeur, $saPropreCaisse): ClotureCaissePersonnelle {
            User::query()->whereKey([$auteur->getKey(), $vendeur->getKey()])->orderBy('id')->lock('for no key update')->get();
            $magasin = app(TenantContext::class)->magasin();
            $compte = $saPropreCaisse
                ? $this->lecteur->exigerSousVerrou($auteur, 'fermer', $magasin, ClotureCaissePersonnelle::class)
                : $this->lecteur->exigerSousVerrou($auteur, 'fermerLaCaisseDe', $magasin, [ClotureCaissePersonnelle::class, $vendeur]);

            return $this->fermer($vendeur, $saPropreCaisse ? ModeFermetureCaisse::Vendeur : ModeFermetureCaisse::Responsable, $compte, CarbonImmutable::now(), memeAZero: true);
        }));
    }

    /**
     * Ferme d'office la caisse du vendeur jusqu'à la borne (voir
     * FermerLesCaissesPersonnellesAction) : null, sans rien noter, quand
     * elle ne compte, une fois relue sous le verrou, aucune vente ni aucun
     * paiement -- le vendeur a pu la fermer entre-temps.
     */
    public function fermerDOffice(User $vendeur, CarbonInterface $jusquAu): ?ClotureCaissePersonnelle
    {
        return $this->fermer($vendeur, ModeFermetureCaisse::Automatique, null, CarbonImmutable::instance($jusquAu), memeAZero: false);
    }

    /**
     * Le relevé que fermerait le vendeur maintenant, sans rien enregistrer :
     * ce que récapitule la fenêtre de fermeture avant confirmation.
     */
    public function apercu(User $vendeur): ClotureCaissePersonnelle
    {
        $maintenant = CarbonImmutable::now();

        return $this->releve($vendeur, ModeFermetureCaisse::Vendeur, $vendeur, $maintenant, $this->ventesDuReleve($vendeur, $maintenant)->get(), $this->paiementsDuReleve($vendeur, $maintenant)->get());
    }

    private function fermer(User $vendeur, ModeFermetureCaisse $mode, ?User $auteur, CarbonImmutable $jusquAu, bool $memeAZero): ?ClotureCaissePersonnelle
    {
        return DB::transaction(function () use ($vendeur, $mode, $auteur, $jusquAu, $memeAZero): ?ClotureCaissePersonnelle {
            User::query()->whereKey($vendeur->getKey())->lock('for no key update')->first();

            $factures = $this->ventesDuReleve($vendeur, $jusquAu)->lockForUpdate()->get();
            $paiements = $this->paiementsDuReleve($vendeur, $jusquAu)->get();

            if (! $memeAZero && $factures->isEmpty() && $paiements->isEmpty()) {
                return null;
            }

            $cloture = $this->releve($vendeur, $mode, $auteur, $jusquAu, $factures, $paiements);
            $cloture->save();
            $cloture->factures()->attach($factures->modelKeys());
            $cloture->paiements()->attach($paiements->modelKeys());

            return $cloture;
        });
    }

    /**
     * Les ventes émises jusqu'à la borne et jamais encore comptées par une
     * fermeture, et non celles d'ID supérieur à la dernière comptée : l'ID
     * est attribué à la création du brouillon, et un brouillon créé avant
     * une vente mais émis après la fermeture serait sinon sauté pour
     * toujours.
     *
     * @return Builder<Facture>
     */
    private function ventesDuReleve(User $vendeur, CarbonInterface $jusquAu): Builder
    {
        return Facture::query()->nonFermeesParLeVendeur($vendeur->getKey())->emisJusquA($jusquAu)->orderBy('id');
    }

    /**
     * Les paiements reçus jusqu'à la journée de la borne, dans le calendrier
     * du magasin actif, et jamais encore comptés : un encaissement est de la
     * journée où il est reçu (voir VerificateurCaissesPersonnelles).
     *
     * @return Builder<Paiement>
     */
    private function paiementsDuReleve(User $vendeur, CarbonInterface $jusquAu): Builder
    {
        return Paiement::query()
            ->nonFermesParLeVendeur($vendeur->getKey())
            ->where('recu_le', '<=', app(TenantContext::class)->magasin()->calendrier()->jourDe($jusquAu))
            ->orderBy('id');
    }

    /**
     * Les quatre rubriques du relevé, non enregistré.
     *
     * @param  Collection<int, Facture>  $factures
     * @param  Collection<int, Paiement>  $paiements
     */
    private function releve(User $vendeur, ModeFermetureCaisse $mode, ?User $auteur, CarbonImmutable $jusquAu, Collection $factures, Collection $paiements): ClotureCaissePersonnelle
    {
        $cloture = new ClotureCaissePersonnelle;

        return $cloture->forceFill([
            'vendeur_id' => $vendeur->getKey(),
            'mode_fermeture' => $mode,
            'fermee_par' => $auteur?->getKey(),
            'periode_debut' => $this->debutDuReleve($vendeur, $jusquAu, $factures, $paiements),
            'jusqu_au' => $jusquAu,
            'cloturee_a' => now(),
            'nombre_ventes' => $factures->count(),
            ...$this->calculateur->cumuler($factures),
            'encaissements' => $this->encaissementsParMode($paiements),
            'credit_avoir' => $this->calculateur->sommer($factures->map(fn (Facture $facture): float => $facture->montantCrediteParAvoir())),
            'reste_du' => $this->calculateur->sommer($factures->map(fn (Facture $facture): float => $facture->montantRestant())),
        ]);
    }

    /**
     * « Depuis » : la borne de la dernière fermeture du vendeur, ou le
     * minuit de la journée de la borne, dans le calendrier du magasin actif,
     * s'il n'en a fait aucune, ou la plus ancienne vente ou le plus ancien paiement compté
     * s'il est antérieur -- y compris ceux d'une journée précédente qu'il
     * n'avait pas fermée.
     *
     * @param  Collection<int, Facture>  $factures
     * @param  Collection<int, Paiement>  $paiements
     */
    private function debutDuReleve(User $vendeur, CarbonImmutable $jusquAu, Collection $factures, Collection $paiements): CarbonInterface
    {
        $derniere = ClotureCaissePersonnelle::query()
            ->where('vendeur_id', $vendeur->getKey())
            ->orderByDesc('id')
            ->first();
        $calendrier = app(TenantContext::class)->magasin()->calendrier();
        $depuis = $derniere?->jusqu_au ?? $calendrier->debutDuJour($calendrier->jourDe($jusquAu));

        foreach ([$factures->min('date_emission'), $paiements->min('created_at')] as $plusAncien) {
            if ($plusAncien !== null && $plusAncien->lt($depuis)) {
                $depuis = $plusAncien;
            }
        }

        return $depuis;
    }

    /**
     * Le montant encaissé par mode de paiement, contre-passations déduites,
     * en chaîne décimale exacte (« 30.00 »), dans l'ordre des modes : seuls
     * les modes des paiements comptés y figurent.
     *
     * @param  Collection<int, Paiement>  $paiements
     * @return array<string, string>
     */
    private function encaissementsParMode(Collection $paiements): array
    {
        return collect(ModePaiement::cases())
            ->mapWithKeys(fn (ModePaiement $mode): array => [$mode->value => $paiements->filter(fn (Paiement $paiement): bool => $paiement->mode_paiement === $mode)])
            ->reject(fn (Collection $paiementsDuMode): bool => $paiementsDuMode->isEmpty())
            ->map(fn (Collection $paiementsDuMode): string => $this->calculateur->enDecimal(
                $paiementsDuMode->sum(fn (Paiement $paiement): int => $this->calculateur->enCentiemes($paiement->montant)),
            ))
            ->all();
    }
}
