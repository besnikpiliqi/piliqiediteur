<?php

namespace App\Filament\Concerns;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Get;
use UnexpectedValueException;

/**
 * Les cases d'un compte dans un magasin, celles du type d'activité de la
 * société du panneau seulement (voir PermissionRegistry::pourLeType() ; la
 * société de l'espace d'administration, ou celle du magasin), en trois
 * groupes : celles qui ne sont pas des ventes (voir
 * PermissionRegistry::horsVente()), puis, à part, celles de la vente (voir
 * PermissionRegistry::deVente()), que le propriétaire coche pour qui il
 * veut sans les exercer lui-même, et celles que le propriétaire est seul à cocher
 * (voir PermissionRegistry::cocheesParLeProprietaire()), montrées à lui
 * seul, pour tout rôle, responsable compris, jamais précochées, sans les
 * graphiques pour un vendeur ou un observateur (voir
 * PermissionRegistry::refuseesAuxVendeursEtObservateurs()). Le
 * formulaire garde chaque groupe sous son nom ($nom, $nom_vente, puis
 * $nom_proprietaire) ; l'action reçoit leur réunion (voir
 * autorisationsSaisies()), et refuse elle-même ce que l'auteur ne peut pas
 * cocher ; un groupe qu'il ne voit pas n'est pas envoyé, et l'action garde
 * alors les cases que le propriétaire est seul à cocher (voir
 * AffectationsDesMembres::cases()).
 */
trait CocheLesAutorisationsDeMagasin
{
    /**
     * Les trois groupes de cases, chacun sous son libellé, les deux
     * premiers masqués ensemble quand $masque est vrai (pour un
     * responsable), le troisième montré au propriétaire seul ; la fenêtre
     * qui les montre les remplit (voir autorisationsAAfficher()).
     *
     * @return list<CheckboxList>
     */
    public static function champsDesAutorisationsDeMagasin(string $nom, string $libelle, ?string $aide, string $libelleVente, string $aideVente, ?Closure $masque = null): array
    {
        return [
            CheckboxList::make($nom)
                ->label($libelle)
                ->helperText($aide)
                ->options(fn (): array => self::libellesDe(array_diff(PermissionRegistry::horsVente(), self::cocheesParLeProprietaire())))
                ->columns(2)
                ->bulkToggleable()
                ->hidden($masque ?? false)
                ->columnSpanFull(),
            CheckboxList::make("{$nom}_vente")
                ->label($libelleVente)
                ->helperText($aideVente)
                ->options(fn (): array => self::libellesDe(PermissionRegistry::deVente()))
                ->columns(2)
                ->hidden($masque ?? false)
                ->columnSpanFull(),
            CheckboxList::make("{$nom}_proprietaire")
                ->label('Réservées au propriétaire')
                ->helperText('Vous seul les cochez, compte par compte : elles ne sont jamais précochées, pas même pour un responsable ; ce compte ne télécharge et ne voit en graphiques que les documents qu\'il peut voir. Les graphiques ne se cochent qu\'à un responsable ou à un administrateur.')
                ->options(fn (Get $get): array => self::libellesDe(self::reserveesAuProprietairePourLeRole($get('role'))))
                ->columns(2)
                ->visible(fn (): bool => auth()->user()?->estProprietaire() ?? false)
                ->columnSpanFull(),
        ];
    }

    /**
     * Les cases cochées dans les trois groupes, réunies, sans doublon.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function autorisationsSaisies(array $data, string $nom): array
    {
        return array_values(array_unique([...self::casesCochees($data, $nom), ...self::casesCochees($data, "{$nom}_vente"), ...self::casesCochees($data, "{$nom}_proprietaire")]));
    }

    /**
     * Les cases cochées de ce champ, dans leur ordre ; aucune quand le
     * champ manque (un groupe que le formulaire ne montre pas).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     *
     * @throws UnexpectedValueException un champ qui n'est pas une liste de cases
     */
    public static function casesCochees(array $data, string $champ): array
    {
        return array_values(array_map(ValeurSimple::enTexte(...), ValeursLues::tableau($data[$champ] ?? [])));
    }

    /**
     * Ces cases réparties entre les trois groupes, pour remplir le
     * formulaire.
     *
     * @param  list<string>  $autorisations
     * @return array<string, list<string>>
     */
    public static function autorisationsAAfficher(array $autorisations, string $nom): array
    {
        return [
            $nom => array_values(array_intersect($autorisations, array_diff(PermissionRegistry::horsVente(), self::cocheesParLeProprietaire()))),
            "{$nom}_vente" => array_values(array_intersect($autorisations, PermissionRegistry::deVente())),
            "{$nom}_proprietaire" => array_values(array_intersect($autorisations, self::cocheesParLeProprietaire())),
        ];
    }

    /**
     * Les libellés de celles de ces autorisations qui existent dans le type
     * d'activité de la société du panneau, dans l'ordre du registre.
     *
     * @param  array<array-key, string>  $autorisations
     * @return array<string, string>
     */
    private static function libellesDe(array $autorisations): array
    {
        return array_intersect_key(PermissionRegistry::libelles(), array_flip(array_intersect($autorisations, PermissionRegistry::pourLeType(self::typeDuPanneau()))));
    }

    /**
     * Celles que le propriétaire est seul à cocher dans le type d'activité de la
     * société du panneau.
     *
     * @return list<string>
     */
    private static function cocheesParLeProprietaire(): array
    {
        return PermissionRegistry::cocheesParLeProprietaire(self::typeDuPanneau());
    }

    /**
     * Celles que le propriétaire est seul à cocher pour le rôle choisi dans
     * la fenêtre : toutes pour un responsable et un administrateur, dont
     * la fenêtre n'a pas de rôle de magasin ; sans les graphiques pour un
     * vendeur ou un observateur, à qui elles ne se cochent jamais (voir
     * PermissionRegistry::refuseesAuxVendeursEtObservateurs()).
     *
     * @return list<string>
     */
    private static function reserveesAuProprietairePourLeRole(mixed $role): array
    {
        $role = $role instanceof RoleMagasin ? $role : RoleMagasin::tryFrom(is_string($role) ? $role : '');

        return $role !== null && $role->aDesCases()
            ? array_values(array_diff(self::cocheesParLeProprietaire(), PermissionRegistry::refuseesAuxVendeursEtObservateurs()))
            : self::cocheesParLeProprietaire();
    }

    private static function typeDuPanneau(): TypeDActivite
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Societe ? $tenant->type_activite : app(TenantContext::class)->magasin()->societe->type_activite;
    }
}
