<?php

namespace App\Filament\Concerns;

use App\Domain\Authorization\PermissionRegistry;
use Closure;
use Filament\Forms\Components\CheckboxList;

/**
 * Les cases d'un compte dans un magasin, en deux groupes : celles qui ne
 * sont pas des ventes (voir PermissionRegistry::horsVente()), puis, à part,
 * celles de la vente (voir PermissionRegistry::deVente()), que le
 * propriétaire coche pour qui il veut sans les exercer lui-même. Le
 * formulaire garde chaque groupe sous son nom ($nom, puis $nom_vente) ;
 * l'action reçoit leur réunion (voir autorisationsSaisies()), et refuse
 * elle-même ce que l'auteur ne peut pas cocher.
 */
trait CocheLesAutorisationsDeMagasin
{
    /**
     * Les deux groupes de cases, chacun sous son libellé, masqués ensemble
     * quand $masque est vrai ; le premier précoche $precochees, le groupe
     * de la vente rien.
     *
     * @param  list<string>  $precochees
     * @return list<CheckboxList>
     */
    public static function champsDesAutorisationsDeMagasin(string $nom, string $libelle, ?string $aide, string $libelleVente, string $aideVente, ?Closure $masque = null, array $precochees = []): array
    {
        return [
            CheckboxList::make($nom)
                ->label($libelle)
                ->helperText($aide)
                ->options(self::libellesDe(PermissionRegistry::horsVente()))
                ->default(array_values(array_intersect($precochees, PermissionRegistry::horsVente())))
                ->columns(2)
                ->bulkToggleable()
                ->hidden($masque ?? false)
                ->columnSpanFull(),
            CheckboxList::make("{$nom}_vente")
                ->label($libelleVente)
                ->helperText($aideVente)
                ->options(self::libellesDe(PermissionRegistry::deVente()))
                ->columns(2)
                ->hidden($masque ?? false)
                ->columnSpanFull(),
        ];
    }

    /**
     * Les cases cochées dans les deux groupes, réunies, sans doublon.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function autorisationsSaisies(array $data, string $nom): array
    {
        return array_values(array_unique([...array_values($data[$nom] ?? []), ...array_values($data["{$nom}_vente"] ?? [])]));
    }

    /**
     * Ces cases réparties entre les deux groupes, pour remplir le
     * formulaire.
     *
     * @param  list<string>  $autorisations
     * @return array<string, list<string>>
     */
    public static function autorisationsAAfficher(array $autorisations, string $nom): array
    {
        return [
            $nom => array_values(array_intersect($autorisations, PermissionRegistry::horsVente())),
            "{$nom}_vente" => array_values(array_intersect($autorisations, PermissionRegistry::deVente())),
        ];
    }

    /**
     * Les libellés de ces autorisations, dans l'ordre du registre.
     *
     * @param  list<string>  $autorisations
     * @return array<string, string>
     */
    private static function libellesDe(array $autorisations): array
    {
        return array_intersect_key(PermissionRegistry::libelles(), array_flip($autorisations));
    }
}
