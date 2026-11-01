<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * L'acceptation d'une demande de lien entre un cabinet d'expertise
 * comptable et un client : une demande de rattachement du cabinet, par le
 * propriétaire du client, depuis la page de rattachement ou « Cabinet
 * comptable », avec les autorisations par défaut ou ajustées (consultations
 * retirées, achats et stock ajoutés pour un client qui a des articles) ;
 * la désignation du cabinet par le client, par le propriétaire du cabinet,
 * avec les autorisations par défaut. Chacun est contrôlé dans sa propre
 * société (voir LienCabinetPolicy::accepter()). Sous les verrous de
 * JournalDesDroits::modifierLeLien() : la demande doit être encore en
 * cours et valable, le cabinet encore vérifié (sa ligne de vérification
 * relue sous un verrou partagé) ; le lien actif d'un autre cabinet prend
 * fin (remplacé), la demande devient le lien actif, son jeton s'efface, et
 * ses autorisations s'écrivent, en une seule modification : un seul JET
 * 130 par compte et par magasin, une seule entrée LienActive par journal.
 * Accepter un rattachement ne valide jamais une société qui attend son
 * code.
 */
class AccepterLaDemandeDeLienAction
{
    public const MESSAGE_PLUS_VALABLE = 'Cette demande n\'est plus valable.';

    public const MESSAGE_CABINET_NON_LIABLE = 'Ce cabinet ne peut plus être lié.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly AccesDesCabinets $acces,
    ) {}

    /**
     * @param  list<string>|null  $autorisations  null : les autorisations par défaut ; une liste, pour une demande de rattachement acceptée par le propriétaire du client
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien, ?array $autorisations = null): LienCabinet
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $remplace = null;
        $ancienAcces = collect();

        $this->journalDesDroits->modifierLeLien($auteur, $client, [$lien], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($autorisations, &$remplace, &$ancienAcces): void {
            [$demande] = $relus;
            $this->journalDesDroits->exigerDansSaSociete($auteurRelu, 'accepter', $demande);

            if (! $demande->estUneDemandeValable()) {
                throw new LogicException(self::MESSAGE_PLUS_VALABLE);
            }

            if ($autorisations !== null && ($demande->etat !== EtatDuLien::DemandeParLeCabinet || (int) $auteurRelu->societe_id !== (int) $clientVerrouille->getKey())) {
                throw new LogicException('Seul le propriétaire du client ajuste les autorisations d\'une demande de rattachement.');
            }

            $cabinet = Societe::query()->findOrFail($demande->cabinet_id);
            $cabinet->cabinet()->sharedLock()->first();

            if (! $cabinet->estUnCabinetVerifie()) {
                throw new LogicException(self::MESSAGE_CABINET_NON_LIABLE);
            }

            $remplace = LienCabinet::query()->where('etat', EtatDuLien::Actif->value)->whereKeyNot($demande->getKey())->first();

            if ($remplace !== null) {
                $ancienAcces = $this->acces->comptesQuiOntAcces($remplace);
                MettreFinAuLienAction::terminerSousVerrou($remplace, $auteurRelu, MotifDeFinDuLien::Remplace);
            }

            $demande->forceFill([
                'etat' => EtatDuLien::Actif,
                'repondu_par' => $auteurRelu->getKey(),
                'repondu_le' => now(),
                'actif_depuis' => now(),
                'jeton_hache' => null,
            ])->save();
            $demande->accorder($autorisations ?? PermissionRegistry::parDefautDuCabinet($clientVerrouille->type_activite));
        }, function (Societe $journal, array $relus) use ($client, $autorisations, &$remplace): array {
            [$demande] = $relus;
            $cabinet = Societe::query()->findOrFail($demande->cabinet_id);
            $active = [ActionAuditee::LienActive, [
                'cabinet' => IdentiteDansLeJournal::de($cabinet),
                'client' => IdentiteDansLeJournal::de($client),
                'origine' => $demande->origine->value,
                'autorisations' => $autorisations === null ? 'par_defaut' : 'ajustees',
            ], (int) $cabinet->getKey()];
            $termine = $remplace === null ? null : [ActionAuditee::LienTermine, [
                'cabinet' => IdentiteDansLeJournal::de(Societe::query()->findOrFail($remplace->cabinet_id)),
                'client' => IdentiteDansLeJournal::de($client),
                'motif' => MotifDeFinDuLien::Remplace->value,
            ], (int) $remplace->cabinet_id];

            return match (true) {
                $journal->is($client) => array_values(array_filter([$termine, $active])),
                (int) $journal->getKey() === (int) $cabinet->getKey() => [$active],
                default => array_values(array_filter([$termine])),
            };
        });

        $lien->refresh();
        DB::afterCommit(fn () => $this->prevenir($auteur, $lien, $remplace, $ancienAcces));

        return $lien;
    }

    /**
     * L'autre partie est prévenue ; à un remplacement, le propriétaire de
     * l'ancien cabinet et ses collaborateurs confiés aussi, dont les avis
     * sur ce client s'effacent d'abord.
     *
     * @param  Collection<int, User>  $ancienAcces
     */
    private function prevenir(User $auteur, LienCabinet $lien, ?LienCabinet $remplace, Collection $ancienAcces): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $cabinet = Societe::query()->findOrFail($lien->cabinet_id);

        if ((int) $auteur->societe_id === (int) $client->getKey()) {
            $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
                $cabinet,
                'Nouveau client lié',
                "{$client->getFilamentName()} a accepté le lien avec votre cabinet : vous pouvez maintenant confier ce client à vos collaborateurs.",
                ClientDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet),
                'Voir vos clients',
                AvisDuCabinet::SUCCES,
                parCourriel: true,
                clientId: (int) $client->getKey(),
            ));
        } else {
            $client->proprietaire()->first()?->notify(new AvisDuCabinet(
                $client,
                'Cabinet lié',
                "Le cabinet {$cabinet->getFilamentName()} a accepté votre demande : il consulte vos magasins réels selon les autorisations que vous lui accordez.",
                CabinetComptable::getUrl(panel: 'societe', tenant: $client),
                'Voir le cabinet comptable',
                AvisDuCabinet::SUCCES,
                parCourriel: true,
            ));
        }

        if ($remplace !== null) {
            MettreFinAuLienAction::prevenir($remplace, $ancienAcces, $auteur, 'votre client a lié un autre cabinet.', prevenirLeClient: false);
        }
    }
}
