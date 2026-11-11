<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as RequeteSql;
use Illuminate\Support\Facades\DB;

/**
 * L'accès des comptes d'un cabinet d'expertise comptable aux magasins de
 * ses clients (voir CLAUDE.md, « Cabinets d'expertise comptable », et les
 * lectures permises de « Produit ») : le propriétaire du cabinet ouvre
 * chaque client lié, un collaborateur ceux que le propriétaire lui confie,
 * dans leurs magasins réels, présents et futurs, jamais la démonstration,
 * avec les seules autorisations que le client accorde au cabinet ; rien
 * tant que le cabinet n'est pas vérifié (voir Societe::estUnCabinetVerifie()).
 *
 * Sans état. Chaque requête retire SocieteScope et filtre explicitement
 * par le cabinet, le client ou le compte : elles se lisent hors de tout
 * contexte (la connexion, canAccessTenant()) comme dans le contexte d'un
 * client, et ne passent jamais par le SocieteScope de l'un ou de l'autre.
 */
class AccesDesCabinets
{
    /**
     * Les liens actifs du cabinet de ce compte, confiés à ce compte pour un
     * collaborateur, sans regarder l'état du cabinet : les droits
     * enregistrés d'un compte (journal des événements, droits implicites)
     * ne changent pas avec la vérification du cabinet, comme ceux d'un
     * compte ne changent pas avec la lecture seule de son magasin. Rien
     * pour un compte retiré ou qui n'est pas d'un cabinet.
     *
     * @return Builder<LienCabinet>
     */
    public function liensDuCompte(User $compte): Builder
    {
        $liens = LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('liens_cabinet.cabinet_id', $compte->societe_id)
            ->where('liens_cabinet.etat', EtatDuLien::Actif->value);

        if (! $compte->estDUnCabinet() || $compte->estRetire()) {
            return $liens->whereRaw('1 = 0');
        }

        return $compte->estProprietaire()
            ? $liens
            : $liens->whereIn('liens_cabinet.id', fn (RequeteSql $confies) => $confies
                ->from('clients_confies')
                ->where('societe_id', $compte->societe_id)
                ->where('user_id', $compte->getKey())
                ->select('lien_id'));
    }

    /**
     * Ce que le compte voit et ouvre : ses liens (voir liensDuCompte())
     * quand son cabinet est vérifié et actif, aucun sinon.
     *
     * @return Builder<LienCabinet>
     */
    public function liensOuverts(User $compte): Builder
    {
        $liens = $this->liensDuCompte($compte);

        return $compte->societe->estUnCabinetVerifie() ? $liens : $liens->whereRaw('1 = 0');
    }

    /**
     * Pour le seul propriétaire d'un cabinet qui n'est plus vérifié, ses
     * liens actifs, avec la dénomination et le SIREN de chaque client
     * (relation client), pour y mettre fin ; rien d'autre du client.
     *
     * @return Collection<int, LienCabinet>
     */
    public function liensActifsDuCabinet(Societe $cabinet): Collection
    {
        return LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('cabinet_id', $cabinet->getKey())
            ->where('etat', EtatDuLien::Actif->value)
            ->with(['client' => fn ($client) => $client->select(['id', 'siren', 'raison_sociale'])])
            ->orderBy('id')
            ->get(['id', 'uuid', 'societe_id', 'cabinet_id', 'actif_depuis']);
    }

    /**
     * Les liens actifs de ce cabinet, avec, de chaque client, de quoi
     * prévenir son propriétaire que l'accès du cabinet s'arrête ou revient
     * (voir PrevenirLesClientsDuCabinet) : la dénomination, le SIREN et
     * l'uuid de la société (relation client) et son propriétaire, lus d'un
     * coup quel que soit le nombre de clients ; rien d'autre du client.
     *
     * @return Collection<int, LienCabinet>
     */
    public function clientsAPrevenir(Societe $cabinet): Collection
    {
        return LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('cabinet_id', $cabinet->getKey())
            ->where('etat', EtatDuLien::Actif->value)
            ->with(['client' => fn ($client) => $client->select(['id', 'uuid', 'siren', 'raison_sociale']), 'client.proprietaire'])
            ->orderBy('id')
            ->get(['id', 'societe_id', 'cabinet_id']);
    }

    /**
     * Les liens de ce cabinet dans ces états, pour une procédure de
     * l'éditeur qui leur met fin (sa révocation, le constat de sa radiation
     * de l'Ordre) : lecture hors du cabinet courant, filtrée par ce cabinet,
     * limitée à l'identifiant, au client, au cabinet, à l'état et à
     * l'origine de chacun, rien d'autre du client ; la fin de chacun le
     * relit sous verrou (voir JournalDesDroits::modifierLeLien()).
     *
     * @param  list<EtatDuLien>  $etats
     * @return Collection<int, LienCabinet>
     */
    public function liensAMettreFin(Societe $cabinet, array $etats): Collection
    {
        return LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('cabinet_id', $cabinet->getKey())
            ->whereIn('etat', $etats)
            ->orderBy('id')
            ->get(['id', 'societe_id', 'cabinet_id', 'etat', 'origine']);
    }

