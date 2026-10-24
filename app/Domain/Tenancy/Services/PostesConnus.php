<?php

namespace App\Domain\Tenancy\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Le poste connu d'un compte : après une connexion réussie de ce compte, ce
 * navigateur reçoit un témoin, chiffré par le chiffrement des cookies de
 * Laravel (« postes_connus », un an, au plus 30 comptes par navigateur),
 * qui porte l'identifiant technique du compte et un nonce aléatoire propre
 * à ce compte. À l'essai suivant de ce compte sur ce navigateur, un témoin
 * valable l'exempte des attentes de la limitation des essais (voir
 * LimitationDesConnexions) : qui connaît l'identifiant d'un collègue et
 * multiplie les échecs le fait attendre, lui, jamais le collègue sur son
 * propre poste. Le témoin ne sert qu'aux attentes, jamais à se connecter.
 */
final class PostesConnus
{
    public const TEMOIN = 'postes_connus';

    public const MAXIMUM_PAR_NAVIGATEUR = 30;

    public const DUREE_EN_MINUTES = 60 * 24 * 365;

    /**
     * Le nonce du témoin de ce compte que porte le navigateur ; null sans
     * témoin pour lui.
     */
    public function nonceDuCompte(Request $requete, User $compte): ?string
    {
        $nonce = $this->postes($requete)[(string) $compte->getKey()] ?? null;

        return is_string($nonce) && preg_match('/^[A-Za-z0-9]{40}$/D', $nonce) === 1 ? $nonce : null;
    }

    /**
     * Retient ce navigateur comme un poste connu de ce compte, avec son
     * nonce s'il en avait déjà un : le témoin part avec la réponse, ce
     * compte en dernier, les plus anciens sortant au-delà de 30 comptes.
     */
    public function retenir(Request $requete, User $compte): void
    {
        $postes = $this->postes($requete);
        $cle = (string) $compte->getKey();
        $nonce = $this->nonceDuCompte($requete, $compte) ?? Str::random(40);
        unset($postes[$cle]);
        $postes[$cle] = $nonce;

        Cookie::queue(Cookie::make(self::TEMOIN, json_encode(array_slice($postes, -self::MAXIMUM_PAR_NAVIGATEUR, preserve_keys: true), JSON_THROW_ON_ERROR), self::DUREE_EN_MINUTES, httpOnly: true, sameSite: 'lax'));
    }

    /**
     * @return array<string, mixed>
     */
    private function postes(Request $requete): array
    {
        $postes = json_decode((string) $requete->cookie(self::TEMOIN, ''), true);

        return is_array($postes) ? $postes : [];
    }
}
