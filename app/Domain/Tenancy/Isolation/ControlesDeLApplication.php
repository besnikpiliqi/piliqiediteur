<?php

namespace App\Domain\Tenancy\Isolation;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Charges\Models\Charge;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use ReflectionClass;

/**
 * Les contrôles de l'isolation par l'application (couche « Laravel » de
 * VerificationDeLIsolation) :
 * - chaque modèle d'une table d'un magasin utilise BelongsToMagasin et
 *   MagasinScope, chaque modèle d'une table d'une société BelongsToSociete et
 *   SocieteScope, sauf les exceptions motivées de ReglesDIsolation (lu par
 *   réflexion sur tous les modèles d'app/) ;
 * - pour chaque compte, la vue que les modèles et les règles d'accès de
 *   PILIQI lui donnent (magasins qu'il ouvre, comme à sa connexion, puis,
 *   dans chacun, comme magasin actif, ses clients, produits, ventes, avoirs,
 *   paiements, devis, achats, clôtures et relevés, et ses notifications,
 *   charges et téléchargements) est comparée à ce qu'une requête SQL directe
 *   dit qu'il doit voir, à partir des affectations, des cases et des liens
 *   des cabinets : une ligne vue de trop (fuite) ou une ligne due qui manque
 *   est un désaccord entre Laravel et la base.
 * Rien ne s'écrit : chaque magasin s'ouvre le temps de ses lectures (voir
 * TenantContext::runAs()), et les droits que le compte a gardés en mémoire
 * s'oublient à chaque magasin.
 */
final class ControlesDeLApplication
{
    public const COUCHE = 'Laravel';

    public const COUCHES_EN_DESACCORD = 'Laravel et SQL';

    public const DESACCORD = 'désaccord entre Laravel et la base';

    /**
     * Les modèles du registre du site de démonstration (lot DEMO-COPIE,
     * C.1) : ils lisent une base à part (connexion registre), sans aucune
     * donnée d'une société, et n'entrent pas dans la vérification de
     * l'isolation d'une base de PILIQI.
     */
    public const MODELES_DU_REGISTRE = 'App\\Domain\\Demonstration\\Registre\\';

    public function __construct(
        private readonly ConnectionInterface $base,
        private readonly TenantContext $contexte,
    ) {}

    /**
     * @return list<ResultatDUnControle>
     */
    public function tous(): array
    {
        return [$this->porteeDesModeles(), $this->vueDeChaqueCompte()];
    }

    /**
     * Chaque modèle d'app/ est d'une table qui a sa règle, et porte le trait
     * et le scope de sa portée, ou figure parmi les exceptions motivées ; une
     * exception dont le modèle porte désormais son scope est devenue inutile.
     * Les modèles lus sont ceux d'app/ (voir modeles()), ou ceux donnés.
     *
     * @param  list<class-string<Model>>|null  $modeles
     */
    public function porteeDesModeles(?array $modeles = null): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Portée des modèles', self::COUCHE);

        foreach ($modeles ?? self::modeles() as $classe) {
            $resultat->compter(1);
            $table = (new $classe)->getTable();
            $portee = ReglesDIsolation::porteeConnue($table);
            $traits = class_uses_recursive($classe);
            $attendus = match ($portee) {
                ReglesDIsolation::MAGASIN => [BelongsToMagasin::class, MagasinScope::class],
                ReglesDIsolation::SOCIETE => [BelongsToSociete::class, SocieteScope::class],
                default => null,
            };

            if ($portee === null) {
                $resultat->signaler($table, $classe, '—', 'une table qui a sa règle d\'isolation', 'une table sans règle', ReglesDIsolation::REGLE_TABLES);

                continue;
            }

            if ($attendus === null) {
                continue;
            }

            [$trait, $scope] = $attendus;
            $porteSonScope = in_array($trait, $traits, true) && $classe::hasGlobalScope($scope);
            $exception = ReglesDIsolation::MODELES_SANS_SCOPE[$classe] ?? null;

            if (! $porteSonScope && $exception === null) {
                $resultat->signaler($table, $classe, '—', class_basename($trait).' et '.class_basename($scope), 'un modèle sans le scope de sa portée', $portee === ReglesDIsolation::MAGASIN ? ReglesDIsolation::REGLE_MAGASINS : ReglesDIsolation::REGLE_SOCIETES);
            }

            if ($porteSonScope && $exception !== null) {
                $resultat->signaler($table, $classe, '—', 'aucune exception pour un modèle qui porte son scope', 'une exception devenue inutile', ReglesDIsolation::REGLE_TABLES);
            }
        }

