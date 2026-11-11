<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Les témoins qu'un navigateur envoie sous un nom en tableau
 * (« postes_reconnus[0]=… ») : PHP les range en tableau sous le nom qui
 * précède le crochet, à la place du témoin ordinaire de ce nom, qu'il ne lit
 * plus tant qu'ils restent dans le navigateur. Le témoin que le site y pose
 * sous ce nom arriverait sans jamais être lu : la réponse qui le pose
 * efface donc aussi chacun d'eux (voir PostesReconnus et PostesConnus).
 */
final class TemoinsEnTableau
{
    /**
     * Efface du navigateur, avec la réponse, chaque témoin de l'en-tête
     * Cookie de cette requête que PHP range en tableau sous ce nom, sous le
     * nom même que le navigateur envoie : PHP décide lui-même du rangement
     * de chacun (parse_str(), qui suit ses règles des noms de témoins), que
     * sa valeur se déchiffre ou non. Un nom qui contient une virgule ou un
     * espace ne s'efface pas : la réponse ne le renverrait pas tel quel.
     */
    public static function effacer(Request $requete, string $nom): void
    {
        foreach (explode(';', implode(';', $requete->headers->all('cookie'))) as $temoin) {
            $nomEnvoye = ltrim(explode('=', $temoin, 2)[0], " \t\n\r\v\f");
            parse_str(rawurlencode($nomEnvoye).'=', $lu);

            if (is_array($lu[$nom] ?? null) && strpbrk($nomEnvoye, ", \t\n\r\v\f") === false) {
                Cookie::queue(Cookie::forget($nomEnvoye));
            }
        }
    }
}
