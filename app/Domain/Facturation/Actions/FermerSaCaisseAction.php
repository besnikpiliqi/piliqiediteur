<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Permet à un vendeur de fermer SA PROPRE caisse (relevé de ses ventes
 * depuis sa dernière clôture personnelle, ou depuis le début de la
 * journée s'il n'en a encore fait aucune) -- jamais la clôture officielle
 * de la journée du magasin (voir CloturerCaisseAction, réservée à
 * cloture_caisse.gerer). Voir le docblock de ClotureCaissePersonnelle pour
 * ce qui distingue les deux.
 *
 * Aucune condition de blocage : contrairement à CloturerCaisseAction, un
 * vendeur peut fermer sa caisse aussi souvent qu'il le souhaite, y compris
 * sans aucune nouvelle vente depuis la précédente fois (relevé à zéro).
 */
class FermerSaCaisseAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function handle(User $vendeur): ClotureCaissePersonnelle
    {
        return DB::transaction(function () use ($vendeur) {
            $derniere = ClotureCaissePersonnelle::query()
                ->where('cree_par', $vendeur->getKey())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            // Curseur par ID de facture, jamais par horodatage : deux
            // fermetures peuvent tomber sur la même seconde (notamment en
            // tests), un ID est lui strictement croissant et sans égalité
            // possible, voir le docblock de la migration.
            $dernierIdInclus = $derniere?->dernier_facture_id ?? 0;
            $depuis = $derniere?->cloturee_a ?? Carbon::now()->startOfDay();

            $factures = Facture::query()
                ->where('cree_par', $vendeur->getKey())
                ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee])
                ->where('id', '>', $dernierIdInclus)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $cloture = new ClotureCaissePersonnelle;
            $cloture->forceFill([
                // Explicite plutôt que de laisser le hook du modèle
                // déduire auth()->id() : $vendeur est le paramètre qui
                // fait foi, pas nécessairement l'utilisateur authentifié
                // courant.
                'cree_par' => $vendeur->getKey(),
                // Avance le curseur jusqu'à la dernière facture trouvée,
                // ou le conserve tel quel (potentiellement null, si
                // jamais aucune facture n'a encore été émise) si rien de
                // nouveau n'a été trouvé -- ne jamais écrire 0, qui
                // violerait la contrainte de clé étrangère.
                'dernier_facture_id' => $factures->isNotEmpty() ? $factures->last()->id : $derniere?->dernier_facture_id,
                'periode_debut' => $depuis,
                'cloturee_a' => now(),
                'nombre_ventes' => $factures->count(),
                ...$this->calculateur->cumuler($factures),
            ])->save();

            return $cloture;
        });
    }
}
