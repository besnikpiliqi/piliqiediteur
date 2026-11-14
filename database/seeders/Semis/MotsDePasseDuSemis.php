<?php

namespace Database\Seeders\Semis;

use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * Les mots de passe des comptes semés, comme si chaque personne avait
 * choisi le sien : le même pour tous, stable d'un semis à l'autre, quand
 * la variable PILIQI_DEMO_MOT_DE_PASSE du .env le donne (config
 * piliqi.demo_mot_de_passe) ; sinon un mot de passe tiré au hasard pour
 * chaque compte, à chaque semis. Chacun suit la règle des mots de passe
 * choisis (voir RegleDuMotDePasse) : au moins 8 caractères, ni un seul
 * caractère répété, ni une suite de chiffres, ni l'identifiant du compte
 * ni une chaîne qui le contient. Aucun ne s'écrit dans le dépôt.
 */
final class MotsDePasseDuSemis
{
    public const MESSAGE_REFUSE = 'PILIQI_DEMO_MOT_DE_PASSE ne suit pas la règle des mots de passe (%s) : choisissez-en un autre dans le .env, ou retirez la variable pour des mots de passe tirés au hasard.';

    public const MESSAGE_IDENTIFIANT = 'PILIQI_DEMO_MOT_DE_PASSE contient l\'identifiant %s, tiré pour un compte semé : choisissez un mot de passe sans cette suite de chiffres, puis relancez php artisan migrate:fresh --seed.';

    private function __construct(private readonly ?string $commun) {}

    /**
     * Les mots de passe que donne la configuration : le mot de passe commun
     * de PILIQI_DEMO_MOT_DE_PASSE, contrôlé avant tout semis, ou le tirage.
     *
     * @throws RuntimeException quand le mot de passe commun ne suit pas la règle
     */
    public static function depuisLaConfiguration(): self
    {
        $commun = config('piliqi.demo_mot_de_passe');

        if (! is_string($commun) || $commun === '') {
            return new self(null);
        }

        $reproches = RegleDuMotDePasse::reproches($commun, null);

        if ($reproches !== []) {
            throw new RuntimeException(sprintf(self::MESSAGE_REFUSE, implode(' ', $reproches)));
        }

        return new self($commun);
    }

    /**
     * Vrai quand tous les comptes reçoivent le mot de passe commun.
     */
    public function sontCommuns(): bool
    {
        return $this->commun !== null;
    }

    /**
     * Le mot de passe d'un compte qui n'a pas encore d'identifiant (le
     * propriétaire qui s'inscrit, dont l'identifiant se tire ensuite, voir
     * GenerateurDIdentifiants) : à contrôler avec son identifiant dès qu'il
     * existe (voir controlerAvec()).
     */
    public function nouveau(): string
    {
        return $this->commun ?? self::tirer();
    }

    /**
     * Le mot de passe d'un compte qui a déjà son identifiant.
     *
     * @throws RuntimeException quand le mot de passe commun contient cet identifiant
     */
    public function pour(string $identifiant): string
    {
        if ($this->commun !== null) {
            $this->controlerAvec($this->commun, $identifiant);

            return $this->commun;
        }

        do {
            $motDePasse = self::tirer();
        } while (RegleDuMotDePasse::reproches($motDePasse, $identifiant) !== []);

        return $motDePasse;
    }

    /**
     * Ce mot de passe ne contient pas cet identifiant.
     *
     * @throws RuntimeException
     */
    public function controlerAvec(#[SensitiveParameter] string $motDePasse, string $identifiant): void
    {
        if (RegleDuMotDePasse::reproches($motDePasse, $identifiant) !== []) {
            throw new RuntimeException(sprintf(self::MESSAGE_IDENTIFIANT, $identifiant));
        }
    }

    /**
     * Un mot de passe facile à recopier : trois groupes de quatre lettres
     * minuscules ou chiffres, séparés par des tirets (« k7qp-2mzr-xa4d »).
     */
    private static function tirer(): string
    {
        return implode('-', array_map(static fn (): string => Str::lower(Str::random(4)), range(1, 3)));
    }
}
