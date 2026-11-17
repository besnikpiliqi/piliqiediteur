<?php

namespace App\Domain\Demonstration\Listeners;

use App\Domain\Demonstration\Models\CompteDuBandeau;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\RoleAuBandeau;
use App\Domain\Demonstration\Visites\SocieteDeLaCopie;
use App\Domain\Tenancy\Events\CompteCree;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\DB;

/**
 * Un compte créé dans une copie entre au bandeau « Vous êtes : » (lot
 * DEMO-COPIE, C.8 ; précision de l'éditeur du 16/11/2026 : on crée des
 * membres comme en vrai), à la fin de son groupe : « La société » pour un
 * administrateur, son magasin pour un membre, « Le comptable » pour un
 * expert-comptable de la société, « Le cabinet » pour un collaborateur.
 * Seulement un compte de la société de la copie, une fois sa création
 * enregistrée (son rôle dans son magasin est alors connu). Chargé
 * seulement sur le site de démonstration (voir
 * SiteDeDemonstrationServiceProvider).
 */
final class AjouterLeCompteAuBandeau implements ShouldHandleEventsAfterCommit
{
    public function handle(CompteCree $evenement): void
    {
        if (! SiteDePiliqi::estLaDemonstration()) {
            return;
        }

        $compte = $evenement->compte;
        $societe = SocieteDeLaCopie::trouver();

        if ($societe === null || $compte->societe_id !== $societe->getKey()) {
            return;
        }

        $role = RoleAuBandeau::de($compte->load('affectations.magasin'), $societe->estUnCabinet());

        app(TenantContext::class)->runAsSociete($societe, fn () => DB::transaction(function () use ($compte, $role): void {
            $lignes = CompteDuBandeau::query()->lockForUpdate()->orderBy('ordre')->get(['id', 'groupe', 'ordre', 'user_id']);

            if ($lignes->contains('user_id', $compte->getKey())) {
                return;
            }

            $dernierDuGroupe = $lignes->where('groupe', $role->groupe)->max('ordre');
            $ordre = is_int($dernierDuGroupe) ? $dernierDuGroupe + 1 : (int) $lignes->max('ordre') + 1;
            CompteDuBandeau::query()->where('ordre', '>=', $ordre)->increment('ordre');
            CompteDuBandeau::query()->create(['user_id' => $compte->getKey(), 'libelle_du_role' => $role->libelle, 'role' => $role->cle, 'groupe' => $role->groupe, 'ordre' => $ordre]);
        }));
    }
}
