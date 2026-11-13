<?php

namespace Database\Seeders\Semis;

use App\Domain\Tenancy\Enums\StatutCompte;
use App\Models\User;
use SensitiveParameter;

/**
 * La liste des comptes semés, pour se connecter : pour chacun, sa
 * société, son magasin (aucun pour un compte de la société ou du cabinet),
 * son rôle, son nom, son identifiant de connexion, son adresse de
 * connexion (celle d'un propriétaire, qui se connecte aussi avec elle) et
 * son mot de passe, dans l'ordre où le semis les crée. Elle s'affiche à la
 * fin du semis et s'écrit dans un fichier local, non suivi par git (voir
 * DatabaseSeeder).
 */
final class ComptesSemes
{
    public const ENTETES = ['Société', 'Magasin', 'Rôle', 'Nom', 'Identifiant', 'Adresse de connexion', 'Mot de passe'];

    /**
     * Le texte de la colonne « Mot de passe » d'un compte dont le mot de
     * passe est encore son identifiant.
     */
    public const SON_IDENTIFIANT = 'son identifiant (poste reconnu exigé)';

    /**
     * Chaque compte noté, par son identifiant en base, avec sa ligne du
     * tableau, dans l'ordre des colonnes de ENTETES.
     *
     * @var list<array{id: int, ligne: list<string>}>
     */
    private array $comptes = [];

    /**
     * Note un compte et le mot de passe qu'il a choisi, ou null quand son
     * mot de passe est encore son identifiant.
     */
    public function noter(User $compte, string $societe, string $magasin, string $role, #[SensitiveParameter] ?string $motDePasse): void
    {
        $this->comptes[] = [
            'id' => (int) $compte->getKey(),
            'ligne' => [
                $societe,
                $magasin,
                $role,
                (string) $compte->name,
                (string) $compte->identifiantDeConnexion(),
                $compte->estProprietaire() ? (string) $compte->email : '',
                $motDePasse ?? self::SON_IDENTIFIANT,
            ],
        ];
    }

    /**
     * Ôte de la liste les comptes retirés depuis qu'ils ont été notés, qui
     * ne se connectent plus : les comptes d'entraînement d'une
     * démonstration que la validation de sa société a fermée.
     */
    public function oublierLesComptesRetires(): void
    {
        $retires = User::query()
            ->whereKey(array_column($this->comptes, 'id'))
            ->where('statut', StatutCompte::Retire)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $this->comptes = array_values(array_filter($this->comptes, static fn (array $compte): bool => ! in_array($compte['id'], $retires, true)));
    }

    /**
     * Les lignes du tableau, dans l'ordre des colonnes de ENTETES.
     *
     * @return list<list<string>>
     */
    public function lignes(): array
    {
        return array_column($this->comptes, 'ligne');
    }

    /**
     * Le tableau en texte, colonnes alignées, tel qu'il s'écrit dans le
     * fichier des comptes.
     */
    public function enTexte(): string
    {
        $lignes = [self::ENTETES, ...$this->lignes()];
        $largeurs = array_map(
            static fn (int $colonne): int => max(array_map(static fn (array $ligne): int => mb_strlen($ligne[$colonne]), $lignes)),
            array_keys(self::ENTETES),
        );
        $separateur = '+'.implode('+', array_map(static fn (int $largeur): string => str_repeat('-', $largeur + 2), $largeurs)).'+';
        $texte = [$separateur];

        foreach ($lignes as $rang => $ligne) {
            $texte[] = '| '.implode(' | ', array_map(
                static fn (string $valeur, int $largeur): string => $valeur.str_repeat(' ', $largeur - mb_strlen($valeur)),
                $ligne,
                $largeurs,
            )).' |';

            if ($rang === 0) {
                $texte[] = $separateur;
            }
        }

        $texte[] = $separateur;

        return implode(PHP_EOL, $texte).PHP_EOL;
    }
}
