<?php

namespace App\Domain\Charges\Services;

use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;
use App\Domain\Charges\Models\TypeDeChargeDeLaSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Les types de charge d'une société (décision de l'éditeur du 14/11/2026,
 * lot CHARGES) : la liste de PILIQI (voir TypeDeCharge), puis ceux que la
 * société ajoute (voir TypeDeChargeDeLaSociete), chacun désigné par sa
 * clé, « piliqi:loyer » ou « societe:{uuid} ». Un type archivé n'est plus
 * proposé, mais reste celui de ses charges. Les types de la liste de
 * PILIQI que la société coche « habituels » (le loyer et l'électricité
 * par défaut) gardent une ligne au tableau des charges même sans charge de
 * l'année. Lectures filtrées par la société donnée, hors de son scope :
 * la page d'un client dans l'espace d'un cabinet les lit aussi.
 */
final class TypesDeCharge
{
    public const REFUS_TYPE = 'Ce type de charge n\'est pas proposé dans cette société.';

    public const PILIQI = 'piliqi:';

    public const SOCIETE = 'societe:';

    /**
     * Le type de cette clé, de la liste de PILIQI ou de la société ; un
     * type archivé seulement quand la charge le porte déjà. Une clé
     * « societe: » dont la suite n'est pas un uuid ne désigne rien, sans
     * interroger la base, où PostgreSQL la refuserait.
     *
     * @return array{0: TypeDeCharge|null, 1: TypeDeChargeDeLaSociete|null}
     *
     * @throws InvalidArgumentException une clé qui ne désigne aucun type proposé
     */
    public function resoudre(Societe $societe, string $cle, ?int $typeDejaPorte = null): array
    {
        if (str_starts_with($cle, self::PILIQI)) {
            return [TypeDeCharge::tryFrom(substr($cle, strlen(self::PILIQI))) ?? throw new InvalidArgumentException(self::REFUS_TYPE), null];
        }

        $uuid = str_starts_with($cle, self::SOCIETE) ? substr($cle, strlen(self::SOCIETE)) : '';

        if (! Str::isUuid($uuid)) {
            throw new InvalidArgumentException(self::REFUS_TYPE);
        }

        $type = $this->desTypes($societe)->where('uuid', $uuid)->first();

        if ($type === null || ($type->estArchive() && $type->getKey() !== $typeDejaPorte)) {
            throw new InvalidArgumentException(self::REFUS_TYPE);
        }

        return [null, $type];
    }

    /**
     * La nature d'un type résolu.
     *
     * @param  array{0: TypeDeCharge|null, 1: TypeDeChargeDeLaSociete|null}  $type
     */
    public static function natureDe(array $type): NatureDeCharge
    {
        return $type[0]?->nature() ?? $type[1]->nature ?? NatureDeCharge::Services;
    }

    /**
     * Les types proposés à la saisie, par groupe : la liste de PILIQI, puis
     * ceux de la société qui ne sont pas archivés, par nom.
     *
     * @return array<string, array<string, string>>
     */
    public function options(Societe $societe): array
    {
        $options = ['Liste de PILIQI' => []];

        foreach (TypeDeCharge::cases() as $type) {
            $options['Liste de PILIQI'][self::PILIQI.$type->value] = $type->getLabel();
        }

        $propres = $this->desTypes($societe)->whereNull('archive_le')->orderBy('nom_cle')->get(['uuid', 'nom']);

        if ($propres->isNotEmpty()) {
            $options['Types de la société'] = $propres->mapWithKeys(fn (TypeDeChargeDeLaSociete $type): array => [self::SOCIETE.$type->uuid => $type->nom])->all();
        }

        return $options;
    }

    /**
     * Les types de la société, archivés compris, par nom.
     *
     * @return Collection<int, TypeDeChargeDeLaSociete>
     */
    public function tousDeLaSociete(Societe $societe): Collection
    {
        return $this->desTypes($societe)->orderBy('nom_cle')->get();
    }

    /**
     * Les types de la liste de PILIQI que la société coche « habituels »,
     * dans l'ordre de la liste ; une valeur inconnue en base est ignorée.
     *
     * @return list<TypeDeCharge>
     */
    public function habituels(Societe $societe): array
    {
        $lus = Societe::query()->whereKey($societe->getKey())->value('types_de_charge_habituels');
        $valeurs = is_string($lus) ? json_decode($lus, true) : $lus;
        $cles = is_array($valeurs) ? ValeursLues::textes(collect($valeurs)->filter(fn (mixed $valeur): bool => is_string($valeur))) : [];

        return array_values(array_filter(TypeDeCharge::cases(), fn (TypeDeCharge $type): bool => in_array($type->value, $cles, true)));
    }

    /**
     * @return Builder<TypeDeChargeDeLaSociete>
     */
    private function desTypes(Societe $societe): Builder
    {
        return TypeDeChargeDeLaSociete::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $societe->getKey());
    }
}
