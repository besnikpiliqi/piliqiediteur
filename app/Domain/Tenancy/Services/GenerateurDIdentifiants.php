<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Contracts\SourceDeHasard;
use App\Domain\Tenancy\Models\Identifiant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SensitiveParameter;

/**
 * Tire l'identifiant de connexion d'un nouveau compte, dans la transaction
 * qui le crée et dans la société active :
 * - un nombre de 10 000 000 à 99 999 999, tiré de la source de hasard (voir
 *   SourceDeHasard), un tirage par essai et rien d'autre : jamais un
 *   compteur, rien qui dise la société, le magasin, le rôle ni l'ordre de
 *   création ;
 * - jamais une valeur triviale (un seul chiffre répété, 12345678,
 *   23456789, 98765432, 87654321, 76543210), ni, pour le propriétaire
 *   inscrit par son lien, la valeur égale au mot de passe qu'il a déjà
 *   choisi, connu seulement haché ;
 * - l'identifiant s'insère dans un point de sauvegarde : un doublon, refusé
 *   par l'index unique de la table, revient au point de sauvegarde et un
 *   autre nombre se tire, sans interrompre la transaction de la création
 *   (sous PostgreSQL, une violation d'unicité sans point de sauvegarde
 *   l'interromprait toute) ; deux créations simultanées qui tirent la même
 *   valeur se suivent sur l'index. Le tirage ne lit jamais les identifiants
 *   d'une autre société, et aucun écran ne parle d'un doublon.
 * Au plus ESSAIS tirages, puis une exception claire.
 */
final class GenerateurDIdentifiants
{
    public const ESSAIS = 10;

    public const PLUS_PETIT = 10_000_000;

    public const PLUS_GRAND = 99_999_999;

    /**
     * Les valeurs qu'un attaquant essaie d'abord, jamais tirées, en plus
     * d'un seul chiffre répété.
     */
    private const SUITES = ['12345678', '23456789', '98765432', '87654321', '76543210'];

    public function __construct(private readonly SourceDeHasard $hasard) {}

    /**
     * Attribue un identifiant à ce compte, avec son magasin de rattachement
     * pour un compte de membre, et le jeton et l'empreinte de l'écran qui
     * l'a créé ; rend l'identifiant.
     *
     * @param  string|null  $motDePasseHache  le mot de passe déjà choisi du propriétaire inscrit par son lien, que l'identifiant ne doit pas égaler
     *
     * @throws RuntimeException quand aucun identifiant libre n'a pu être tiré
     */
    public function attribuer(User $compte, ?int $magasinId = null, ?string $jetonDeCreation = null, ?string $empreinteDeCreation = null, #[SensitiveParameter] ?string $motDePasseHache = null): string
    {
        for ($essai = 1; $essai <= self::ESSAIS; $essai++) {
            $valeur = (string) $this->hasard->entier(self::PLUS_PETIT, self::PLUS_GRAND);

            if (self::estTrivial($valeur) || ($motDePasseHache !== null && Hash::check($valeur, $motDePasseHache))) {
                continue;
            }

            try {
                DB::transaction(fn (): bool => (new Identifiant)->forceFill([
                    'identifiant' => $valeur,
                    'user_id' => $compte->getKey(),
                    'magasin_id' => $magasinId,
                    'jeton_de_creation' => $jetonDeCreation,
                    'empreinte_de_creation' => $empreinteDeCreation,
                ])->save());
            } catch (UniqueConstraintViolationException $doublon) {
                if (! self::porteSurLIdentifiant($doublon)) {
                    throw $doublon;
                }

                continue;
            }

            return $valeur;
        }

        throw new RuntimeException('Aucun identifiant de connexion libre n\'a pu être tiré : recommencez.');
    }

    /**
     * Un seul chiffre répété, ou l'une des suites qu'un attaquant essaie
     * d'abord.
     */
    public static function estTrivial(string $valeur): bool
    {
        return preg_match('/^(\d)\1{7}$/D', $valeur) === 1 || in_array($valeur, self::SUITES, true);
    }

    /**
     * Le doublon porte-t-il sur l'identifiant lui-même, et non sur un autre
     * index (le compte, le jeton de création) ?
     */
    private static function porteSurLIdentifiant(UniqueConstraintViolationException $doublon): bool
    {
        return str_contains($doublon->getMessage(), 'identifiants_identifiant_unique')
            || str_contains($doublon->getMessage(), 'identifiants.identifiant');
    }
}
