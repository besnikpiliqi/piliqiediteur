<?php

namespace App\Domain\Tenancy\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Les jetons des liens « Mot de passe oublié » du propriétaire, gardés par
 * le courtier des mots de passe de Laravel (table password_reset_tokens,
 * une ligne par adresse, l'empreinte du jeton, jamais le jeton) : seuls les
 * propriétaires s'en servent, et leur adresse de connexion est unique
 * parmi eux. Un lien vaut le délai de config/auth.php et ne sert qu'une
 * fois. La page du lien (voir PageDuLien) ne fait que le lire pour
 * s'afficher ; seule la requête qui choisit le mot de passe le consomme,
 * sous le verrou de sa ligne, dans sa propre transaction. La table
 * appartient aux comptes, pas à un magasin : elle se filtre par adresse.
 */
final class JetonsDuLien
{
    public function estValable(User $compte, string $jeton): bool
    {
        return $compte->estProprietaire()
            && $this->verifier(DB::table($this->table())->where('email', $compte->getEmailForPasswordReset())->first(), $jeton);
    }

    /**
     * Consomme le jeton s'il est valable pour ce propriétaire : à appeler
     * dans la transaction de la décision.
     */
    public function consommer(User $compte, string $jeton): bool
    {
        if (! $compte->estProprietaire()) {
            return false;
        }

        $lignes = DB::table($this->table())->where('email', $compte->getEmailForPasswordReset());

        if (! $this->verifier((clone $lignes)->lockForUpdate()->first(), $jeton)) {
            return false;
        }

        $lignes->delete();

        return true;
    }

    /**
     * Efface le lien en attente de cette adresse : celui d'un ancien
     * propriétaire, à la transmission de la propriété, ou d'une ancienne
     * adresse de connexion.
     */
    public function effacer(string $adresse): void
    {
        DB::table($this->table())->where('email', $adresse)->delete();
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
