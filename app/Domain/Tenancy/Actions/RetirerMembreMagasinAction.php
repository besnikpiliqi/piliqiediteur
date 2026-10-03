<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * "Supprimer" un membre ne supprime jamais son compte User (il peut
 * appartenir à d'autres magasins, ex: un comptable) : seul le
 * rattachement à CE magasin disparaît, avec nettoyage du rôle Spatie
 * correspondant pour ne pas laisser une assignation orpheline.
 */
class RetirerMembreMagasinAction
{
    public function handle(MagasinUser $membre): void
    {
        if ($membre->role === RoleMagasin::Proprietaire) {
            throw new LogicException('Le propriétaire ne peut pas être retiré de son propre magasin.');
        }

        DB::transaction(function () use ($membre) {
            app(TenantContext::class)->set($membre->magasin);
            $membre->utilisateur->removeRole($membre->role->value);
            $membre->delete();
        });
    }
}
