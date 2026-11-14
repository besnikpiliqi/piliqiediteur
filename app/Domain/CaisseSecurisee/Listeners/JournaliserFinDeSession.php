<?php

namespace App\Domain\CaisseSecurisee\Listeners;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Support\MagasinsOuvertsParLaSession;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;

/**
 * Inscrit la fin de session (JET 40) au journal des événements de chaque
 * magasin que la session a ouvert (voir MagasinsOuvertsParLaSession), à la
 * déconnexion de l'utilisateur, ou quand la session est fermée parce que
 * son mot de passe a changé depuis un autre appareil (CurrentDeviceLogout,
 * voir AuthenticateSession). L'expiration d'une session sans déconnexion
 * n'est pas tracée : aucune requête n'en est témoin.
 *
 * L'événement est déclenché avant que la session soit invalidée, quand
 * l'utilisateur est encore celui de la garde par défaut : il est
 * l'opérateur de la fin de session. Elle s'écrit dans le registre de
 * chaque magasin (TenantContext::runAs()), seulement si l'utilisateur y a
 * encore des droits, quel que soit son statut (voir User::droitsDans()) :
 * le propriétaire de la société, un compte affecté à ce magasin ou à
 * tous, ou un compte d'un cabinet d'expertise comptable dans un magasin
 * réel d'un client que son lien actif lui ouvre, dans le contexte de ce
 * client ; qui n'y en a plus n'a plus rien à y inscrire.
 *
 * Une déconnexion n'est jamais bloquée : laisser une session ouverte
 * parce que le registre refuse d'écrire (clé de signature absente,
 * horloge qui recule) serait pire que de ne pas la tracer. Le refus est
 * signalé au journal de l'application.
 */
class JournaliserFinDeSession
{
    public function handle(Logout|CurrentDeviceLogout $evenement): void
    {
        $requete = request();
        $utilisateur = $evenement->user;

        if (! $requete->hasSession() || ! $utilisateur instanceof User || ! auth()->user()?->is($utilisateur)) {
            return;
        }

        $session = $requete->session();
        $duCabinet = $utilisateur->estDUnCabinet();
        $magasins = Magasin::query()
            ->with('societe')
            ->whereKey(MagasinsOuvertsParLaSession::de($session))
            ->when(! $duCabinet, fn ($magasins) => $magasins->where('societe_id', $utilisateur->societe_id))
            ->orderBy('id')
            ->get()
            ->filter(fn (Magasin $magasin): bool => $utilisateur->droitsDans($magasin) !== null);
        $contexte = app(TenantContext::class);

        foreach ($magasins as $magasin) {
            $journaliser = fn () => $contexte->runAs($magasin, fn () => DB::transaction(
                fn () => app(RegistreFiscal::class)->journaliser(CodeEvenement::FinDeSession),
            ));

            try {
                $duCabinet ? $contexte->runAsSociete($magasin->societe, $journaliser) : $journaliser();
            } catch (RefusFiscalException $refus) {
                report($refus);
            }
        }

        MagasinsOuvertsParLaSession::oublier($session);
    }
}