    /**
     * Les sociétés clientes que lui ouvrent ses liens (voir liensOuverts()),
     * par identifiant : il ouvre leurs magasins réels (voir
     * User::peutOuvrir()).
     *
     * @return list<int>
     */
    public function clientsOuverts(User $compte): array
    {
        return ValeursLues::entiers($this->liensOuverts($compte)->pluck('liens_cabinet.societe_id'));
    }

    /**
     * Les magasins réels des clients que le compte ouvre, actifs ou non,
     * par dénomination du client puis nom du magasin.
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsOuverts(User $compte): Collection
    {
        return $this->magasinsDes($this->liensOuverts($compte));
    }

    /**
     * Les magasins réels des clients de ses liens, sans regarder l'état du
     * cabinet (voir liensDuCompte()).
     *
     * @return Collection<int, Magasin>
     */
    public function magasinsDuCompte(User $compte): Collection
    {
        return $this->magasinsDes($this->liensDuCompte($compte));
    }

    /**
     * Les autorisations que le compte tient chez chaque client de ses liens
     * (voir liensDuCompte()), sans regarder l'état du cabinet : celles que
     * le client accorde au cabinet par le lien actif, croisées avec celles
     * qu'un cabinet peut recevoir d'un client de ce type (voir
     * PermissionRegistry::duCabinet()), triées, par identifiant de la
     * société cliente ; elles valent dans chacun de ses magasins réels.
     * Aucun client pour un compte retiré ou sans lien actif confié. Trois
     * lectures quel que soit le nombre de clients, chacune filtrée par les
     * liens du compte.
     *
     * @return array<int, list<string>>
     */
    public function autorisationsParClient(User $compte): array
    {
        $liens = $this->liensDuCompte($compte)->get(['liens_cabinet.id', 'liens_cabinet.societe_id']);

        if ($liens->isEmpty()) {
            return [];
        }

        $clients = ValeursLues::entiers($liens->pluck('societe_id'));
        $accordees = DB::table('autorisations_cabinet')
            ->whereIn('societe_id', $clients)
            ->whereIn('lien_id', $liens->modelKeys())
            ->orderBy('autorisation')
            ->get(['lien_id', 'autorisation'])
            ->groupBy('lien_id');
        $types = Societe::query()->whereKey($clients)->get(['id', 'type_activite'])->keyBy('id');
        $parClient = [];

        foreach ($liens as $lien) {
            $retenues = array_values(array_intersect(
                ValeursLues::textes($accordees->get(CleDuModele::de($lien), collect())->pluck('autorisation')),
                PermissionRegistry::duCabinet(ValeurRequise::de($types->get($lien->societe_id), 'la société d\'un client lié')->type_activite),
            ));
            sort($retenues);
            $parClient[(int) $lien->societe_id] = $retenues;
        }

        return $parClient;
    }

    /**
     * Les comptes du cabinet qui ont accès au client de ce lien : son
     * propriétaire et les collaborateurs à qui il est confié, jamais un
     * compte retiré ; sans les suspendus si on le demande.
     *
     * @return Collection<int, User>
     */
    public function comptesQuiOntAcces(LienCabinet $lien, bool $avecLesSuspendus = true): Collection
    {
        return User::query()
            ->with('societe')
            ->where('societe_id', $lien->cabinet_id)
            ->where('statut', '<>', StatutCompte::Retire->value)
            ->when(! $avecLesSuspendus, fn (Builder $comptes) => $comptes->where('statut', StatutCompte::Actif->value))
            ->where(fn (Builder $comptes) => $comptes
                ->where('role_societe', 'proprietaire')
                ->orWhereIn('id', fn (RequeteSql $confies) => $confies
                    ->from('clients_confies')
                    ->where('societe_id', $lien->cabinet_id)
                    ->where('lien_id', $lien->getKey())
                    ->select('user_id')))
            ->orderBy('id')
            ->get();
    }

    /**
     * Efface les clients confiés de ce lien, depuis le contexte du client
     * (sa fin, son remplacement) : une écriture dans la table du cabinet,
     * filtrée par le cabinet du lien et le lien.
     */
    public function effacerLesClientsConfies(LienCabinet $lien): void
    {
        DB::table('clients_confies')->where('societe_id', $lien->cabinet_id)->where('lien_id', $lien->getKey())->delete();
    }

    /**
     * @param  Builder<LienCabinet>  $liens
     * @return Collection<int, Magasin>
     */
    private function magasinsDes(Builder $liens): Collection
    {
        return Magasin::query()
            ->with('societe')
            ->whereIn('magasins.societe_id', $liens->select('liens_cabinet.societe_id'))
            ->where('magasins.est_demonstration', false)
            ->join('societes', 'societes.id', '=', 'magasins.societe_id')
            ->orderBy('societes.raison_sociale')
            ->orderBy('magasins.nom')
            ->orderBy('magasins.id')
            ->get(['magasins.*']);
    }
}
