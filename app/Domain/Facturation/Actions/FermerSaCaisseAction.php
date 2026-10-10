<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Permet à un vendeur de fermer SA PROPRE caisse -- jamais la clôture
 * officielle de la journée du magasin (voir CloturerCaisseAction, réservée
 * à cloture_caisse.gerer). Le docblock de ClotureCaissePersonnelle décrit
 * les quatre rubriques du relevé (B18) : les ventes et leur TTC vendu, les
 * encaissements par mode de paiement, le crédit d'avoir utilisé et le
 * reste dû, la période qu'il couvre et ce qui le distingue de la clôture
 * de la journée. Avant de fermer, le vendeur voit ce relevé (voir
 * apercu()).
 *
 * Aucune condition de blocage : contrairement à CloturerCaisseAction, un
 * vendeur peut fermer sa caisse aussi souvent qu'il le souhaite, y compris
 * sans aucune nouvelle vente ni aucun paiement depuis la précédente fois
 * (relevé à zéro).
 *
 * Deux fermetures du même vendeur au même instant (double clic, deux
 * onglets) se suivent : la seconde attend le verrou que la première a
 * pris sur la fiche du vendeur, puis relit tout après elle. Sous
 * PostgreSQL (READ COMMITTED), chaque lecture qui suit le verrou voit
 * donc la fermeture validée entre-temps : la seconde part d'elle, sans
 * compter une seconde fois ses ventes ou ses paiements ni heurter la clé
 * primaire de clotures_caisse_personnelles_factures ou de
 * clotures_caisse_personnelles_paiements. FOR NO KEY UPDATE, et non FOR
 * UPDATE : les ventes et les paiements que le vendeur enregistre pendant
 * ce temps vérifient leur clé étrangère vers sa fiche sans l'attendre. Un
 * paiement ne revient qu'au relevé d'un seul vendeur (voir
 * Paiement::scopeNonFermesParLeVendeur()) : la fermeture d'un autre
 * vendeur ne le dispute jamais.
 */
class FermerSaCaisseAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function handle(User $vendeur): ClotureCaissePersonnelle
    {
        return DB::transaction(function () use ($vendeur) {
            User::query()->whereKey($vendeur->getKey())->lock('for no key update')->first();

            $factures = $this->ventesDuReleve($vendeur)->lockForUpdate()->get();
            $paiements = $this->paiementsDuReleve($vendeur)->get();

            $cloture = $this->releve($vendeur, $factures, $paiements);
            $cloture->save();
            $cloture->factures()->attach($factures->modelKeys());
            $cloture->paiements()->attach($paiements->modelKeys());

            return $cloture;
        });
    }

    /**
     * Le relevé que fermerait le vendeur maintenant, sans rien enregistrer :
     * ce que récapitule la fenêtre « Fermer ma caisse » avant confirmation.
     */
    public function apercu(User $vendeur): ClotureCaissePersonnelle
    {
        return $this->releve($vendeur, $this->ventesDuReleve($vendeur)->get(), $this->paiementsDuReleve($vendeur)->get());
    }

    /**
     * Les ventes jamais encore comptées par une fermeture, et non celles
     * d'ID supérieur à la dernière comptée : l'ID est attribué à la
     * création du brouillon, et un brouillon créé avant une vente mais émis
     * après la fermeture serait sinon sauté pour toujours.
     *
     * @return Builder<Facture>
     */
    private function ventesDuReleve(User $vendeur): Builder
    {
        return Facture::query()->nonFermeesParLeVendeur($vendeur->getKey())->orderBy('id');
    }

    /**
     * @return Builder<Paiement>
     */
    private function paiementsDuReleve(User $vendeur): Builder
    {
        return Paiement::query()->nonFermesParLeVendeur($vendeur->getKey())->orderBy('id');
    }

    /**
     * Les quatre rubriques du relevé, non enregistré.
     *
     * @param  Collection<int, Facture>  $factures
     * @param  Collection<int, Paiement>  $paiements
     */
    private function releve(User $vendeur, Collection $factures, Collection $paiements): ClotureCaissePersonnelle
    {
        $cloture = new ClotureCaissePersonnelle;

        return $cloture->forceFill([
            // Explicite plutôt que de laisser le hook du modèle déduire
            // auth()->id() : $vendeur est le paramètre qui fait foi, pas
            // nécessairement l'utilisateur authentifié courant.
            'cree_par' => $vendeur->getKey(),
            'periode_debut' => $this->debutDuReleve($vendeur, $factures, $paiements),
            'cloturee_a' => now(),
            'nombre_ventes' => $factures->count(),
            ...$this->calculateur->cumuler($factures),
            'encaissements' => $this->encaissementsParMode($paiements),
            'credit_avoir' => $this->calculateur->sommer($factures->map(fn (Facture $facture): float => $facture->montantCrediteParAvoir())),
            'reste_du' => $this->calculateur->sommer($factures->map(fn (Facture $facture): float => $facture->montantRestant())),
        ]);
    }

    /**
     * « Depuis » : la dernière fermeture du vendeur, ou le début de la
     * journée s'il n'en a fait aucune, ou la plus ancienne vente ou le plus
     * ancien paiement compté s'il est antérieur -- y compris ceux d'une
     * journée précédente qu'il n'avait pas fermée.
     *
     * @param  Collection<int, Facture>  $factures
     * @param  Collection<int, Paiement>  $paiements
     */
    private function debutDuReleve(User $vendeur, Collection $factures, Collection $paiements): CarbonInterface
    {
        $derniere = ClotureCaissePersonnelle::query()
            ->where('cree_par', $vendeur->getKey())
            ->orderByDesc('id')
            ->first();
        $depuis = $derniere?->cloturee_a ?? Carbon::now()->startOfDay();

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
