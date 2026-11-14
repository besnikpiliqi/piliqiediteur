<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Mail\CompteDExpertComptableFermeMail;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinetEnFile;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Cabinets\Support\IdentiteDansLeJournal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\TraceDuVisiteur;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use LogicException;

/**
 * Le rattachement de la déclaration d'un expert-comptable de la société au
 * cabinet vérifié de son SIREN (décision de l'éditeur du 01/11/2026, choix
 * 3) : la même ligne de liens_cabinet reçoit ce cabinet et devient le lien
 * actif du client, avec ses autorisations et son choix de magasins, sans
 * aucune copie ; chacun de ses comptes d'expert-comptable est retiré (droits
 * supprimés, lien de son mot de passe et avis effacés, sessions fermées,
 * historique gardé) ; les demandes en cours entre ce client et ce cabinet,
 * dans les deux sens, sont retirées, leur jeton effacé. Le tout dans le
 * contexte du client, sous les verrous de
 * JournalDesDroits::modifierLeLien(), avec le JET 130 de chaque magasin
 * réel (comptes retirés, propriétaire du cabinet qui entre) et les entrées
 * des deux journaux d'audit ; rien des comptes fermés au journal du
 * cabinet.
 *
 * Il ne se fait que si la déclaration est encore en attente, de ce SIREN,
 * le cabinet vérifié (validé, contrôlé 69.20Z, vérifié à l'Ordre, actif) et
 * au moins une personne de la déclaration encore active ; sinon rien ne
 * change, sans trace : le rattachement est rejouable et ne touche jamais à
 * la validation (une déclaration n'existe que dans une société validée).
 * Sans auteur, c'est le système, après le geste qui a rendu le cabinet
 * vérifié ou la déclaration rattachable (voir RattacherUneDeclaration), au
 * nom de personne et sans trace de visiteur ; avec un auteur, c'est le
 * propriétaire du client qui désigne ce cabinet ou accepte sa demande de
 * rattachement, contrôlé dans sa société : les comptes sont retirés par
 * lui, et le lien prend les autorisations et les magasins qu'il valide,
 * préremplis avec ceux de la déclaration.
 *
 * Après le commit, le propriétaire du client (sauf l'auteur) et celui du
 * cabinet sont prévenus, et chaque personne dont le compte se ferme reçoit
 * un e-mail, sans identifiant, chacun dans sa propre tâche en file.
 */
