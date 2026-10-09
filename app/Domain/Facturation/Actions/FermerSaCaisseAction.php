<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Permet à un vendeur de fermer SA PROPRE caisse -- jamais la clôture
 * officielle de la journée du magasin (voir CloturerCaisseAction, réservée
 * à cloture_caisse.gerer). Le docblock de ClotureCaissePersonnelle décrit
 * les ventes que compte le relevé, la période qu'il couvre et ce qui le
 * distingue de la clôture de la journée.
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

            // Les ventes jamais encore comptées par une fermeture, et non
            // celles d'ID supérieur à la dernière comptée : l'ID est
            // attribué à la création du brouillon, et un brouillon créé
            // avant une vente mais émis après la fermeture serait sinon
            // sauté pour toujours.
            $factures = Facture::query()
                ->nonFermeesParLeVendeur($vendeur->getKey())
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            // « Depuis » couvre toutes les ventes du relevé, y compris celles
            // d'une journée précédente que le vendeur n'avait pas fermée.
            $depuis = $derniere?->cloturee_a ?? Carbon::now()->startOfDay();
            $plusAncienneVente = $factures->min('date_emission');

            if ($plusAncienneVente !== null && $plusAncienneVente->lt($depuis)) {
                $depuis = $plusAncienneVente;
            }

            $cloture = new ClotureCaissePersonnelle;
            $cloture->forceFill([
                // Explicite plutôt que de laisser le hook du modèle
                // déduire auth()->id() : $vendeur est le paramètre qui
                // fait foi, pas nécessairement l'utilisateur authentifié
                // courant.
                'cree_par' => $vendeur->getKey(),
                'periode_debut' => $depuis,
                'cloturee_a' => now(),
                'nombre_ventes' => $factures->count(),
                ...$this->calculateur->cumuler($factures),
            ])->save();

            $cloture->factures()->attach($factures->modelKeys());

            return $cloture;
        });
    }
}
