<?php

namespace App\Domain\Calendrier\Casts;

use App\Domain\Tenancy\Support\ValeursLues;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Un instant, enregistré en UTC à la seconde (« AAAA-MM-JJ HH:MM:SS »,
 * colonne timestamp ou dateTime) et relu en UTC : une émission, un
 * horodatage du registre fiscal, une expiration, une connexion. Deux
 * instants se comparent ainsi en base comme en PHP dans leur ordre réel,
 * y compris aux deux passages de l'heure qui se répète au passage à
 * l'heure d'hiver.
 *
 * Seule une date qui porte son fuseau (DateTimeInterface) s'écrit,
 * ramenée en UTC quel que soit son fuseau : une chaîne ne dit pas de quel
 * fuseau est son heure.
 *
 * @implements CastsAttributes<CarbonImmutable|null, DateTimeInterface|null>
 */
final class Instant implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * L'instant tel qu'il s'écrit en base, et tel qu'une requête le
     * compare à une colonne : la seule définition de ce format.
     */
    public static function enBase(DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format(self::FORMAT);
    }

    /**
     * L'instant d'un texte lu en base hors d'un modèle (un agrégat, une
     * lecture par DB::table()), en UTC. Tout autre texte que
     * « AAAA-MM-JJ HH:MM:SS » à une date et une heure qui existent est
     * refusé, jamais corrigé : la lecture native, qui ne lève rien, rend
     * faux sur un texte illisible, et le format relu écarte un débordement
     * (« 2026-02-30 »).
     *
     * @throws InvalidArgumentException
     */
    public static function depuisLaBase(string $texte): CarbonImmutable
    {
        $instant = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $texte, new DateTimeZone('UTC'));

        if ($instant === false || $instant->format(self::FORMAT) !== $texte) {
            throw new InvalidArgumentException("« {$texte} » n'est pas un instant en base « AAAA-MM-JJ HH:MM:SS ».");
        }

        return CarbonImmutable::instance($instant);
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : self::depuisLaBase(ValeursLues::texte($value));
    }

    /**
     * Une écriture faite sans passer par le type de l'attribut (un tableau
     * de forceFill(), par exemple) peut porter n'importe quoi, qui est
     * refusé.
     *
     * @param  mixed  $value  une date qui porte son fuseau, ou n'importe quoi d'autre, qui est refusé
     *
     * @throws InvalidArgumentException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException("L'instant {$key} s'écrit avec une date qui porte son fuseau, jamais avec une chaîne.");
        }

        return self::enBase($value);
    }
}
