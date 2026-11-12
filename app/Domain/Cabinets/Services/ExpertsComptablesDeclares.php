<?php

namespace App\Domain\Cabinets\Services;

use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as RequeteSql;
use Illuminate\Support\Facades\DB;

/**
 * Ce que l'on sait de l'expert-comptable d'une société, déclaré par le
 * SIREN de son cabinet (voir DeclarerSonExpertComptableAction) : sa
 * déclaration, une ligne de liens_cabinet sans cabinet, d'état declare
 * tant qu'aucun cabinet vérifié de son SIREN ne s'y est rattaché, et ses
 * comptes, des comptes de la société au rôle expert_comptable, attachés à
 * elle pour toujours (voir CompteExpertComptable).
 *
 * Lectures hors de la société active, chacune filtrée et limitée aux
 * colonnes qu'elle lit :
 * - la déclaration d'un compte (declarationDe()) et ses autorisations, dans
 *   la société du compte, lues aussi hors de tout contexte (connexion,
 *   ouverture d'un magasin, nom figé du compte) ;
 * - quand un cabinet devient vérifié, et chaque heure : le cabinet vérifié
 *   d'un SIREN (cabinetVerifieDuSiren()) et les SIREN des déclarations en
 *   attente dont le cabinet est vérifié (sirensAvecUnCabinetVerifie()).
 * Aucune lecture ne mêle deux sociétés : un compte d'expert-comptable
 * n'ouvre que les magasins réels de sa société.
 */
class ExpertsComptablesDeclares
{
    public function __construct(private readonly SessionsDesComptes $sessions) {}

    /**
     * La déclaration à laquelle ce compte est attaché, quel que soit son
     * état, lue dans la société du compte, sans scope : null pour un compte
     * qui n'est pas un expert-comptable de la société, ou sans attachement.
     */
    public function declarationDe(User $compte): ?LienCabinet
    {
        if ($compte->role_societe !== RoleSociete::ExpertComptable) {
            return null;
        }

        return LienCabinet::query()->withoutGlobalScope(SocieteScope::class)
            ->where('liens_cabinet.societe_id', $compte->societe_id)
            ->whereIn('liens_cabinet.id', fn (RequeteSql $attachement) => $attachement
                ->from('comptes_expert_comptable')
                ->where('societe_id', $compte->societe_id)
                ->where('user_id', $compte->getKey())
                ->select('lien_id'))
            ->first(['id', 'uuid', 'societe_id', 'etat', 'origine', 'siren_cabinet', 'denomination_cabinet', 'tous_les_magasins']);
    }

    /**
     * La ligne d'attachement de ce compte à sa déclaration, dans sa société.
     */
    public function attachementDe(User $compte): ?CompteExpertComptable
    {
        return CompteExpertComptable::query()->withoutGlobalScope(SocieteScope::class)
            ->where('societe_id', $compte->societe_id)
            ->where('user_id', $compte->getKey())
            ->first();
    }

    /**
     * Les comptes non retirés de cette déclaration, par identifiant
     * croissant, avec leur société ; sans les suspendus si demandé.
     *
     * @return Collection<int, User>
     */
    public function comptesDe(LienCabinet $declaration, bool $avecLesSuspendus = true): Collection
    {
        return User::query()
            ->with('societe')
            ->where('societe_id', $declaration->societe_id)
            ->where('role_societe', RoleSociete::ExpertComptable->value)
            ->where('statut', '<>', StatutCompte::Retire->value)
            ->when(! $avecLesSuspendus, fn (Builder $comptes) => $comptes->where('statut', StatutCompte::Actif->value))
            ->whereIn('id', fn (RequeteSql $attaches) => $attaches
                ->from('comptes_expert_comptable')
                ->where('societe_id', $declaration->societe_id)
                ->where('lien_id', $declaration->getKey())
                ->select('user_id'))
            ->orderBy('id')
            ->get();
    }

    /**
     * Une de ses personnes a-t-elle encore un compte actif ? Sans elle, une
     * déclaration ne se rattache pas (voir RattacherUneDeclarationAction).
     */
    public function aUnCompteActif(LienCabinet $declaration): bool
    {
        return $this->comptesDe($declaration, avecLesSuspendus: false)->isNotEmpty();
    }

