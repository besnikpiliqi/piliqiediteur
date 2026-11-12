<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Ce que partagent le refus, le retrait et l'expiration d'une demande de
 * lien entre un cabinet et un client, qui ne changent aucun accès : dans
 * le contexte du client, sous le verrou de la ligne du client (« for no
 * key update », où se sérialisent les liens d'un client sans retenir une
 * insertion qui la référence, voir JournalDesDroits::modifierLeLien()),
 * puis de la demande, la demande en cours passe à son nouvel état, son
 * jeton s'efface (le lien de l'e-mail ne vaut plus rien), et le geste
 * s'inscrit au journal d'audit des deux sociétés : au journal du cabinet,
 * d'une demande qu'il a envoyée et qui n'est pas devenue un lien actif, la
 * société n'est que son SIREN. L'auteur n'est nommé qu'au journal de sa
 * propre société : au journal de l'autre, son geste est celui de « l'autre
 * société », sans rien de lui (voir
 * JournalAudit::inscrireLeGesteDeLAutreSociete()), la demande n'étant
 * jamais devenue un lien actif. La trace du visiteur ne se note qu'au
 * journal de la société de l'auteur, du client pour le système (voir
 * TraceDuVisiteur).
 */
abstract class RepondreAUneDemandeDeLien
{
    public function __construct(
        protected readonly JournalDesDroits $journalDesDroits,
        protected readonly JournalAudit $journalAudit,
        protected readonly TraceDuVisiteur $traces,
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
            Societe::query()->whereKey($client->getKey())->lock('for no key update')->firstOrFail();
            $relu = LienCabinet::query()->whereKey($lien->getKey())->lockForUpdate()->firstOrFail();
            $auteurRelu = $auteur === null ? null : User::query()->whereKey($auteur->getKey())->lockForUpdate()->firstOrFail();

            if (! $relu->estUneDemande()) {
                return null;
            }

            $controle($auteurRelu, $relu);

            self::cloreSousVerrou($relu, $etat, $auteurRelu);
            $duCabinet = $relu->origine === OrigineDuLien::DemandeDuCabinet;

            $duVisiteur = $auteurRelu->societe_id ?? CleDuModele::de($client);

            $this->inscrireAuJournalDe($client, $duVisiteur, $action, $auteurRelu, $relu, ['cabinet' => IdentiteDansLeJournal::de($cabinet), 'client' => IdentiteDansLeJournal::de($client)]);
            $this->inscrireAuJournalDe($cabinet, $duVisiteur, $action, $auteurRelu, $relu, ['cabinet' => IdentiteDansLeJournal::de($cabinet), 'client' => $duCabinet ? IdentiteDansLeJournal::sirenSeul($client) : IdentiteDansLeJournal::de($client)]);

            return $relu;
        }));
    }

    /**
     * Clôt cette demande, relue et verrouillée par l'appelant, dans sa
     * transaction : son état, son jeton effacé, l'auteur de la réponse
     * (null pour le système) et sa date. Sert aussi au retrait des demandes
     * qu'une déclaration de l'expert-comptable de la société remplace, et
     * de celles qu'un rattachement rend sans objet.
     */
    public static function cloreSousVerrou(LienCabinet $relu, EtatDuLien $etat, ?User $auteur): void
    {
        $relu->forceFill(['etat' => $etat, 'jeton_hache' => null, 'repondu_par' => $auteur?->getKey(), 'repondu_le' => now()])->save();
    }

    /**
     * Inscrit le geste au journal de cette société : directement quand
     * c'est la société du visiteur (celle de l'auteur, ou le client pour
     * le système), sans garder la trace du visiteur sinon (voir
     * TraceDuVisiteur).
     *
     * @param  array<string, mixed>  $valeurs
     */
    private function inscrireAuJournalDe(Societe $journal, int $duVisiteur, ActionAuditee $action, ?User $auteur, LienCabinet $demande, array $valeurs): void
    {
        if (CleDuModele::de($journal) === $duVisiteur) {
            $this->inscrire($journal, $action, $auteur, $valeurs, $demande);
        } else {
            $this->traces->horsDeSaSociete(fn (): EntreeAudit => $this->inscrire($journal, $action, $auteur, $valeurs, $demande));
        }
    }

    /**
     * Inscrit le geste sur la demande au journal de cette société : au nom
     * de son auteur s'il est de cette société, du système sans auteur, de
     * « l'autre société » sinon, sans rien de lui.
     *
     * @param  array<string, mixed>  $valeurs
     */
    private function inscrire(Societe $journal, ActionAuditee $action, ?User $auteur, array $valeurs, LienCabinet $demande): EntreeAudit
    {
        return app(TenantContext::class)->runAsSociete($journal, fn (): EntreeAudit => $auteur !== null && (int) $auteur->societe_id !== CleDuModele::de($journal)
            ? $this->journalAudit->inscrireLeGesteDeLAutreSociete($action, null, $valeurs, $demande)
            : $this->journalAudit->inscrire($action, $auteur, null, null, $valeurs, $demande));
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
