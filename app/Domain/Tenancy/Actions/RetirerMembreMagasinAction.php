<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * "Supprimer" un membre ne supprime jamais son compte User (il peut
 * appartenir à d'autres magasins, ex: un comptable) : seul le
 * rattachement à CE magasin disparaît, avec tout ce qu'il donnait dans ce
 * magasin -- son rôle Spatie, ses permissions supplémentaires (Spatie en
 * mode teams ne touche qu'à celles du magasin du TenantContext) -- et ses
 * notifications à propos de ce magasin (voir NotificationDuMagasin), qui
 * ne le regardent plus.
 *
 * Le retrait s'inscrit au journal des événements du magasin, avec les
 * droits que le membre perd (JET 130, voir JournalDesDroits).
 */
class RetirerMembreMagasinAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    public function handle(MagasinUser $membre): void
    {
        if ($membre->role === RoleMagasin::Proprietaire) {
            throw new LogicException("Le propriétaire ne peut pas être retiré de l'équipe.");
        }

        DB::transaction(function () use ($membre): void {
            $magasin = $membre->magasin;
            $utilisateur = $membre->utilisateur;
            app(TenantContext::class)->set($magasin);
            $avant = $this->journalDesDroits->avant($magasin, $utilisateur);

            $utilisateur->removeRole($membre->role->value);
            $utilisateur->syncPermissions([]);
            $utilisateur->effacerLesNotificationsDu($magasin);
            $membre->delete();

            $this->journalDesDroits->apres($magasin, $utilisateur, $avant);
        });
    }
}