class RattacherUneDeclarationAction
{
    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly ExpertsComptablesDeclares $experts,
        private readonly TraceDuVisiteur $traces,
    ) {}

    /**
     * @param  list<string>|null  $autorisations  null : celles de la déclaration
     * @param  bool  $reglerLesMagasins  faux : le choix de magasins de la déclaration ; vrai : celui de $magasins
     * @param  array<array-key, mixed>|null  $magasins  avec $reglerLesMagasins : null pour tous les magasins réels, présents et futurs, ou une sélection d'au moins un
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function handle(int $declarationId, int $cabinetId, ?User $auteur = null, ?array $autorisations = null, bool $reglerLesMagasins = false, ?array $magasins = null): bool
    {
        $declaration = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->whereKey($declarationId)->first(['id', 'societe_id', 'etat', 'siren_cabinet']);
        $cabinet = Societe::query()->find($cabinetId, ['id', 'siren', 'type_activite', 'validee_le', 'statut']);

        if ($declaration === null || $cabinet === null || $declaration->etat !== EtatDuLien::Declare || $declaration->siren_cabinet !== $cabinet->siren) {
            return false;
        }

        $client = Societe::query()->findOrFail($declaration->societe_id);
        $demandes = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $client->getKey())
            ->where('cabinet_id', $cabinetId)
            ->whereIn('etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::demandes()))
            ->orderBy('id')
            ->get(['id', 'societe_id', 'cabinet_id', 'etat']);

        $rattacher = fn (): ?array => $this->journalDesDroits->modifierLeLien($auteur, $client, [$declaration, ...$demandes->all()], function (?User $auteurRelu, Societe $clientVerrouille, array $relus) use ($cabinetId, $autorisations, $reglerLesMagasins, $magasins): ?array {
            $relue = $relus[0];
            $demandesRelues = array_slice($relus, 1);

            if ($auteurRelu !== null) {
                $auteurRelu = $this->journalDesDroits->exigerDansSaSociete($auteurRelu, 'gererSonCabinet', $clientVerrouille);
            }

            $cabinetRelu = Societe::query()->findOrFail($cabinetId);

            if (! $relue->estDeclare() || $relue->siren_cabinet !== $cabinetRelu->siren || ! $cabinetRelu->estUnCabinetVerifie() || ! $this->experts->aUnCompteActif($relue)) {
                return null;
            }

            if ($autorisations !== null) {
                $relue->accorder($autorisations);
            }

            if ($reglerLesMagasins) {
                $relue->choisirLesMagasins($magasins);
            }

            $comptes = $this->experts->retirerLesComptes($relue, $auteurRelu);
            $retirees = [];

            foreach ($demandesRelues as $demande) {
                if (in_array($demande->etat, EtatDuLien::demandes(), true)) {
                    RepondreAUneDemandeDeLien::cloreSousVerrou($demande, EtatDuLien::Retire, $auteurRelu);
                    $retirees[] = $demande;
                }
            }

            $relue->forceFill(['cabinet_id' => $cabinetId, 'etat' => EtatDuLien::Actif, 'actif_depuis' => now()])->save();

            return ['comptes' => $comptes, 'demandes' => $retirees];
        }, function (Societe $journal, array $relus, ?array $resultat) use ($client, $cabinetId, $autorisations, $reglerLesMagasins): array {
            if ($resultat === null) {
                return [];
            }

            $cabinet = Societe::query()->findOrFail($cabinetId);
            $duClient = $journal->is($client);
            $entrees = [];

            foreach ($resultat['demandes'] as $demande) {
                $entrees[] = [ActionAuditee::DemandeDeLienRetiree, [
                    'cabinet' => IdentiteDansLeJournal::de($cabinet),
                    'client' => IdentiteDansLeJournal::de($client),
                ], null];
            }

            if ($duClient) {
                $entrees[] = [ActionAuditee::ExpertComptableRattache, ['cabinet' => IdentiteDansLeJournal::de($cabinet)], CleDuModele::de($client)];
            }

            $entrees[] = [ActionAuditee::LienActive, [
                'cabinet' => IdentiteDansLeJournal::de($cabinet),
                'client' => IdentiteDansLeJournal::de($client),
                'origine' => OrigineDuLien::DeclarationDuClient->value,
                'autorisations' => $autorisations === null && ! $reglerLesMagasins ? 'declarees' : 'ajustees',
                'magasins' => IdentiteDansLeJournal::magasinsOuverts($relus[0]),
            ], $cabinetId];

            return $entrees;
        }, [$cabinetId]);

        $resultat = $auteur === null ? $this->traces->horsDeSaSociete($rattacher) : $rattacher();

        if ($resultat === null) {
            return false;
        }

        DB::afterCommit(fn () => $this->prevenir($auteur, $client, $cabinetId, $resultat['comptes']));

        return true;
    }

    /**
     * Le propriétaire du client (sauf l'auteur, qui le sait) et celui du
     * cabinet sont prévenus, dans leur cloche et par e-mail ; chaque
     * personne dont le compte se ferme reçoit l'e-mail de fermeture, à son
     * adresse lue maintenant. Chaque avis et chaque e-mail part dans sa
     * propre tâche en file : l'échec de l'un ne double ni n'arrête aucun
     * autre.
     *
     * @param  Collection<int, User>  $comptes  les comptes d'expert-comptable fermés
     */
    private function prevenir(?User $auteur, Societe $client, int $cabinetId, Collection $comptes): void
    {
        $cabinet = Societe::query()->findOrFail($cabinetId);
        $proprietaire = $client->proprietaire()->first();
        $noms = $comptes->map(fn (User $compte): string => $compte->name)->implode(', ');

        if ($proprietaire !== null && ! $proprietaire->is($auteur)) {
            $proprietaire->notify(new AvisDuCabinetEnFile(
                $client,
                'Votre expert-comptable a rejoint PILIQI',
                "Le cabinet {$cabinet->getFilamentName()} (SIREN {$cabinet->siren}) que vous aviez déclaré est inscrit et vérifié sur PILIQI : il suit désormais votre société depuis son espace, avec les autorisations que vous lui aviez accordées. Les comptes de {$noms} sont fermés. Si ce n'est pas votre expert-comptable, mettez fin au lien dans « Cabinet comptable » et prévenez l'éditeur de PILIQI.",
                CabinetComptable::getUrl(panel: 'societe', tenant: $client),
                'Voir le cabinet comptable',
                AvisDuCabinetEnFile::SUCCES,
                parCourriel: true,
            ));
        }

        $cabinet->proprietaire()->first()?->notify(new AvisDuCabinetEnFile(
            $cabinet,
            'Nouveau client relié',
            "{$client->getFilamentName()} (SIREN {$client->siren}) avait déclaré votre cabinet comme son expert-comptable : elle est désormais votre cliente liée, avec les autorisations qu'elle vous accorde. Confiez-la à vos collaborateurs.",
            ClientDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet),
            'Voir vos clients',
            AvisDuCabinetEnFile::SUCCES,
            parCourriel: true,
            clientId: CleDuModele::de($client),
        ));

        foreach ($comptes as $compte) {
            $adresse = User::query()->whereKey($compte->getKey())->value('email');

            if (is_string($adresse) && $adresse !== '') {
                Mail::to($adresse)->queue(new CompteDExpertComptableFermeMail($compte->name, $client->getFilamentName(), $cabinet->getFilamentName()));
            }
        }
    }
}
