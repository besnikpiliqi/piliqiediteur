<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Le code court d'un magasin, inséré dans tous les numéros de ses
 * documents (FAC-LYON1-2026-000001, voir DocumentNumberingService) : 2 à
 * 10 lettres majuscules ou chiffres, sans espace ni tiret, puisque le
 * tiret sépare les segments du numéro. Il n'appartient qu'à un seul
 * magasin du logiciel, magasins supprimés compris (voir l'index unique de
 * magasins.code), donc à un seul magasin de la société, et ne change plus
 * dès le premier document du magasin (voir Magasin::codeEstDefinitif()).
 */
class CodeMagasin
{
    public const FORMAT = '/^[A-Z0-9]{2,10}$/';

    public const LONGUEUR_MAXIMALE = 10;

    /**
     * Le nombre de lettres ou de chiffres du nom que reprend le code tiré
     * d'un nom.
     */
    private const LONGUEUR_PROPOSEE = 6;

    /**
     * Le code tiré d'un nom qui compte moins de deux lettres ou chiffres.
     */
    private const CODE_SANS_NOM = 'MAG';

    /**
     * Le code que tire un nom : ses six premières lettres ou chiffres, sans
     * accent, en majuscules (« Ma Boutique » donne MABOUT, « Été 2026 »
     * ETE202), ou MAG quand le nom en compte moins de deux. Il ne lit pas
     * la base : la page d'inscription, ouverte à tous, le propose sans rien
     * révéler des codes des autres magasins (voir RegisterMagasin).
     */
    public static function depuisLeNom(string $nom): string
    {
        $code = substr((string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($nom))), 0, self::LONGUEUR_PROPOSEE);

        return strlen($code) < 2 ? self::CODE_SANS_NOM : $code;
    }

    /**
     * Le code d'un magasin créé sans code choisi (voir Magasin::booted()) :
     * celui que tire son nom, ou, déjà pris, le même avec le premier
     * suffixe libre, 2, 3... (un second « Piliqi Store » reçoit PILIQI2),
     * raccourci au besoin pour tenir en dix caractères.
     */
    public static function proposer(string $nom): string
    {
        $base = self::depuisLeNom($nom);
        $code = $base;

        for ($suffixe = 2; self::estPris($code); $suffixe++) {
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
     * Ce code est-il déjà celui d'un magasin, autre que $saufPour ? Seule
     * lecture de toute la table magasins avec celle du SIRET, limitée à
     * l'existence de ce code : la réponse ne dit rien du magasin qui le
     * porte. Elle ne se fait que dans l'identité d'un magasin (voir
     * CodeMagasinLibre) et à la création d'un magasin sans code choisi ;
     * jamais à l'inscription, ouverte à tous (voir CodeMagasinAuFormat).
     * Les magasins supprimés, qui restent en base, comptent : leur code
     * n'est jamais repris.
     */
    public static function estPris(string $code, ?Magasin $saufPour = null): bool
    {
        return Magasin::withTrashed()
            ->where('code', $code)
            ->when($saufPour, fn (Builder $requete): Builder => $requete->whereKeyNot($saufPour->getKey()))
            ->exists();
    }
}
