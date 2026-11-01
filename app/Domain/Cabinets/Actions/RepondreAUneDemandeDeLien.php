<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ce que partagent le refus, le retrait et l'expiration d'une demande de
 * lien entre un cabinet et un client, qui ne changent aucun accès : dans
 * le contexte du client, sous le verrou de la ligne du client (où se
 * sérialisent les liens d'un client) puis de la demande, la demande en
 * cours passe à son nouvel état, son jeton s'efface (le lien de l'e-mail
 * ne vaut plus rien), et le geste s'inscrit au journal d'audit des deux
 * sociétés : au journal du cabinet, d'une demande qu'il a envoyée et qui
 * n'est pas devenue un lien actif, la société n'est que son SIREN.
 */
abstract class RepondreAUneDemandeDeLien
{
    public function __construct(
        protected readonly JournalDesDroits $journalDesDroits,
        protected readonly JournalAudit $journalAudit,
    ) {}

    /**
     * Passe la demande à cet état, au nom de l'auteur (null pour le
     * système), qu'un contrôle vérifie sur le compte et la demande relus ;
     * rend la demande relue, ou null quand elle n'était plus en cours (rien
     * n'est écrit, et le geste dit qu'elle n'est plus valable).
     *
     * @param  callable(User|null, LienCabinet): void  $controle
     *
     * @throws AuthorizationException
     */
    protected function cloreLaDemande(?User $auteur, LienCabinet $lien, EtatDuLien $etat, ActionAuditee $action, callable $controle): ?LienCabinet
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $cabinet = Societe::query()->findOrFail($lien->cabinet_id);

        return app(TenantContext::class)->runAsSociete($client, fn (): ?LienCabinet => DB::transaction(function () use ($auteur, $lien, $etat, $action, $controle, $client, $cabinet): ?LienCabinet {
            Societe::query()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
            $relu = LienCabinet::query()->whereKey($lien->getKey())->lockForUpdate()->firstOrFail();
            $auteurRelu = $auteur === null ? null : User::query()->whereKey($auteur->getKey())->lockForUpdate()->firstOrFail();

            if (! $relu->estUneDemande()) {
                return null;
            }

            $controle($auteurRelu, $relu);

            $relu->forceFill(['etat' => $etat, 'jeton_hache' => null, 'repondu_par' => $auteurRelu?->getKey(), 'repondu_le' => now()])->save();
            $duCabinet = $relu->origine === OrigineDuLien::DemandeDuCabinet;

            $this->journalAudit->inscrire($action, $auteurRelu, null, null, [
                'cabinet' => IdentiteDansLeJournal::de($cabinet),
                'client' => IdentiteDansLeJournal::de($client),
            ], $relu);
            app(TenantContext::class)->runAsSociete($cabinet, fn () => $this->journalAudit->inscrire($action, $auteurRelu, null, null, [
                'cabinet' => IdentiteDansLeJournal::de($cabinet),
                'client' => $duCabinet ? IdentiteDansLeJournal::sirenSeul($client) : IdentiteDansLeJournal::de($client),
            ], $relu));

            return $relu;
        }));
    }

    /**
     * Prévient le propriétaire du cabinet, d'une demande qu'il avait
     * envoyée (elle ne cite que le SIREN) ou reçue.
     */
    protected static function prevenirLeCabinet(LienCabinet $lien, string $titre, string $texte): void
    {
        $cabinet = Societe::query()->findOrFail($lien->cabinet_id);

        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
            $cabinet,
            $titre,
            $texte,
            InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet),
            'Voir les invitations et demandes',
            AvisDuCabinet::INFORMATION,
            parCourriel: true,
        ));
    }

    /**
     * Prévient le propriétaire du client d'une demande qu'il avait
     * envoyée.
     */
    protected static function prevenirLeClient(LienCabinet $lien, string $titre, string $texte): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);

        $client->proprietaire()->first()?->notify(new AvisDuCabinet(
            $client,
            $titre,
            $texte,
            CabinetComptable::getUrl(panel: 'societe', tenant: $client),
            'Voir le cabinet comptable',
            AvisDuCabinet::INFORMATION,
            parCourriel: true,
        ));
    }

    /**
     * La demande est-elle encore une demande ? Sinon le geste ne change
     * rien et le dit.
     *
     * @throws LogicException
     */
    protected static function exigerUneDemande(?LienCabinet $relu): LienCabinet
    {
        return $relu ?? throw new LogicException(AccepterLaDemandeDeLienAction::MESSAGE_PLUS_VALABLE);
    }
}
