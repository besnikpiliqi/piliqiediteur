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
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
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
 * relue sous un verrou partagé) ; le client lit, pour la demande d'un
 * cabinet suspendu, qu'il ne l'accepte pas tant que le cabinet l'est, mais
 * peut la refuser (décision de l'éditeur du 11/11/2026, voir
 * RefuserLaDemandeDeLienAction) ; le lien actif d'un autre cabinet prend
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

    public const MESSAGE_CABINET_SUSPENDU = 'Ce cabinet est suspendu : sa demande ne peut pas être acceptée tant qu\'il l\'est. Vous pouvez la refuser.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly AccesDesCabinets $acces,
    ) {}

    /**
     * @param  list<string>|null  $autorisations  null : les autorisations par défaut ; une liste, pour une demande de rattachement acceptée par le propriétaire du client
     * @param  array<array-key, mixed>|null  $magasins  null : tous les magasins réels du client, présents et futurs ; une sélection d'au moins un, pour une demande de rattachement acceptée par le propriétaire du client
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien, ?array $autorisations = null, ?array $magasins = null): LienCabinet
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $rattachee = $this->rattacherLaDeclarationDuMemeCabinet($auteur, $client, $lien, $autorisations, $magasins);

        if ($rattachee !== null) {
            return $rattachee;
        }

        [$remplace, $ancienAcces] = $this->journalDesDroits->modifierLeLien($auteur, $client, [$lien], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($autorisations, $magasins): array {
            [$demande] = $relus;
            $auteurRelu = ValeurRequise::de($auteurRelu, 'l\'auteur du geste, relu sous verrou');
            $this->journalDesDroits->exigerDansSaSociete($auteurRelu, 'accepter', $demande);

            if (! $demande->estUneDemandeValable()) {
                throw new LogicException(self::MESSAGE_PLUS_VALABLE);
            }

            if (($autorisations !== null || $magasins !== null) && ($demande->etat !== EtatDuLien::DemandeParLeCabinet || (int) $auteurRelu->societe_id !== CleDuModele::de($clientVerrouille))) {
                throw new LogicException('Seul le propriétaire du client ajuste les autorisations d\'une demande de rattachement.');
            }

            $cabinet = Societe::query()->findOrFail($demande->cabinet_id);
            $cabinet->cabinet()->sharedLock()->first();

            if ($demande->etat === EtatDuLien::DemandeParLeCabinet && $cabinet->estUnCabinetSuspendu()) {
                throw new LogicException(self::MESSAGE_CABINET_SUSPENDU);
            }

            if (! $cabinet->estUnCabinetVerifie()) {
                throw new LogicException(self::MESSAGE_CABINET_NON_LIABLE);
            }

            $remplace = LienCabinet::query()->enCours()->whereKeyNot($demande->getKey())->first();
            $ancienAcces = $remplace === null ? new Collection : $this->acces->comptesQuiOntAcces($remplace);

            if ($remplace !== null) {
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
            $demande->choisirLesMagasins($magasins);

            return [$remplace, $ancienAcces];
        }, function (Societe $journal, array $relus, array $resultat) use ($client, $autorisations): array {
            [$demande] = $relus;
            [$remplace] = $resultat;
            $cabinet = Societe::query()->findOrFail($demande->cabinet_id);
            $active = [ActionAuditee::LienActive, [
                'cabinet' => IdentiteDansLeJournal::de($cabinet),
                'client' => IdentiteDansLeJournal::de($client),
                'origine' => $demande->origine->value,
                'autorisations' => $autorisations === null ? 'par_defaut' : 'ajustees',
                'magasins' => IdentiteDansLeJournal::magasinsOuverts($demande),
            ], CleDuModele::de($cabinet)];
            $termine = $remplace === null ? null : [ActionAuditee::LienTermine, [
                'cabinet' => $remplace->cabinet_id === null ? IdentiteDansLeJournal::declaree($remplace) : IdentiteDansLeJournal::de(Societe::query()->findOrFail($remplace->cabinet_id)),
                'client' => IdentiteDansLeJournal::de($client),
                'motif' => MotifDeFinDuLien::Remplace->value,
            ], $remplace->cabinet_id === null ? CleDuModele::de($client) : (int) $remplace->cabinet_id];

            return match (true) {
                $journal->is($client) => array_values(array_filter([$termine, $active])),
                CleDuModele::de($journal) === CleDuModele::de($cabinet) => [$active],
                default => array_filter([$termine]),
            };
        });

        $lien->refresh();
        DB::afterCommit(fn () => $this->prevenir($auteur, $lien, $remplace, $ancienAcces));

        return $lien;
    }

    /**
     * Quand le propriétaire du client accepte la demande de rattachement du
     * cabinet que sa société a déclaré comme expert-comptable (son SIREN
     * est celui de la déclaration en attente, une donnée de la société,
     * lue d'abord), c'est le rattachement de cette déclaration, à son nom
     * (voir RattacherUneDeclarationAction) : ses comptes d'expert-comptable
     * se ferment, et le lien garde les autorisations et les magasins de la
     * déclaration, ou prend ceux qu'il ajuste ; la demande est retirée. Rend
     * le lien né de la déclaration, ou null quand ce n'est pas ce cas, ou
     * que la déclaration ne se rattache pas (aucune personne active) : la
     * demande s'accepte alors comme toute autre, et remplace la
     * déclaration.
     *
     * @param  list<string>|null  $autorisations
     * @param  array<array-key, mixed>|null  $magasins
     */
    private function rattacherLaDeclarationDuMemeCabinet(User $auteur, Societe $client, LienCabinet $demande, ?array $autorisations, ?array $magasins): ?LienCabinet
    {
        if ($demande->etat !== EtatDuLien::DemandeParLeCabinet || (int) $auteur->societe_id !== CleDuModele::de($client) || $demande->cabinet_id === null) {
            return null;
        }

        $declaration = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $client->getKey())->where('etat', EtatDuLien::Declare->value)->first(['id', 'siren_cabinet']);
        $cabinet = Societe::query()->find($demande->cabinet_id, ['id', 'siren']);

        if ($declaration === null || $cabinet === null || $declaration->siren_cabinet !== $cabinet->siren) {
            return null;
        }

        $ajustee = $autorisations !== null || $magasins !== null;

        if (! app(RattacherUneDeclarationAction::class)->handle(CleDuModele::de($declaration), CleDuModele::de($cabinet), $auteur, $autorisations, $ajustee, $magasins)) {
            return null;
        }

        return LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $client->getKey())->findOrFail($declaration->id);
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

        if ((int) $auteur->societe_id === CleDuModele::de($client)) {
            $cabinet->proprietaire()->first()?->notify(new AvisDuCabinet(
                $cabinet,
                'Nouveau client lié',
                "{$client->getFilamentName()} a accepté le lien avec votre cabinet : vous pouvez maintenant confier ce client à vos collaborateurs.",
                ClientDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet),
                'Voir vos clients',
                AvisDuCabinet::SUCCES,
                parCourriel: true,
                clientId: CleDuModele::de($client),
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
