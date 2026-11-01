<?php

namespace App\Domain\Cabinets\Actions;

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
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * La fin du lien entre un cabinet d'expertise comptable et un client :
 * par le propriétaire du client ou par celui du cabinet, chacun contrôlé
 * dans sa propre société (voir LienCabinetPolicy::mettreFin()), même pour
 * un cabinet qui n'est plus vérifié ou en lecture seule ; par l'éditeur,
 * quand il constate la radiation du cabinet de l'Ordre (voir parLEditeur())
 * ; par le système, quand l'inscription d'une société non validée se clôt
 * ou qu'une autre personne saisit le code de son courrier (voir
 * parLeSysteme()). Sous les verrous de JournalDesDroits::modifierLeLien() :
 * le lien passe à « terminé », ses clients confiés et ses autorisations
 * s'effacent ; chaque compte du cabinet qui avait accès perd ses droits,
 * au JET 130 de chaque magasin réel du client, et la fin s'inscrit au
 * journal d'audit des deux sociétés. Un lien déjà terminé ne change rien.
 *
 * Aucune session n'est fermée : chaque session ouverte d'un compte du
 * cabinet perd l'accès au client dès sa requête suivante (Filament relit
 * canAccessTenant(), ControleDesDroits relit User::peutOuvrir()), et le
 * compte garde son espace et ses autres clients (choix de Claude, que
 * l'éditeur peut contester). L'autre propriétaire est prévenu, et les
 * collaborateurs qui avaient ce client, dont les avis sur lui s'effacent
 * d'abord de la cloche.
 */
class MettreFinAuLienAction
{
    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly AccesDesCabinets $acces,
        private readonly RetirerLaDemandeDeLienAction $retraitDesDemandes,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, LienCabinet $lien): void
    {
        $motif = (int) $auteur->societe_id === (int) $lien->cabinet_id ? MotifDeFinDuLien::ParLeCabinet : MotifDeFinDuLien::ParLeClient;

        $this->terminer($auteur, $lien, $motif, null, function (?User $auteurRelu, LienCabinet $relu): void {
            $this->journalDesDroits->exigerDansSaSociete($auteurRelu, 'mettreFin', $relu);
        });
    }

    /**
     * La fin d'un lien décidée par une procédure de l'éditeur, sans auteur.
     */
    public function parLEditeur(LienCabinet $lien, MotifDeFinDuLien $motif, InterventionDeLEditeur $editeur): void
    {
        $this->terminer(null, $lien, $motif, $editeur, static fn (): null => null);
    }

    /**
     * La fin d'un lien qui suit un geste de la vérification d'une société
     * non validée (la clôture de son inscription, la saisie de son code par
     * une autre personne), sans auteur ni intervention, dans la transaction
     * de ce geste, qui tient déjà les registres de ses magasins réels et
     * sa ligne.
     */
    public function parLeSysteme(LienCabinet $lien, MotifDeFinDuLien $motif): void
    {
        $this->terminer(null, $lien, $motif, null, static fn (): null => null, prevenirLeClient: false);
    }

    /**
     * Le lien actif et les demandes en cours, dans les deux sens, d'une
     * société non validée dont l'inscription se clôt ou dont une autre
     * personne saisit le code et devient propriétaire, prennent fin au nom
     * du système, dans la transaction de ce geste : le lien est terminé
     * (voir parLeSysteme()), chaque demande retirée, son jeton effacé, et
     * chaque cabinet prévenu ; le nouveau propriétaire choisit lui-même son
     * cabinet, et une société close n'en gère plus.
     */
    public function pourUneSocieteQuiChangeDeMains(Societe $client, MotifDeFinDuLien $motif): void
    {
        $liens = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $client->getKey())
            ->whereIn('etat', [EtatDuLien::Actif->value, ...array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::demandes())])
            ->orderBy('id')
            ->get();

        foreach ($liens as $lien) {
            $lien->estActif() ? $this->parLeSysteme($lien, $motif) : $this->retraitDesDemandes->parLeSysteme($lien, $motif);
        }
    }

    /**
     * Termine ce lien, relu et verrouillé, dans la modification en cours :
     * état, auteur, date et motif, clients confiés et autorisations
     * effacés. Sert aussi au remplacement d'un cabinet (voir
     * AccepterLaDemandeDeLienAction).
     */
    public static function terminerSousVerrou(LienCabinet $relu, ?User $auteur, MotifDeFinDuLien $motif): void
    {
        $relu->forceFill([
            'etat' => EtatDuLien::Termine,
            'termine_par' => $auteur?->getKey(),
            'termine_le' => now(),
            'motif_fin' => $motif,
        ])->save();
        app(AccesDesCabinets::class)->effacerLesClientsConfies($relu);
        DB::table('autorisations_cabinet')->where('societe_id', $relu->societe_id)->where('lien_id', $relu->getKey())->delete();
    }

    /**
     * Prévient, à la fin d'un lien, le propriétaire du client et celui du
     * cabinet (sauf l'auteur, qui le sait ; le client, sur demande, quand
     * il a lui-même lié un autre cabinet), et les collaborateurs qui avaient
     * ce client, dont les avis sur lui s'effacent d'abord.
     *
     * @param  Collection<int, User>  $comptesDuCabinet  les comptes du cabinet qui avaient accès
     */
    public static function prevenir(LienCabinet $lien, Collection $comptesDuCabinet, ?User $auteur, string $raison, bool $prevenirLeClient = true): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $cabinet = Societe::query()->findOrFail($lien->cabinet_id);

        foreach ($comptesDuCabinet as $compte) {
            $compte->effacerLesAvisDesClients([(int) $client->getKey()]);

            if ($compte->is($auteur)) {
                continue;
            }

            $compte->notify(new AvisDuCabinet(
                $cabinet,
                'Lien terminé',
                "Le lien entre votre cabinet et {$client->getFilamentName()} a pris fin : {$raison} Vous n'avez plus accès à ce client.",
                TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet),
                'Voir l\'espace du cabinet',
                AvisDuCabinet::ATTENTION,
                parCourriel: $compte->estProprietaire(),
            ));
        }

        $proprietaire = $client->proprietaire()->first();

        if ($prevenirLeClient && $proprietaire !== null && ! $proprietaire->is($auteur) && $client->close_le === null) {
            $proprietaire->notify(new AvisDuCabinet(
                $client,
                'Lien avec votre cabinet terminé',
                "Le lien avec le cabinet {$cabinet->getFilamentName()} a pris fin : {$raison} Il n'a plus accès à votre société.",
                CabinetComptable::getUrl(panel: 'societe', tenant: $client),
                'Voir le cabinet comptable',
                AvisDuCabinet::ATTENTION,
                parCourriel: true,
            ));
        }
    }

    /**
     * @param  callable(User|null, LienCabinet): mixed  $controle
     */
    private function terminer(?User $auteur, LienCabinet $lien, MotifDeFinDuLien $motif, ?InterventionDeLEditeur $editeur, callable $controle, bool $prevenirLeClient = true): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $avant = $this->acces->comptesQuiOntAcces($lien);

        $termine = false;

        $this->journalDesDroits->modifierLeLien($auteur, $client, [$lien], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($motif, $controle, &$termine): void {
            [$relu] = $relus;
            $controle($auteurRelu, $relu);

            if ($relu->estActif()) {
                self::terminerSousVerrou($relu, $auteurRelu, $motif);
                $termine = true;
            }
        }, function (Societe $journal, array $relus) use ($client, $motif, &$termine): array {
            [$relu] = $relus;

            return ! $termine ? [] : [[ActionAuditee::LienTermine, [
                'cabinet' => IdentiteDansLeJournal::de(Societe::query()->findOrFail($relu->cabinet_id)),
                'client' => IdentiteDansLeJournal::de($client),
                'motif' => $motif->value,
            ], (int) $relu->cabinet_id]];
        }, editeur: $editeur);

        if ($termine) {
            DB::afterCommit(fn () => self::prevenir($lien, $avant, $auteur, $motif->getLabel().'.', $prevenirLeClient));
        }
    }
}
