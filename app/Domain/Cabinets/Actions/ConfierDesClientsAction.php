<?php

namespace App\Domain\Cabinets\Actions;

use App\Domain\Cabinets\Models\ClientConfie;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Cabinet\Resources\ClientDuCabinetResource;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Le propriétaire d'un cabinet d'expertise comptable confie à un
 * collaborateur les clients liés qu'il suivra (voir
 * PorteeDesDroits::peutConfierDesClients()) : la liste complète des liens
 * confiés après le geste, qui peut être vide. Sous les verrous de
 * JournalDesDroits::modifier() : les registres des magasins réels des
 * clients des liens demandés et de ceux qu'on retire (une prise de
 * registres de plusieurs sociétés, voir
 * RegistreFiscal::sousVerrouDesMagasinsDesClients()), puis ces liens, puis
 * les comptes ; chaque lien demandé doit être actif et de ce cabinet. La
 * différence s'écrit (ajouts, retraits), au JET 130 de chaque magasin réel
 * touché et au journal d'audit du cabinet ; le collaborateur est prévenu,
 * client par client, et, pour un client qui ne lui est plus confié, ses
 * avis sur ce client s'effacent d'abord de sa cloche.
 */
class ConfierDesClientsAction
{
    use AgitDansLaSociete;

    public const MESSAGE_CLIENT_NON_LIE = 'Ce client n\'est plus lié à votre cabinet.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly AccesDesCabinets $acces,
    ) {}

    /**
     * @param  list<int>  $liens  les identifiants des liens confiés après le geste
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function handle(User $auteur, User $collaborateur, array $liens): void
    {
        $cabinet = Societe::query()->findOrFail($collaborateur->societe_id);
        $liens = array_values(array_unique(array_map(intval(...), $liens)));
        $changements = ['ajoutes' => [], 'retires' => []];

        $this->dansLaSociete($cabinet, CreerCollaborateurAction::MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL, function () use ($auteur, $collaborateur, $cabinet, $liens, &$changements): void {
            $actuels = DB::table('clients_confies')->where('societe_id', $cabinet->getKey())->where('user_id', $collaborateur->getKey())->pluck('lien_id')->map(fn ($id): int => (int) $id)->all();
            $concernes = array_values(array_unique([...$liens, ...$actuels]));
            $clients = LienCabinet::query()->duCabinet($cabinet)->whereKey($concernes)->pluck('societe_id')->unique()->values()->all();
            $magasinsVises = Magasin::query()->whereIn('societe_id', $clients)->where('est_demonstration', false)->orderBy('id')->get();

            $this->journalDesDroits->modifier($auteur, ActionAuditee::ClientsConfiesModifies, [$collaborateur], $magasinsVises, function (User $auteurRelu, array $relus) use ($cabinet, $liens, &$changements): void {
                [$collaborateurRelu] = $relus;

                if (! $this->portee->peutConfierDesClients($auteurRelu, $collaborateurRelu)) {
                    throw new AuthorizationException(CreerCollaborateurAction::MESSAGE_PROPRIETAIRE_DU_CABINET_SEUL);
                }

                $verrouilles = LienCabinet::query()->duCabinet($cabinet)->whereKey($liens)->get()->keyBy(fn (LienCabinet $lien): int => (int) $lien->getKey());

                foreach ($liens as $id) {
                    if (! ($verrouilles->get($id)?->estActif() ?? false)) {
                        throw new LogicException(self::MESSAGE_CLIENT_NON_LIE);
                    }
                }

                $actuels = ClientConfie::query()->where('user_id', $collaborateurRelu->getKey())->get();
                $retires = $actuels->reject(fn (ClientConfie $confie): bool => in_array((int) $confie->lien_id, $liens, true));
                $retires->each(fn (ClientConfie $confie) => ClientConfie::query()->whereKey($confie->getKey())->delete());

                foreach (array_diff($liens, $actuels->pluck('lien_id')->map(fn ($id): int => (int) $id)->all()) as $id) {
                    tap((new ClientConfie)->forceFill(['lien_id' => $id, 'user_id' => $collaborateurRelu->getKey(), 'confie_par' => $auteurRelu->getKey()]))->save();
                    $changements['ajoutes'][] = (int) $verrouilles->get($id)->societe_id;
                }

                $changements['retires'] = LienCabinet::query()->duCabinet($cabinet)->whereKey($retires->pluck('lien_id')->all())->pluck('societe_id')->map(fn ($id): int => (int) $id)->all();
            }, function () use ($cabinet, $concernes): void {
                LienCabinet::query()->duCabinet($cabinet)->whereKey($concernes)->orderBy('id')->lockForUpdate()->get();
            });
        });

        $this->prevenir($collaborateur->refresh(), $cabinet, $changements);
    }

    /**
     * @param  array{ajoutes: list<int>, retires: list<int>}  $changements
     */
    private function prevenir(User $collaborateur, Societe $cabinet, array $changements): void
    {
        $collaborateur->effacerLesAvisDesClients($changements['retires']);

        foreach ($changements['retires'] as $clientId) {
            $client = Societe::query()->findOrFail($clientId);
            $collaborateur->notify(new AvisDuCabinet($cabinet, 'Client retiré', "{$client->getFilamentName()} ne vous est plus confié."));
        }

        foreach ($changements['ajoutes'] as $clientId) {
            $client = Societe::query()->findOrFail($clientId);
            $collaborateur->notify(new AvisDuCabinet(
                $cabinet,
                "Client confié : {$client->getFilamentName()}",
                "Le propriétaire du cabinet vous confie {$client->getFilamentName()} : vous ouvrez ses magasins réels selon les autorisations qu'il accorde au cabinet.",
                ClientDuCabinetResource::getUrl('index', panel: 'cabinet', tenant: $cabinet),
                'Voir vos clients',
                clientId: $clientId,
            ));
        }
    }
}
