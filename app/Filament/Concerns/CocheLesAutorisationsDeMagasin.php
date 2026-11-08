<?php

namespace App\Filament\Concerns;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;

/**
 * Les cases d'un compte dans un magasin, celles du type d'activité de la
 * société du panneau seulement (voir PermissionRegistry::pourLeType() ; la
 * société de l'espace d'administration, ou celle du magasin), en trois
 * groupes : celles qui ne sont pas des ventes (voir
 * PermissionRegistry::horsVente()), puis, à part, celles de la vente (voir
 * PermissionRegistry::deVente()), que le propriétaire coche pour qui il
 * veut sans les exercer lui-même, et celles que seul le propriétaire coche
 * (voir PermissionRegistry::cocheesParLeProprietaire()), montrées à lui
 * seul, pour tout rôle, responsable compris, jamais précochées. Le
 * formulaire garde chaque groupe sous son nom ($nom, $nom_vente, puis
 * $nom_proprietaire) ; l'action reçoit leur réunion (voir
 * autorisationsSaisies()), et refuse elle-même ce que l'auteur ne peut pas
 * cocher ; un groupe qu'il ne voit pas n'est pas envoyé, et l'action garde
 * alors les cases que seul le propriétaire coche (voir
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
                ->label('Réservée au propriétaire')
                ->helperText('Vous seul la cochez, compte par compte : elle n\'est jamais précochée, pas même pour un responsable ; ce compte ne télécharge que les documents qu\'il peut voir.')
                ->options(fn (): array => self::libellesDe(self::cocheesParLeProprietaire()))
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
        return array_values(array_unique([...array_values($data[$nom] ?? []), ...array_values($data["{$nom}_vente"] ?? []), ...array_values($data["{$nom}_proprietaire"] ?? [])]));
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
     * Celles que seul le propriétaire coche dans le type d'activité de la
     * société du panneau.
     *
     * @return list<string>
     */
    private static function cocheesParLeProprietaire(): array
    {
        return PermissionRegistry::cocheesParLeProprietaire(self::typeDuPanneau());
    }

    private static function typeDuPanneau(): TypeDActivite
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Societe ? $tenant->type_activite : app(TenantContext::class)->magasin()->societe->type_activite;
    }
}
