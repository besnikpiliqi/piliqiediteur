<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Un vendeur peut fermer sa caisse personnelle aussi souvent qu'il le
 * souhaite, ou jamais (voir FermerSaCaisseAction) : rien ne l'y oblige, et
 * la clôture officielle de la journée (voir CloturerCaisseAction) agrège
 * directement les factures, jamais les relevés personnels -- un vendeur
 * qui oublie de fermer sa caisse ne bloque donc jamais la clôture.
 *
 * Avant de clôturer manuellement, le propriétaire doit néanmoins pouvoir
 * voir si un vendeur a encore des ventes de cette journée qu'il n'a pas
 * fermées de son côté, pour ne pas que son relevé personnel (son outil de
 * réconciliation de fin de service) parte en retard. Volontairement
 * absent de la commande automatique de minuit (voir
 * ClotureAutomatiqueCaisse) : elle doit garantir qu'une journée est
 * toujours clôturée même si personne n'intervient, jamais bloquer dessus.
 */
class VerificateurCaissesPersonnelles
{
    /**
     * @return Collection<int, User>
     */
    public function vendeursAvecCaisseOuverte(CarbonInterface $date): Collection
    {
        $vendeurIds = Facture::query()
            ->whereDate('date_emission', $date)
            ->whereNotNull('cree_par')
            ->distinct()
            ->pluck('cree_par');

        return $vendeurIds
            ->map(fn (int $vendeurId) => User::find($vendeurId))
            ->filter()
            ->filter(fn (User $vendeur) => $this->aUneVenteNonFermee($vendeur, $date))
            ->values();
    }

    private function aUneVenteNonFermee(User $vendeur, CarbonInterface $date): bool
    {
        $derniereFermeture = ClotureCaissePersonnelle::query()
            ->where('cree_par', $vendeur->id)
            ->orderByDesc('id')
            ->first();

        $dernierIdInclus = $derniereFermeture?->dernier_facture_id ?? 0;

        return Facture::query()
            ->where('cree_par', $vendeur->id)
            ->whereDate('date_emission', $date)
            ->where('id', '>', $dernierIdInclus)
            ->exists();
    }
}
