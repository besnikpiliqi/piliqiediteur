<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * « Retirer » un membre ne supprime jamais son compte User : ses factures,
 * ses paiements, ses avoirs et le registre fiscal le désignent encore.
 * Seul son rattachement au magasin disparaît, avec tout ce qu'il donnait :
 * ses autorisations (Spatie en mode teams ne touche qu'à celles du magasin
 * du TenantContext) et ses notifications à propos du magasin (voir
 * NotificationDuMagasin). Un compte n'appartenant qu'à un seul magasin, il
 * n'ouvre alors plus rien, et son adresse e-mail ne sert jamais à un
 * nouveau membre (voir EmailLibrePourUnNouveauMembre).
 *
 * Le retrait s'inscrit au journal des événements du magasin, avec les
 * droits que le membre perd (JET 130, voir JournalDesDroits).
 */
class RetirerMembreMagasinAction
{
    public function __construct(private readonly JournalDesDroits $journalDesDroits) {}

    public function handle(MagasinUser $membre): void
    {
        if ($membre->utilisateur->estProprietaire()) {
            throw new LogicException("Le propriétaire ne peut pas être retiré de l'équipe.");
        }

        DB::transaction(function () use ($membre): void {
            $magasin = $membre->magasin;
            $utilisateur = $membre->utilisateur;
            app(TenantContext::class)->set($magasin);
            $avant = $this->journalDesDroits->avant($magasin, $utilisateur);

            $utilisateur->syncPermissions([]);
            $utilisateur->effacerLesNotificationsDu($magasin);
            $membre->delete();

            $this->journalDesDroits->apres($magasin, $utilisateur, $avant);
        });
    }
}
