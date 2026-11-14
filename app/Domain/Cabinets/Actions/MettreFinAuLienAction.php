<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\MotifDeFinDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * La fin du lien entre un cabinet d'expertise comptable et un client :
 * par le propriétaire du client ou par celui du cabinet, chacun contrôlé
 * dans sa propre société (voir LienCabinetPolicy::mettreFin()), même pour
 * un cabinet qui n'est plus vérifié ou en lecture seule ; par l'éditeur,
 * quand il constate la radiation du cabinet de l'Ordre, ou qu'il révoque
 * le cabinet ou le client (voir parLEditeur() et pourUnClientRevoque()) ;
 * par le système, quand l'inscription d'une société non validée se clôt
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
 * d'abord de la cloche ; un avis qui ne part pas (une messagerie en panne)
 * est signalé sans rien défaire, et n'arrête ni le geste qui l'a demandé
 * ni la suite d'une procédure qui termine plusieurs liens.
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
            $this->journalDesDroits->exigerDansSaSociete(ValeurRequise::de($auteurRelu, 'l\'auteur du geste, relu sous verrou'), 'mettreFin', $relu);
        });
    }

    /**
     * La fin d'un lien décidée par une procédure de l'éditeur qui vise le
     * cabinet ou le client ($visee), sans auteur : sa justification et ses
     * opérateurs ne s'inscrivent qu'au journal de la société visée (voir
     * JournalDesDroits::modifierLeLien()) ; le propriétaire du cabinet est
     * prévenu, et celui du client, sauf quand c'est le client que l'éditeur
     * vise (il l'apprend avec sa révocation).
     */
    public function parLEditeur(LienCabinet $lien, MotifDeFinDuLien $motif, InterventionDeLEditeur $editeur, Societe $visee): void
    {
        $this->terminer(null, $lien, $motif, $editeur, static fn (): null => null, CleDuModele::de($visee) !== (int) $lien->societe_id, $visee);
    }

    /**
     * Le lien actif et les demandes en cours, dans les deux sens, d'un
     * client que l'éditeur révoque prennent fin dans la transaction de sa
     * révocation, qui tient déjà les registres de ses magasins réels et sa
     * ligne (voir RevoquerSocieteAction) : le lien, ou la déclaration de
     * son expert-comptable, dont les comptes sont retirés sans auteur, par
     * la procédure de l'éditeur (voir parLEditeur()), motif « société
     * révoquée », le cabinet d'un lien prévenu ; chaque demande retirée au nom du système, son jeton
     * effacé, son cabinet prévenu sans le motif (voir
     * RetirerLaDemandeDeLienAction::parLeSysteme()).
     */
    public function pourUnClientRevoque(Societe $client, InterventionDeLEditeur $editeur): void
    {
        $liens = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $client->getKey())
            ->whereIn('etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, [...EtatDuLien::enCours(), ...EtatDuLien::demandes()]))
            ->orderBy('id')
            ->get();

        foreach ($liens as $lien) {
            $lien->estEnCours()
                ? $this->parLEditeur($lien, MotifDeFinDuLien::SocieteRevoquee, $editeur, $client)
                : $this->retraitDesDemandes->parLeSysteme($lien);
        }
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
     * chaque cabinet prévenu, celui d'une demande sans le motif (voir
     * RetirerLaDemandeDeLienAction::parLeSysteme()) ; le nouveau
     * propriétaire choisit lui-même son cabinet, et une société close n'en
     * gère plus.
     */
    public function pourUneSocieteQuiChangeDeMains(Societe $client, MotifDeFinDuLien $motif): void
    {
        $liens = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $client->getKey())
            ->whereIn('etat', [EtatDuLien::Actif->value, ...array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::demandes())])
            ->orderBy('id')
            ->get();

        foreach ($liens as $lien) {
            $lien->estActif() ? $this->parLeSysteme($lien, $motif) : $this->retraitDesDemandes->parLeSysteme($lien);
        }
    }

    /**
     * Termine ce lien en cours, relu et verrouillé, dans la modification en
     * cours : pour une déclaration, ses comptes d'expert-comptable retirés
     * d'abord, au nom de l'auteur seulement s'il est de la société du
     * client (un compte d'un cabinet n'écrit jamais le statut d'un compte
     * du client) ; puis état, auteur, date et motif, clients confiés,
     * autorisations et magasins choisis effacés. Sert aussi au remplacement
     * d'un cabinet ou d'une déclaration (voir AccepterLaDemandeDeLienAction
     * et DeclarerSonExpertComptableAction).
     */
    public static function terminerSousVerrou(LienCabinet $relu, ?User $auteur, MotifDeFinDuLien $motif): void
    {
        if ($relu->estDeclare()) {
            app(ExpertsComptablesDeclares::class)->retirerLesComptes($relu, $auteur !== null && (int) $auteur->societe_id === (int) $relu->societe_id ? $auteur : null);
        }

        $relu->forceFill([
            'etat' => EtatDuLien::Termine,
            'termine_par' => $auteur?->getKey(),
            'termine_le' => now(),
            'motif_fin' => $motif,
        ])->save();
        app(AccesDesCabinets::class)->effacerLesClientsConfies($relu);
        DB::table('autorisations_cabinet')->where('societe_id', $relu->societe_id)->where('lien_id', $relu->getKey())->delete();
        DB::table('magasins_cabinet')->where('societe_id', $relu->societe_id)->where('lien_id', $relu->getKey())->delete();
    }

    /**
     * Prévient, à la fin d'un lien, le propriétaire du client et celui du
     * cabinet (sauf l'auteur, qui le sait ; le client, sur demande, quand
     * il a lui-même lié un autre cabinet), et les collaborateurs qui avaient
     * ce client, dont les avis sur lui s'effacent d'abord. Chaque avis part
     * à part : celui qui ne part pas (une messagerie en panne) est signalé
     * sans empêcher les suivants. Rien pour une déclaration, sans cabinet :
     * les personnes de l'expert-comptable ne sont pas prévenues.
     *
     * @param  Collection<int, User>  $comptesDuCabinet  les comptes du cabinet qui avaient accès
     */
    public static function prevenir(LienCabinet $lien, Collection $comptesDuCabinet, ?User $auteur, string $raison, bool $prevenirLeClient = true): void
    {
        if ($lien->cabinet_id === null) {
            return;
        }

        $client = Societe::query()->findOrFail($lien->societe_id);
        $cabinet = Societe::query()->findOrFail($lien->cabinet_id);

        foreach ($comptesDuCabinet as $compte) {
            $compte->effacerLesAvisDesClients([CleDuModele::de($client)]);

            if ($compte->is($auteur)) {
                continue;
            }

            self::notifierSansInterrompre($compte, new AvisDuCabinet(
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
            self::notifierSansInterrompre($proprietaire, new AvisDuCabinet(
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
     * Envoie cet avis sans interrompre ce qui suit : un avis qui ne part pas
     * est signalé, et rien n'est défait.
     */
    private static function notifierSansInterrompre(User $destinataire, AvisDuCabinet $avis): void
    {
        try {
            $destinataire->notify($avis);
        } catch (Throwable $erreur) {
            report($erreur);
        }
    }

    /**
     * @param  callable(User|null, LienCabinet): mixed  $controle
     * @param  Societe|null  $visee  la société que vise la procédure de l'éditeur qui met fin au lien
     */
    private function terminer(?User $auteur, LienCabinet $lien, MotifDeFinDuLien $motif, ?InterventionDeLEditeur $editeur, callable $controle, bool $prevenirLeClient = true, ?Societe $visee = null): void
    {
        $client = Societe::query()->findOrFail($lien->societe_id);
        $avant = $this->acces->comptesQuiOntAcces($lien);

        $termine = $this->journalDesDroits->modifierLeLien($auteur, $client, [$lien], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($motif, $controle): bool {
            [$relu] = $relus;
            $controle($auteurRelu, $relu);

            if (! $relu->estEnCours()) {
                return false;
            }

            self::terminerSousVerrou($relu, $auteurRelu, $motif);

            return true;
        }, function (Societe $journal, array $relus, bool $termine) use ($client, $motif): array {
            [$relu] = $relus;

            if (! $termine) {
                return [];
            }

            $declaration = $relu->cabinet_id === null;

            return [[ActionAuditee::LienTermine, [
                'cabinet' => $declaration ? IdentiteDansLeJournal::declaree($relu) : IdentiteDansLeJournal::de(Societe::query()->findOrFail($relu->cabinet_id)),
                'client' => IdentiteDansLeJournal::de($client),
                'motif' => $motif->value,
            ], $declaration ? CleDuModele::de($client) : (int) $relu->cabinet_id]];
        }, editeur: $editeur, visee: $visee);

        if ($termine) {
            DB::afterCommit(static function () use ($lien, $avant, $auteur, $motif, $prevenirLeClient): void {
                try {
                    self::prevenir($lien, $avant, $auteur, $motif->getLabel().'.', $prevenirLeClient);
                } catch (Throwable $erreur) {
                    report($erreur);
                }
            });
        }
    }
}
