<?php

namespace Database\Seeders\Semis;

use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Closure;
use LogicException;
use SensitiveParameter;

/**
 * La liste des comptes semés, pour se connecter : pour chacun, sa
 * société, son magasin (aucun pour un compte de la société ou du cabinet),
 * son rôle, son nom, son identifiant de connexion, son adresse de
 * connexion (celle d'un propriétaire, qui se connecte aussi avec elle), son
 * mot de passe, les clients confiés à un collaborateur du cabinet et ce
 * qu'il faut regarder avec ce compte, dans l'ordre où le semis les crée.
 * Elle s'affiche à la fin du semis et s'écrit dans un fichier local, non
 * suivi par git (voir DatabaseSeeder) ; le mot de passe commun ne s'écrit
 * nulle part ailleurs.
 */
final class ComptesSemes
{
    public const ENTETES = ['Société', 'Magasin', 'Rôle', 'Nom', 'Identifiant', 'Adresse de connexion', 'Mot de passe', 'Clients confiés', 'À regarder avec ce compte'];

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
     * mot de passe est encore son identifiant (ou qu'il ne l'a pas encore
     * choisi, avec $sansMotDePasse pour le dire), et ce qu'il faut regarder
     * avec lui.
     */
    public function noter(User $compte, string $societe, string $magasin, string $role, #[SensitiveParameter] ?string $motDePasse, string $aRegarder = '', ?string $sansMotDePasse = null): void
    {
        $this->comptes[] = [
            'id' => $compte->id,
            'ligne' => [
                $societe,
                $magasin,
                $role,
                (string) $compte->name,
                (string) $compte->identifiantDeConnexion(),
                $compte->estProprietaire() ? (string) $compte->email : '',
                $motDePasse ?? $sansMotDePasse ?? self::SON_IDENTIFIANT,
                '',
                $aRegarder,
            ],
        ];
    }

    /**
     * Ajoute ce client à ceux qui sont confiés à ce collaborateur du
     * cabinet.
     */
    public function confier(User $collaborateur, string $client): void
    {
        $this->modifier($collaborateur, function (array $ligne) use ($client): array {
            $ligne[7] = $ligne[7] === '' ? $client : $ligne[7].', '.$client;

            return $ligne;
        });
    }

    /**
     * Ce qu'il faut regarder avec ce compte, en remplacement de ce qui
     * était noté.
     */
    public function regarder(User $compte, string $aRegarder): void
    {
        $this->modifier($compte, function (array $ligne) use ($aRegarder): array {
            $ligne[8] = $aRegarder;

            return $ligne;
        });
    }

    /**
     * Ce compte a choisi un nouveau mot de passe.
     */
    public function changerLeMotDePasse(User $compte, #[SensitiveParameter] string $motDePasse): void
    {
        $this->modifier($compte, function (array $ligne) use ($motDePasse): array {
            $ligne[6] = $motDePasse;

            return $ligne;
        });
    }

    /**
     * Ce compte n'a plus le mot de passe noté : il est redevenu son
     * identifiant (accès redonné en son absence).
     */
    public function oublierSonMotDePasse(User $compte): void
    {
        $this->modifier($compte, function (array $ligne): array {
            $ligne[6] = self::SON_IDENTIFIANT;

            return $ligne;
        });
    }

    /**
     * La propriété est passée de l'ancien propriétaire à l'administrateur
     * qui a suivi le lien : chacun garde son identifiant et son mot de
     * passe, l'adresse de connexion passe au nouveau.
     */
    public function noterLaTransmission(User $ancien, User $nouveau): void
    {
        $this->modifier($ancien, function (array $ligne): array {
            $ligne[2] = 'Administrateur (ancien propriétaire)';
            $ligne[5] = '';

            return $ligne;
        });
        $this->modifier($nouveau, function (array $ligne) use ($nouveau): array {
            $ligne[1] = '';
            $ligne[2] = 'Propriétaire (par transmission)';
            $ligne[5] = (string) $nouveau->email;

            return $ligne;
        });
    }

    /**
     * Le rôle de ce compte, en remplacement de celui qui était noté (après
     * une promotion ou un changement de rôle de société).
     */
    public function changerLeRole(User $compte, string $magasin, string $role): void
    {
        $this->modifier($compte, function (array $ligne) use ($magasin, $role): array {
            $ligne[1] = $magasin;
            $ligne[2] = $role;

            return $ligne;
        });
    }

    /**
     * @param  Closure(list<string>): array<int, string>  $modification
     *
     * @throws LogicException quand ce compte n'a pas été noté
     */
    private function modifier(User $compte, Closure $modification): void
    {
        foreach ($this->comptes as $rang => $note) {
            if ($note['id'] === $compte->id) {
                $this->comptes[$rang]['ligne'] = array_values($modification($note['ligne']));

                return;
            }
        }

        throw new LogicException("Le compte {$compte->name} n'a pas été noté dans la liste.");
    }

    /**
     * Ôte de la liste les comptes retirés depuis qu'ils ont été notés, qui
     * ne se connectent plus (les comptes de l'expert-comptable d'une
     * déclaration devenue un lien), sauf ceux
     * qu'on garde pour voir ce qu'est un compte retiré.
     *
     * @param  list<int>  $gardes
     */
    public function oublierLesComptesRetires(array $gardes = []): void
    {
        $retires = ValeursLues::entiers(User::query()
            ->whereKey(array_values(array_diff(array_column($this->comptes, 'id'), $gardes)))
            ->where('statut', StatutCompte::Retire)
            ->pluck('id'));

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