    /**
     * Les magasins réels de sa société que la déclaration de ce compte lui
     * ouvre, par identifiant croissant : tous, présents et futurs, ou ceux
     * que le propriétaire a choisis, tant que la déclaration est en
     * attente ; aucun sinon, ni pour un compte retiré. Jamais le magasin de
     * démonstration.
     *
     * @return list<int>
     */
    public function idsDesMagasinsOuverts(User $compte): array
    {
        $declaration = $compte->estRetire() ? null : $this->declarationDe($compte);

        if ($declaration === null || $declaration->etat !== EtatDuLien::Declare) {
            return [];
        }

        return ValeursLues::entiers(Magasin::query()
            ->where('societe_id', $compte->societe_id)
            ->where('est_demonstration', false)
            ->when(! $declaration->tous_les_magasins, fn (Builder $magasins) => $magasins->whereIn('id', fn (RequeteSql $choisis) => $choisis
                ->from('magasins_cabinet')
                ->where('societe_id', $compte->societe_id)
                ->where('lien_id', $declaration->getKey())
                ->select('magasin_id')))
            ->orderBy('id')
            ->pluck('id'));
    }

    /**
     * Les autorisations que la déclaration de ce compte porte dans ce
     * magasin (voir LienCabinet::autorisationsRetenues()) ; null pour le
     * magasin de démonstration, un magasin d'une autre société ou que la
     * déclaration n'ouvre pas, un compte retiré ou une déclaration qui
     * n'est plus en attente.
     *
     * @return list<string>|null
     */
    public function autorisationsEnregistrees(User $compte, Magasin $magasin): ?array
    {
        if ($magasin->societe_id !== $compte->societe_id || ! in_array(CleDuModele::de($magasin), $this->idsDesMagasinsOuverts($compte), true)) {
            return null;
        }

        return $this->declarationDe($compte)?->autorisationsRetenues();
    }

    /**
     * Verrouille, avant les comptes, la ligne de la société de ce compte
     * (« for no key update ») puis sa déclaration (FOR UPDATE), dans l'ordre
     * de tout geste sur les liens (voir JournalDesDroits::modifierLeLien()) :
     * tout geste qui rouvre un compte d'expert-comptable tient ainsi sa
     * déclaration, que la garde de la base lit en partage.
     */
    public function verrouillerLaDeclarationDe(User $compte): void
    {
        Societe::query()->whereKey($compte->societe_id)->lock('for no key update')->first(['id']);
        DB::table('liens_cabinet')
            ->where('societe_id', $compte->societe_id)
            ->whereIn('id', fn (RequeteSql $attachement) => $attachement
                ->from('comptes_expert_comptable')
                ->where('societe_id', $compte->societe_id)
                ->where('user_id', $compte->getKey())
                ->select('lien_id'))
            ->lockForUpdate()
            ->first(['id']);
    }

    /**
     * Retire, dans la modification en cours, chaque compte non retiré de
     * cette déclaration, déjà tenu par l'appelant : ses droits supprimés,
     * le lien de son mot de passe effacé, ses avis effacés, ses sessions
     * fermées ; son historique reste. L'auteur est le propriétaire de la
     * société qui retire, remplace ou rattache la déclaration, ou personne
     * (le système, l'éditeur). Rend les comptes retirés.
     *
     * @return Collection<int, User>
     */
    public function retirerLesComptes(LienCabinet $declaration, ?User $auteur): Collection
    {
        $comptes = $this->comptesDe($declaration);

        foreach ($comptes as $compte) {
            ChangerStatutCompteAction::retirerLesDroits($compte);
            $compte->forceFill($compte->nouveauStatut(StatutCompte::Retire, $auteur));
            $this->sessions->fermerAvantDEnregistrer($compte);
            $compte->save();
            $compte->effacerToutesSesNotifications();
        }

        return $comptes;
    }

    /**
     * Le cabinet vérifié de ce SIREN (voir Societe::scopeCabinetsVerifies()),
     * lu hors de toute société, ses seules colonnes nécessaires ; null s'il
     * n'y en a pas.
     */
    public function cabinetVerifieDuSiren(string $siren): ?Societe
    {
        return Societe::query()->cabinetsVerifies()->where('societes.siren', $siren)
            ->first(['societes.id', 'societes.siren', 'societes.type_activite', 'societes.validee_le', 'societes.statut']);
    }

    /**
     * Les SIREN distincts des déclarations en attente dont le cabinet est
     * vérifié, sans rien d'autre de ces déclarations : la tâche horaire
     * rattrape ainsi un rattachement manqué (voir
     * RattacherLesExpertsComptables).
     *
     * @return list<string>
     */
    public function sirensAvecUnCabinetVerifie(): array
    {
        return ValeursLues::textes(DB::table('liens_cabinet')
            ->where('etat', EtatDuLien::Declare->value)
            ->whereIn('siren_cabinet', Societe::query()->cabinetsVerifies()->select('societes.siren'))
            ->distinct()
            ->orderBy('siren_cabinet')
            ->pluck('siren_cabinet'));
    }

    /**
     * Le type d'activité d'une société qui déclare : jamais un cabinet.
     */
    public static function peutDeclarer(TypeDActivite $type): bool
    {
        return $type !== TypeDActivite::Cabinet;
    }
}
