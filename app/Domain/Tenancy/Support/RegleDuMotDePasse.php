<?php

namespace App\Domain\Tenancy\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * La règle d'un mot de passe que la personne choisit : dans son profil, à
 * sa première connexion avec son mot de passe provisoire, sur l'écran de
 * qui lui redonne l'accès ou crée son compte en sa présence, et sur la page
 * du lien « Mot de passe oublié » du propriétaire ; à l'inscription et à
 * l'acceptation d'une invitation, pour le propriétaire (décision de Claude
 * D76, T36.1 à T36.4, d'après la recommandation de la CNIL sur les mots de
 * passe) :
 * - au moins 12 caractères, ou au moins 8 caractères de trois sortes
 *   parmi les majuscules, les minuscules, les chiffres et les signes (tout
 *   caractère qui n'est ni une lettre ni un chiffre, l'espace compris) ;
 * - jamais un mot de passe courant (voir MotsDePasseCourants) ;
 * - jamais l'identifiant du compte, ni une chaîne qui le contient, ni un
 *   seul caractère répété, ni une suite de chiffres croissante ou
 *   décroissante.
 * Un mot de passe provisoire (voir MotsDePasseProvisoires) n'est pas
 * choisi : il est tiré au hasard, ne sert qu'une fois, et la personne le
 * remplace aussitôt par un mot de passe qui suit cette règle.
 *
 * À l'inscription, le mot de passe du propriétaire se choisit avant que son
 * identifiant existe : la demande d'inscription applique la règle sans
 * identifiant, puis le tirage écarte la valeur égale à ce mot de passe
 * (voir GenerateurDIdentifiants).
 */
final class RegleDuMotDePasse implements ValidationRule
{
    public const LONGUEUR_SANS_COMPOSITION = 12;

    public const LONGUEUR_AVEC_COMPOSITION = 8;

    public const SORTES_EXIGEES = 3;

    public const AIDE = 'Au moins 12 caractères, ou 8 caractères de trois sortes parmi les majuscules, les minuscules, les chiffres et les signes ; jamais un mot de passe courant, ni votre identifiant, ni un seul caractère répété, ni une suite de chiffres.';

    public const MESSAGE_TROP_FAIBLE = 'Le mot de passe doit compter au moins 12 caractères, ou au moins 8 caractères de trois sortes parmi les majuscules, les minuscules, les chiffres et les signes.';

    public const MESSAGE_COURANT = 'Ce mot de passe est trop courant : choisissez-en un autre.';

    public const MESSAGE_IDENTIFIANT = 'Le mot de passe ne doit être ni votre identifiant, ni le contenir.';

    public const MESSAGE_REPETE = 'Le mot de passe ne doit pas être un seul caractère répété.';

    public const MESSAGE_SUITE = 'Le mot de passe ne doit pas être une suite de chiffres.';

    /**
     * @param  string|null  $identifiant  l'identifiant du compte, que le mot de passe ne doit ni égaler ni contenir ; null à l'inscription, avant qu'il existe
     */
    public function __construct(private readonly ?string $identifiant) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach (self::reproches(ValeurSimple::enTexte($value), $this->identifiant) as $reproche) {
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

        if (! self::estAssezFort($motDePasse)) {
            $reproches[] = self::MESSAGE_TROP_FAIBLE;
        }

        if ($motDePasse !== '' && MotsDePasseCourants::contient($motDePasse)) {
            $reproches[] = self::MESSAGE_COURANT;
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
     * Combien de sortes de caractères ce mot de passe compte : les
     * majuscules, les minuscules, les chiffres, les signes (tout le reste).
     */
    public static function sortes(#[SensitiveParameter] string $motDePasse): int
    {
        return array_sum([
            preg_match('/\p{Lu}/u', $motDePasse) === 1 ? 1 : 0,
            preg_match('/\p{Ll}/u', $motDePasse) === 1 ? 1 : 0,
            preg_match('/\p{Nd}/u', $motDePasse) === 1 ? 1 : 0,
            preg_match('/[^\p{L}\p{Nd}]|\p{Lo}|\p{Lt}|\p{Lm}/u', $motDePasse) === 1 ? 1 : 0,
        ]);
    }

    /**
     * Au moins 12 caractères, ou au moins 8 de trois sortes.
     */
    private static function estAssezFort(#[SensitiveParameter] string $motDePasse): bool
    {
        $longueur = mb_strlen($motDePasse);

        return $longueur >= self::LONGUEUR_SANS_COMPOSITION
            || ($longueur >= self::LONGUEUR_AVEC_COMPOSITION && self::sortes($motDePasse) >= self::SORTES_EXIGEES);
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
