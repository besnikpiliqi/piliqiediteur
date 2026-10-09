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
     * Le nombre de lettres ou de chiffres du nom que reprend un code
     * proposé.
     */
    private const LONGUEUR_PROPOSEE = 6;

    /**
     * Le code proposé quand le nom compte moins de deux lettres ou
     * chiffres.
     */
    private const CODE_SANS_NOM = 'MAG';

    /**
     * Le code proposé pour un magasin de ce nom : ses six premières lettres
     * ou chiffres, sans accent, en majuscules (« Ma Boutique » donne
     * MABOUT, « Été 2026 » ETE202), ou MAG quand le nom en compte moins de
     * deux. Déjà pris, il reçoit le premier suffixe libre, 2, 3... (un
     * second « Piliqi Store » reçoit PILIQI2), raccourci au besoin pour
     * tenir en dix caractères.
     *
     * @param  list<string>  $codesEcartes  codes à écarter en plus de ceux des magasins en base : celui qu'une création simultanée vient de prendre, que la transaction en cours ne voit pas forcément (voir CreerMagasinAction)
     */
    public static function proposer(string $nom, array $codesEcartes = []): string
    {
        $base = substr((string) preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($nom))), 0, self::LONGUEUR_PROPOSEE);

        if (strlen($base) < 2) {
            $base = self::CODE_SANS_NOM;
        }

        $code = $base;

        for ($suffixe = 2; in_array($code, $codesEcartes, true) || self::estPris($code); $suffixe++) {
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
     * Ce code est-il déjà celui d'un magasin, autre que $saufPour ? Seule
     * lecture de toute la table magasins avec celle du SIRET, limitée à
     * l'existence de ce code : la réponse ne dit rien du magasin qui le
     * porte. Les magasins supprimés, qui restent en base, comptent : leur
     * code n'est jamais repris.
     */
    public static function estPris(string $code, ?Magasin $saufPour = null): bool
    {
        return Magasin::withTrashed()
            ->where('code', $code)
            ->when($saufPour, fn (Builder $requete): Builder => $requete->whereKeyNot($saufPour->getKey()))
            ->exists();
    }
}
