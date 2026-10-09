<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\Facture;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Un vendeur ferme sa caisse personnelle aussi souvent qu'il le souhaite
 * (voir FermerSaCaisseAction), et la clôture de la journée (voir
 * CloturerCaisseAction) agrège directement les factures, jamais les relevés
 * personnels.
 *
 * Seule la clôture manuelle en tient compte : tant qu'un vendeur a des
 * ventes de la journée qu'il n'a pas fermées de son côté, le bouton
 * « Clôturer une journée » reste désactivé et nomme ces vendeurs (voir
 * ListClotureCaisses), pour que leur relevé personnel (leur outil de
 * réconciliation de fin de service) ne parte pas en retard. La clôture
 * automatique de minuit (voir ClotureAutomatiqueCaisse) ne le consulte
 * jamais : une journée est toujours clôturée, même si personne
 * n'intervient.
 */
class VerificateurCaissesPersonnelles
{
    /**
     * @return Collection<int, User>
     */
    public function vendeursAvecCaisseOuverte(CarbonInterface $date): Collection
    {
        $vendeurIds = Facture::query()
            ->emisLe($date)
            ->whereNotNull('cree_par')
            ->distinct()
            ->pluck('cree_par');

        return $vendeurIds
            ->map(fn (int $vendeurId) => User::find($vendeurId))
            ->filter()
            ->filter(fn (User $vendeur) => $this->aUneVenteNonFermee($vendeur, $date))
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
}
