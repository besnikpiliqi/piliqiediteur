<?php

namespace App\Domain\Tenancy\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Les jetons des liens envoyés à l'adresse d'un compte (« Mot de passe
 * oublié », renouvellement de l'accès), gardés par le courtier des mots de
 * passe de Laravel (table password_reset_tokens, une ligne par adresse,
 * l'empreinte du jeton, jamais le jeton) : un lien vaut le délai de
 * config/auth.php et ne sert qu'une fois. La page du lien (voir PageDuLien)
 * ne fait que le lire pour s'afficher ; seule la requête qui décide le
 * consomme, sous le verrou de sa ligne, dans sa propre transaction : deux
 * décisions simultanées sur le même lien n'en retiennent qu'une. La table
 * appartient aux comptes, pas à un magasin : elle se filtre par adresse.
 */
final class JetonsDuLien
{
    public function estValable(User $compte, string $jeton): bool
    {
        return $this->verifier(DB::table($this->table())->where('email', $compte->getEmailForPasswordReset())->first(), $jeton);
    }

    /**
     * Consomme le jeton s'il est valable pour ce compte : à appeler dans la
     * transaction de la décision.
     */
    public function consommer(User $compte, string $jeton): bool
    {
        $lignes = DB::table($this->table())->where('email', $compte->getEmailForPasswordReset());

        if (! $this->verifier((clone $lignes)->lockForUpdate()->first(), $jeton)) {
            return false;
        }

        $lignes->delete();

        return true;
    }

    private function verifier(?object $ligne, string $jeton): bool
    {
        if ($ligne === null || ! is_string($ligne->token) || $ligne->created_at === null) {
            return false;
        }

        $expireLe = CarbonImmutable::parse($ligne->created_at)->addMinutes((int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'));

        return $expireLe->isFuture() && Hash::check($jeton, $ligne->token);
    }

    private function table(): string
    {
        return (string) config('auth.passwords.'.config('auth.defaults.passwords').'.table');
    }
}
