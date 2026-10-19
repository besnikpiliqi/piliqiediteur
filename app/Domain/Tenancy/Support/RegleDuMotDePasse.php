<?php

namespace App\Domain\Tenancy\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * La règle d'un mot de passe que la personne choisit une fois son compte
 * créé : dans son profil, sur l'écran de qui lui redonne l'accès en sa
 * présence, à sa première connexion pour un responsable, un administrateur
 * ou un expert-comptable, et sur la page du lien « Mot de passe oublié »
 * du propriétaire. Au moins 8 caractères ; jamais l'identifiant du compte,
 * ni une chaîne qui le contient ; ni un seul caractère répété, ni une suite
 * de chiffres croissante ou décroissante.
 *
 * À l'inscription, le mot de passe du propriétaire se choisit avant que son
 * identifiant existe : la demande d'inscription applique la règle sans
 * identifiant, puis le tirage écarte la valeur égale à ce mot de passe
 * (voir GenerateurDIdentifiants).
 */
final class RegleDuMotDePasse implements ValidationRule
{
    public const LONGUEUR_MINIMALE = 8;

    public const MESSAGE_TROP_COURT = 'Le mot de passe doit compter au moins 8 caractères.';

    public const MESSAGE_IDENTIFIANT = 'Le mot de passe ne doit être ni votre identifiant, ni le contenir.';

    public const MESSAGE_REPETE = 'Le mot de passe ne doit pas être un seul caractère répété.';

    public const MESSAGE_SUITE = 'Le mot de passe ne doit pas être une suite de chiffres.';

    /**
     * @param  string|null  $identifiant  l'identifiant du compte, que le mot de passe ne doit ni égaler ni contenir ; null à l'inscription, avant qu'il existe
     */
    public function __construct(private readonly ?string $identifiant) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (self::reproches((string) $value, $this->identifiant) as $reproche) {
            $fail($reproche);
        }
    }

    /**
     * Ce que la règle reproche à ce mot de passe, dans l'ordre ; aucun
     * reproche quand il la suit.
     *
     * @return list<string>
     */
    public static function reproches(#[SensitiveParameter] string $motDePasse, ?string $identifiant): array
    {
        $reproches = [];

        if (mb_strlen($motDePasse) < self::LONGUEUR_MINIMALE) {
            $reproches[] = self::MESSAGE_TROP_COURT;
        }

        if ($identifiant !== null && $identifiant !== '' && str_contains($motDePasse, $identifiant)) {
            $reproches[] = self::MESSAGE_IDENTIFIANT;
        }

        if ($motDePasse !== '' && preg_match('/^(.)\1*$/Dsu', $motDePasse) === 1) {
            $reproches[] = self::MESSAGE_REPETE;
        }

        if (self::estUneSuiteDeChiffres($motDePasse)) {
            $reproches[] = self::MESSAGE_SUITE;
        }

        return $reproches;
    }

    /**
     * La règle d'un mot de passe obligatoire, pour un formulaire ou un
     * validateur.
     *
     * @return list<string|self>
     */
    public static function regles(?string $identifiant): array
    {
        return ['required', 'string', new self($identifiant)];
    }

    /**
     * @throws ValidationException quand le mot de passe ne suit pas la règle
     */
    public static function exiger(#[SensitiveParameter] string $motDePasse, ?string $identifiant, string $champ = 'password'): void
    {
        $validateur = Validator::make(['mot_de_passe' => $motDePasse], ['mot_de_passe' => self::regles($identifiant)]);

        if ($validateur->fails()) {
            throw ValidationException::withMessages([$champ => $validateur->errors()->get('mot_de_passe')]);
        }
    }

    /**
     * Une suite de chiffres, croissante ou décroissante, d'au moins deux
     * chiffres (« 12345678 », « 98765432 »).
     */
    private static function estUneSuiteDeChiffres(string $motDePasse): bool
    {
        if (preg_match('/^[0-9]{2,}$/D', $motDePasse) !== 1) {
            return false;
        }

        $pas = (int) $motDePasse[1] - (int) $motDePasse[0];

        if (abs($pas) !== 1) {
            return false;
        }

        for ($rang = 2, $longueur = strlen($motDePasse); $rang < $longueur; $rang++) {
            if ((int) $motDePasse[$rang] - (int) $motDePasse[$rang - 1] !== $pas) {
                return false;
            }
        }

        return true;
    }
}