        return $resultat;
    }

    /**
     * Pour chaque compte de chaque société : ses magasins, puis ce qu'il voit
     * dans chacun, et ses lignes de société, comparés à ce que la base dit
     * qu'il doit voir.
     */
    public function vueDeChaqueCompte(): ResultatDUnControle
    {
        $resultat = new ResultatDUnControle('Ce que chaque compte voit', self::COUCHE);

        User::query()->with('societe')->orderBy('id')->chunkById(100, function ($comptes) use ($resultat): void {
            foreach ($comptes as $compte) {
                $this->controlerLeCompte($resultat, ValeursLues::instance($compte, User::class));
            }
        });

        return $resultat;
    }

    private function controlerLeCompte(ResultatDUnControle $resultat, User $compte): void
    {
        self::oublierLesDroits($compte);
        $vus = $compte->magasinsAccessibles();
        $attendus = $this->magasinsAttendus($compte);
        $this->comparer($resultat, 'magasins', $compte, null, ValeursLues::entiers($vus->modelKeys()), $attendus);

        foreach ($vus as $magasin) {
            if (! in_array(CleDuModele::de($magasin), $attendus, true)) {
                continue;
            }

            self::oublierLesDroits($compte);
            $this->contexte->runAs($magasin, function () use ($resultat, $compte, $magasin): void {
                foreach ($this->lecturesDuMagasin($compte, $magasin) as $table => [$laravel, $sql]) {
                    $this->comparer($resultat, $table, $compte, $magasin, ValeursLues::entiers($laravel()), $sql);
                }
            });
        }

        self::oublierLesDroits($compte);
        $notifications = ValeursLues::textes($compte->notifications()->pluck('id'));
        $notificationsAttendues = ValeursLues::textes(array_column(array_map(SchemaDeLaBase::champsDe(...), $this->base->select('select id from notifications where notifiable_type = ? and notifiable_id = ? order by id', [$compte->getMorphClass(), $compte->getKey()])), 'id'));
        $resultat->compter(count($notifications));

        sort($notifications);
        sort($notificationsAttendues);

        if ($notifications !== $notificationsAttendues) {
            $resultat->signaler('notifications', 'compte n° '.CleDuModele::de($compte), 'notifiable_id', count($notificationsAttendues).' notification(s) en base', count($notifications).' notification(s) lues ('.self::DESACCORD.')', ReglesDIsolation::REGLE_COMPTES, self::COUCHES_EN_DESACCORD);
        }

        $societe = $compte->societe;
        $this->contexte->runAsSociete($societe, function () use ($resultat, $compte): void {
            $this->comparer($resultat, 'charges', $compte, null, ValeursLues::entiers(Charge::query()->orderBy('id')->pluck('id')), $this->ids('select id from charges where societe_id = ? order by id', [$compte->societe_id]));
            $this->comparer($resultat, 'telechargements_documents', $compte, null, ValeursLues::entiers(TelechargementDeDocuments::query()->where('demande_par', $compte->getKey())->orderBy('id')->pluck('id')), $this->ids('select id from telechargements_documents where societe_id = ? and demande_par = ? order by id', [$compte->societe_id, CleDuModele::de($compte)]));
        });
    }

    /**
     * Oublie les droits que le compte a gardés en mémoire (voir
     * User::oublierLesDroits()) et relit sa société, que ses droits lisent.
     */
    private static function oublierLesDroits(User $compte): void
    {
        $compte->oublierLesDroits();
        $compte->load('societe');
    }

    /**
     * Ce que le compte lit dans ce magasin, magasin actif, par table : la
     * lecture des modèles et des règles d'accès de PILIQI (scopes, droits),
     * et les identifiants que la base dit qu'il doit voir. Les ventes, les
     * avoirs, les paiements, les clôtures du jour et les relevés de caisse
     * suivent le choix des écrans : tous avec « voir celles de toute
     * l'équipe », les siens sinon (voir FactureResource, AvoirResource,
     * PaiementResource, ClotureCaisseResource et
     * ClotureCaissePersonnelleResource).
     *
     * @return array<string, array{0: Closure(): iterable<mixed>, 1: list<int>}>
     */
    private function lecturesDuMagasin(User $compte, Magasin $magasin): array
    {
        $id = CleDuModele::de($magasin);
        $utilisateur = CleDuModele::de($compte);
        $toutes = $this->permis($compte, $magasin, PermissionRegistry::FACTURE_VIEW_TOUTES);
        $siennes = $this->permis($compte, $magasin, PermissionRegistry::FACTURE_VIEW);
        $avoirs = $this->permis($compte, $magasin, PermissionRegistry::AVOIR_VIEW);
        $relevesDeLEquipe = $this->permis($compte, $magasin, PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES);
        $sienOuTous = fn (bool $tous, string $table, string $colonne, bool $permis = true): array => ! $permis ? [] : $this->ids("select id from {$table} where magasin_id = ?".($tous ? '' : " and {$colonne} = ?").' order by id', $tous ? [$id] : [$id, $utilisateur]);
        $tousDuMagasin = fn (string $table): array => $this->ids("select id from {$table} where magasin_id = ? order by id", [$id]);
        $ids = static fn (Builder $requete): iterable => $requete->orderBy($requete->qualifyColumn('id'))->pluck($requete->qualifyColumn('id'));

        return [
            'clients' => [fn (): iterable => $ids(Client::query()), $tousDuMagasin('clients')],
            'produits' => [fn (): iterable => $ids(Produit::query()), $tousDuMagasin('produits')],
            'devis' => [fn (): iterable => $ids(Devis::query()), $tousDuMagasin('devis')],
            'factures_achat' => [fn (): iterable => $ids(FactureAchat::query()), $tousDuMagasin('factures_achat')],
            'factures' => [fn (): iterable => $ids(Facture::query()->visiblesPar($compte)), $sienOuTous($toutes, 'factures', 'cree_par', $toutes || $siennes)],
            'avoirs' => [fn (): iterable => $ids(Avoir::query()->visiblesPar($compte)), $sienOuTous($toutes, 'avoirs', 'cree_par', $avoirs)],
            'paiements' => [fn (): iterable => $ids($compte->can(PermissionRegistry::FACTURE_VIEW_TOUTES) ? Paiement::query() : Paiement::query()->where('cree_par', $utilisateur)), $sienOuTous($toutes, 'paiements', 'cree_par')],
            'clotures_caisse' => [fn (): iterable => $ids($compte->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) ? ClotureCaisse::query() : ClotureCaisse::query()->where('cree_par', $utilisateur)), $sienOuTous($relevesDeLEquipe, 'clotures_caisse', 'cree_par')],
            'clotures_caisse_personnelles' => [fn (): iterable => $ids($compte->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES) ? ClotureCaissePersonnelle::query() : ClotureCaissePersonnelle::query()->where('vendeur_id', $utilisateur)), $sienOuTous($relevesDeLEquipe, 'clotures_caisse_personnelles', 'vendeur_id')],
        ];
    }

    /**
     * Les magasins que la base dit que ce compte ouvre, par identifiant : rien
     * pour un compte qui n'est pas actif ; pour le propriétaire d'une société,
     * tous ses magasins ; pour un administrateur, ceux de sa portée (tous, ou
     * ceux de ses affectations) ; pour un membre, celui de son affectation ;
     * pour un compte d'un cabinet vérifié et actif, les magasins que les
     * clients de ses liens actifs (tous pour son propriétaire, ceux qui lui
     * sont confiés pour un collaborateur) lui ouvrent ; pour un expert-comptable
     * de la société, les magasins que sa déclaration en attente lui ouvre.
     *
     * @return list<int>
     */
    private function magasinsAttendus(User $compte): array
    {
        if ($compte->statut !== StatutCompte::Actif) {
            return [];
        }

        $id = CleDuModele::de($compte);
        $societe = (int) $compte->societe_id;
        $role = $compte->role_societe;
        $cabinet = TypeDActivite::Cabinet->value;
        $magasinsOuverts = '(l.tous_les_magasins or exists (select 1 from magasins_cabinet mc where mc.lien_id = l.id and mc.societe_id = l.societe_id and mc.magasin_id = m.id))';

        if ($compte->societe->type_activite === TypeDActivite::Cabinet) {
            $verifie = "c.type_activite = '{$cabinet}' and c.validee_le is not null and c.statut = '".StatutSociete::Active->value."' and exists (select 1 from cabinets v where v.societe_id = c.id and v.activite_controlee_le is not null and v.ordre_verifie_le is not null and v.ordre_retire_le is null)";
            $confie = $role === RoleSociete::Proprietaire ? 'true' : 'exists (select 1 from clients_confies cc where cc.lien_id = l.id and cc.user_id = ?)';

            return $this->ids("select distinct m.id from liens_cabinet l join societes c on c.id = l.cabinet_id join magasins m on m.societe_id = l.societe_id where l.cabinet_id = ? and l.etat = 'actif' and {$verifie} and {$confie} and {$magasinsOuverts} order by m.id", $role === RoleSociete::Proprietaire ? [$societe] : [$societe, $id]);
        }

        return match ($role) {
            RoleSociete::Proprietaire => $this->ids('select m.id from magasins m where m.societe_id = ? order by m.id', [$societe]),
            RoleSociete::Administrateur => $this->ids('select m.id from magasins m where m.societe_id = ? and (
                    (exists (select 1 from affectations a where a.user_id = ? and a.role is null and a.magasin_id is null) and not exists (select 1 from affectations a where a.user_id = ? and a.magasin_id is not null))
                    or (not exists (select 1 from affectations a where a.user_id = ? and a.magasin_id is null) and exists (select 1 from affectations a where a.user_id = ? and a.role is null and a.magasin_id = m.id))
                ) order by m.id', [$societe, $id, $id, $id, $id]),
            RoleSociete::Membre => $this->ids('select m.id from magasins m join affectations a on a.magasin_id = m.id and a.user_id = ? and a.role is not null where m.societe_id = ? order by m.id', [$id, $societe]),
            RoleSociete::ExpertComptable => $this->ids("select m.id from comptes_expert_comptable c join liens_cabinet l on l.id = c.lien_id and l.societe_id = c.societe_id join magasins m on m.societe_id = l.societe_id where c.user_id = ? and l.etat = 'declare' and {$magasinsOuverts} order by m.id", [$id]),
            default => [],
        };
    }

    /**
     * La base accorde-t-elle cette autorisation à ce compte dans ce magasin ?
     * Au propriétaire de la société du magasin et à un responsable de ce
     * magasin, toujours ; à un administrateur, un vendeur ou un observateur,
     * si la case est cochée sur son affectation (à ce magasin ou à tous) ; à
     * un compte d'un cabinet, si le client l'accorde au cabinet par un lien
     * actif qui lui ouvre ce magasin ; à un expert-comptable de la société, si
     * sa déclaration l'accorde.
     */
    private function permis(User $compte, Magasin $magasin, string $autorisation): bool
    {
        $id = CleDuModele::de($compte);
        $ligne = $this->base->selectOne(
            "select (
                exists (select 1 from users u join magasins m on m.id = ? where u.id = ? and u.role_societe = 'proprietaire' and u.societe_id = m.societe_id)
                or exists (select 1 from affectations a where a.user_id = ? and a.magasin_id = ? and a.role = ?)
                or exists (select 1 from affectations a join autorisations_affectations aa on aa.affectation_id = a.id where a.user_id = ? and (a.magasin_id = ? or (a.magasin_id is null and a.role is null)) and aa.autorisation = ?)
                or exists (select 1 from users u join liens_cabinet l on l.cabinet_id = u.societe_id and l.etat = 'actif' join magasins m on m.id = ? and m.societe_id = l.societe_id
                            join autorisations_cabinet ac on ac.lien_id = l.id and ac.societe_id = l.societe_id and ac.autorisation = ?
                            where u.id = ? and (u.role_societe = 'proprietaire' or exists (select 1 from clients_confies cc where cc.lien_id = l.id and cc.user_id = u.id)))
                or exists (select 1 from comptes_expert_comptable c join liens_cabinet l on l.id = c.lien_id and l.etat = 'declare'
                            join autorisations_cabinet ac on ac.lien_id = l.id and ac.societe_id = l.societe_id and ac.autorisation = ? where c.user_id = ?)
            ) as permis",
            [CleDuModele::de($magasin), $id, $id, CleDuModele::de($magasin), RoleMagasin::Responsable->value, $id, CleDuModele::de($magasin), $autorisation, CleDuModele::de($magasin), $autorisation, $id, $autorisation, $id],
        );

        return ValeursLues::champ(SchemaDeLaBase::champsDe($ligne), 'permis') === true;
    }

    /**
     * Compare ce que l'application montre au compte à ce que la base dit qu'il
     * doit voir.
     *
     * @param  list<int>  $vus
     * @param  list<int>  $attendus
     */
    private function comparer(ResultatDUnControle $resultat, string $table, User $compte, ?Magasin $magasin, array $vus, array $attendus): void
    {
        $resultat->compter(count($vus));
        $ou = 'compte n° '.CleDuModele::de($compte).($magasin === null ? '' : ', magasin n° '.CleDuModele::de($magasin));

        foreach (array_diff($vus, $attendus) as $ligne) {
            $resultat->signaler($table, "{$ou}, ligne n° {$ligne}", 'id', 'une ligne que la base ne lui donne pas à voir', 'une ligne vue de trop (fuite, '.self::DESACCORD.')', $table === 'magasins' ? ReglesDIsolation::REGLE_CABINETS : ReglesDIsolation::REGLE_MAGASINS, self::COUCHES_EN_DESACCORD);
        }

        foreach (array_diff($attendus, $vus) as $ligne) {
            $resultat->signaler($table, "{$ou}, ligne n° {$ligne}", 'id', 'une ligne que la base lui donne à voir', 'une ligne due qui manque ('.self::DESACCORD.')', ReglesDIsolation::REGLE_COMPTES, self::COUCHES_EN_DESACCORD);
        }
    }

    /**
     * @param  list<int|string>  $liaisons
     * @return list<int>
     */
    private function ids(string $requete, array $liaisons): array
    {
        return array_values(array_map(static fn (mixed $ligne): int => ValeursLues::entier(ValeursLues::champ(SchemaDeLaBase::champsDe($ligne), 'id')), $this->base->select($requete, $liaisons)));
    }

    /**
     * Les modèles Eloquent concrets d'app/, par nom de classe, lus dans les
     * fichiers d'app/ (PSR-4 : App\ ↔ app/).
     *
     * @return list<class-string<Model>>
     */
    public static function modeles(): array
    {
        $modeles = [];

        foreach (File::allFiles(app_path()) as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            $classe = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            if (str_starts_with($classe, self::MODELES_DU_REGISTRE)) {
                continue;
            }

            $modeles[] = $classe;
        }

        sort($modeles);

        return $modeles;
    }
}
