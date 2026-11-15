<?php

namespace App\Domain\Tenancy\Isolation;

/**
 * Un problème d'isolation trouvé dans la base (voir VerificationDeLIsolation) :
 * la couche qui l'a trouvé (« SQL », « Laravel », ou les deux quand elles ne
 * disent pas la même chose), le contrôle, la table, l'identifiant interne de
 * la ligne, la colonne, la valeur attendue et la valeur trouvée, et la règle
 * de CLAUDE.md qu'il viole. Il ne porte jamais de donnée personnelle : ni nom,
 * ni adresse, ni montant, ni identifiant de connexion, seulement des noms de
 * tables et de colonnes et des identifiants internes.
 */
final readonly class ProblemeDIsolation
{
    public function __construct(
        public string $couche,
        public string $controle,
        public string $table,
        public string $identifiant,
        public string $colonne,
        public string $attendu,
        public string $trouve,
        public string $regle,
    ) {}

    /**
     * La ligne du problème, telle que la commande l'affiche et que l'alerte de
     * l'éditeur la porte.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}
     */
    public function enLigne(): array
    {
        return [$this->couche, $this->controle, $this->table, $this->identifiant, $this->colonne, $this->attendu, $this->trouve, $this->regle];
    }
}
