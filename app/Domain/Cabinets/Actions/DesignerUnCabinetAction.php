<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\JournalAudit;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use App\Filament\Cabinet\Pages\InvitationsEtDemandes;
use App\Models\User;
use Carbon\CarbonInterval;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le propriétaire d'une société désigne son cabinet parmi les cabinets
 * vérifiés (voir RechercheDesCabinets et SocietePolicy::gererSonCabinet()) :
 * une demande de lien, que le propriétaire du cabinet accepte ou refuse
 * dans les 7 jours. Sous le verrou de la ligne du client, où se
 * sérialisent aussi les demandes de rattachement d'un cabinet : si ce
 * cabinet a déjà demandé le rattachement, le désigner revient à accepter
 * sa demande, avec les autorisations par défaut (voir
 * AccepterLaDemandeDeLienAction, qui relit tout sous ses propres verrous) ;
 * une demande déjà en cours vers ce cabinet est rendue telle quelle ; une
 * demande refusée, retirée ou expirée depuis moins de 7 jours ne se
 * renouvelle pas, une demande dont l'échéance est passée expirant d'abord,
 * sans attendre la tâche horaire (voir
 * RetirerLaDemandeDeLienAction::expirerLesPerimees()). Une société qui attend son courrier lie un cabinet, sans
 * que cela la valide jamais. Le propriétaire du cabinet est prévenu, par
 * e-mail compris, dans la limite d'e-mails de l'auteur, par un avis qui
 * nomme le client et s'efface de sa cloche quand le lien prend fin (voir
 * MettreFinAuLienAction::prevenir()).
 */
class DesignerUnCabinetAction
{
    public const MESSAGE_CABINET_NON_LIABLE = 'Ce cabinet ne peut pas encore être lié.';

    public const MESSAGE_DEJA_LIE = 'Ce cabinet est déjà votre cabinet.';

    public const MESSAGE_DEJA_DEMANDE = 'Vous avez déjà demandé ce lien il y a moins de 7 jours.';

    public const MESSAGE_DEMANDE_EN_COURS = 'Une demande de lien est déjà en cours avec ce cabinet.';

    public const DELAI_AVANT_UNE_NOUVELLE_DEMANDE_EN_JOURS = 7;

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly JournalAudit $journalAudit,
        private readonly DebitDesEmails $debit,
        private readonly TraceDuVisiteur $traces,
        private readonly RetirerLaDemandeDeLienAction $demandes,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, Societe $client, Societe $cabinet): LienCabinet
    {
        $auteurRelu = $this->journalDesDroits->exigerDansSaSociete($auteur, 'gererSonCabinet', $client);

        if (! $cabinet->estUnCabinetVerifie()) {
            throw new LogicException(self::MESSAGE_CABINET_NON_LIABLE);
        }

        $this->debit->verifier($auteurRelu);

        try {
            $resultat = app(TenantContext::class)->runAsSociete($client, fn (): array => DB::transaction(function () use ($auteurRelu, $client, $cabinet): array {
                Societe::query()->whereKey($client->getKey())->lock('for no key update')->firstOrFail();
                $liens = $this->demandes->expirerLesPerimees(LienCabinet::query()->where('cabinet_id', $cabinet->getKey())->orderBy('id')->lockForUpdate()->get());

                if ($liens->contains(fn (LienCabinet $lien): bool => $lien->estActif())) {
                    throw new LogicException(self::MESSAGE_DEJA_LIE);
                }

                $duCabinet = $liens->first(fn (LienCabinet $lien): bool => $lien->etat === EtatDuLien::DemandeParLeCabinet);

                if ($duCabinet !== null) {
                    return ['accepter' => $duCabinet];
                }

                $enCours = $liens->first(fn (LienCabinet $lien): bool => $lien->etat === EtatDuLien::DemandeParLeClient);

                if ($enCours !== null) {
                    return ['lien' => $enCours, 'nouveau' => false];
                }

                if ($liens->contains(fn (LienCabinet $lien): bool => $lien->origine === OrigineDuLien::DesignationDuClient
                    && $lien->finDeLaDemande()?->greaterThan(Calendrier::deParis()->decaler(now(), CarbonInterval::days(-self::DELAI_AVANT_UNE_NOUVELLE_DEMANDE_EN_JOURS))))) {
                    throw new LogicException(self::MESSAGE_DEJA_DEMANDE);
                }

                $lien = tap((new LienCabinet)->forceFill([
                    'cabinet_id' => $cabinet->getKey(),
                    'origine' => OrigineDuLien::DesignationDuClient,
                    'etat' => EtatDuLien::DemandeParLeClient,
                    'demande_par' => $auteurRelu->getKey(),
                    'demande_le' => now(),
                    'expire_le' => Calendrier::deParis()->decaler(now(), CarbonInterval::days(LienCabinet::DUREE_D_UNE_DEMANDE_EN_JOURS)),
                ]))->save();

                $valeurs = ['cabinet' => IdentiteDansLeJournal::de($cabinet), 'client' => IdentiteDansLeJournal::de($client), 'origine' => OrigineDuLien::DesignationDuClient->value];
                $this->journalAudit->inscrire(ActionAuditee::LienDemande, $auteurRelu, null, null, $valeurs, $lien);
                $this->traces->horsDeSaSociete(fn () => app(TenantContext::class)->runAsSociete($cabinet, fn () => $this->journalAudit->inscrireLeGesteDeLAutreSociete(ActionAuditee::LienDemande, null, $valeurs, $lien)));

                return ['lien' => $lien, 'nouveau' => true];
            }));
        } catch (UniqueConstraintViolationException $doublon) {
            if (! str_contains($doublon->getMessage(), 'liens_cabinet_une_demande_par_paire')) {
                throw $doublon;
            }

            throw new LogicException(self::MESSAGE_DEMANDE_EN_COURS);
        }

        if (isset($resultat['accepter'])) {
            return app(AccepterLaDemandeDeLienAction::class)->handle($auteurRelu, $resultat['accepter']);
        }

        if ($resultat['nouveau']) {
            $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
                $cabinet,
                'Demande de lien d\'un client',
                "{$client->getFilamentName()} (SIREN {$client->siren}) demande à lier votre cabinet : répondez dans les 7 jours, dans « Invitations et demandes ».",
                InvitationsEtDemandes::getUrl(panel: 'cabinet', tenant: $cabinet),
                'Répondre',
                AvisDuCabinet::INFORMATION,
                parCourriel: true,
                clientId: (int) $client->getKey(),
            ));
            $this->debit->compter($auteurRelu);
        }

        return $resultat['lien'];
    }
}
