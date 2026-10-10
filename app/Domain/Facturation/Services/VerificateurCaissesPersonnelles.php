<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Un vendeur ferme sa caisse personnelle aussi souvent qu'il le souhaite
 * (voir FermerSaCaisseAction), et la clôture de la journée (voir
 * CloturerCaisseAction) agrège directement les factures, jamais les relevés
 * personnels.
 *
 * Seule la clôture manuelle en tient compte : tant qu'un vendeur a des
 * ventes ou des encaissements de l'une des journées à clôturer qu'il n'a
 * pas fermés de son côté, le bouton « Clôturer la journée » reste
 * désactivé et nomme ces vendeurs (voir ListClotureCaisses), pour que leur
 * relevé personnel (leur outil de réconciliation de fin de service) ne
 * parte pas en retard. Une vente est de la journée de son émission, un
 * encaissement de celle où il est reçu, une contre-passation comprise,
 * qui revient au vendeur du paiement qu'elle corrige (voir
 * Paiement::scopeNonFermesParLeVendeur()). Quiconque enregistre un
 * paiement peut aussi fermer sa caisse, le comptable comme le vendeur
 * (voir ClotureCaissePersonnellePolicy::fermer()). La clôture automatique
 * de minuit (voir ClotureAutomatiqueCaisse) ne le consulte jamais : une
 * journée est toujours clôturée, même si personne n'intervient.
 */
class VerificateurCaissesPersonnelles
{
    /**
     * Les vendeurs dont la caisse personnelle n'est pas fermée pour l'une
     * de ces journées (AAAA-MM-JJ), chacun une fois.
     *
     * @param  Collection<int, string>  $journees
     * @return Collection<int, User>
     */
    public function vendeursAvecCaisseOuverteCesJours(Collection $journees): Collection
    {
        return $journees
            ->flatMap(fn (string $journee): Collection => $this->vendeursAvecCaisseOuverte(CarbonImmutable::parse($journee)))
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    public function vendeursAvecCaisseOuverte(CarbonInterface $date): Collection
    {
        $paiementsDuJour = Paiement::query()->whereDate('recu_le', $date->toDateString());
        $vendeurIds = Facture::query()->emisLe($date)->distinct()->pluck('cree_par')
            ->merge((clone $paiementsDuJour)->whereNull('contre_passe_paiement_id')->distinct()->pluck('cree_par'))
            ->merge(Paiement::query()->whereIn('id', (clone $paiementsDuJour)->select('contre_passe_paiement_id'))->distinct()->pluck('cree_par'))
            ->filter()
            ->unique();

        return User::query()
            ->whereKey($vendeurIds->all())
            ->orderBy('id')
            ->get()
            ->filter(fn (User $vendeur): bool => $this->aUneVenteNonFermee($vendeur, $date) || $this->aUnEncaissementNonFerme($vendeur, $date))
            ->values();
    }

    /**
     * Même règle que FermerSaCaisseAction : toute vente émise compte, même
     * corrigée depuis par un avoir, et une vente émise après une fermeture
     * reste ouverte tant qu'une fermeture ne l'a pas comptée.
     */
    private function aUneVenteNonFermee(User $vendeur, CarbonInterface $date): bool
    {
        return Facture::query()
            ->nonFermeesParLeVendeur($vendeur->id)
            ->emisLe($date)
            ->exists();
    }

    /**
     * Un paiement reçu ce jour-là qui revient au relevé du vendeur (le
     * sien, ou la contre-passation de l'un des siens) et qu'aucune de ses
     * fermetures n'a encore compté.
     */
    private function aUnEncaissementNonFerme(User $vendeur, CarbonInterface $date): bool
    {
        return Paiement::query()
            ->nonFermesParLeVendeur($vendeur->id)
            ->whereDate('recu_le', $date->toDateString())
            ->exists();
    }
}
