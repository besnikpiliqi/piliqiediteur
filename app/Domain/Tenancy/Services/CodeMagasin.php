<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Le code court d'un magasin, inséré dans tous les numéros de ses
 * documents (FAC-LYON1-2026-000001, voir DocumentNumberingService) : 2 à
 * 10 lettres majuscules ou chiffres, sans espace ni tiret, puisque le
 * tiret sépare les segments du numéro. Il n'appartient qu'à un seul
 * magasin de la société (voir l'index unique (societe_id, code) de
 * magasins), n'est jamais repris d'un magasin d'une société révoquée du
 * même SIREN, dont les numéros ont pu être émis sous ce SIREN (voir
 * estPris()), et ne change plus dès le premier document du magasin (voir
 * Magasin::aNumeroteUnDocument()). Deux sociétés ont chacune leurs codes :
 * LYON1 peut exister dans l'une et dans l'autre. DEMO est réservé au
 * magasin de démonstration de chaque société (FAC-DEMO-2026-000001) :
 * aucun magasin réel ne le reçoit.
 */
class CodeMagasin
{
    public const FORMAT = '/^[A-Z0-9]{2,10}$/';

    public const CODE_DE_LA_DEMONSTRATION = 'DEMO';

    public const LONGUEUR_MAXIMALE = 10;

    /**
     * Le nombre de lettres ou de chiffres d'un nom que reprend le code
     * qu'il tire.
     */
    private const LONGUEUR_PROPOSEE = 6;

    /**
     * Le code tiré d'un nom qui compte moins de deux lettres ou chiffres.
     */
    private const CODE_SANS_NOM = 'MAG';

    /**
     * Le code proposé pour un magasin : celui que tire ce nom (le nom du
     * magasin, ou la commune de son établissement à la création, voir
     * CreerMagasin), rendu libre dans la société. Le nom donne ses six
     * premières lettres ou chiffres, sans accent, en majuscules (« Ma
     * Boutique » donne MABOUT, « Été 2026 » ETE202), ou MAG quand il en
     * compte moins de deux ; déjà pris dans la société, le code reçoit le
     * premier suffixe libre, 2, 3..., raccourci au besoin pour tenir en dix
     * caractères : un second magasin à Paris reçoit PARIS2.
     */
    public static function proposer(Societe $societe, string $nom): string
    {
        $base = substr((string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($nom))), 0, self::LONGUEUR_PROPOSEE);
        $base = strlen($base) < 2 ? self::CODE_SANS_NOM : $base;
        $code = $base;

        for ($suffixe = 2; self::estReserve($code) || self::estPris($societe, $code); $suffixe++) {
            $code = substr($base, 0, self::LONGUEUR_MAXIMALE - strlen((string) $suffixe)).$suffixe;
        }

        return $code;
    }

    /**
     * Le code tel qu'il s'enregistre : sans espace autour, en majuscules.
     */
    public static function normaliser(string $saisie): string
    {
        return Str::upper(trim($saisie));
    }

    /**
     * Cette saisie, une fois normalisée, est-elle au format d'un code ?
     */
    public static function estAuFormat(mixed $saisie): bool
    {
        return is_string($saisie) && preg_match(self::FORMAT, self::normaliser($saisie)) === 1;
    }

    /**
     * Ce code est-il réservé au magasin de démonstration ?
     */
    public static function estReserve(string $code): bool
    {
        return self::normaliser($code) === self::CODE_DE_LA_DEMONSTRATION;
    }

    /**
     * Ce code est-il déjà celui d'un magasin de la société, autre que
     * $saufPour, ou d'un magasin d'une société révoquée du même SIREN ?
     * Seule lecture hors de la société (liste fermée des lectures hors du
     * périmètre courant), limitée à l'existence de ce code : la réponse ne
     * dit rien du magasin qui le porte.
     */
    public static function estPris(Societe $societe, string $code, ?Magasin $saufPour = null): bool
    {
        return Magasin::query()
            ->where('code', $code)
            ->where(fn (Builder $proprietaires): Builder => $proprietaires
                ->where('societe_id', $societe->getKey())
                ->orWhereIn('societe_id', Societe::query()
                    ->select('id')
                    ->where('siren', $societe->siren)
                    ->where('statut', StatutSociete::Revoquee->value)))
            ->when($saufPour, fn (Builder $requete): Builder => $requete->whereKeyNot($saufPour->getKey()))
            ->exists();
    }
}
