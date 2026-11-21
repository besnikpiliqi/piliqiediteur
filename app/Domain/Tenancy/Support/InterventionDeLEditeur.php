<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\CaisseSecurisee\ModuleFiscal;
use InvalidArgumentException;

/**
 * Une procédure exceptionnelle de l'éditeur en train de se faire (voir
 * ProcedureDeLEditeur) : sa justification, la référence de la pièce qui la
 * fonde, et les opérateurs authentifiés qui la lancent (voir
 * RegistreDesOperateurs). Le journal d'audit et le registre des
 * vérifications l'inscrivent avec chaque écriture de la procédure, origine
 * « éditeur » (voir JournalAudit::inscrire()).
 */
final readonly class InterventionDeLEditeur
{
    public const LONGUEUR_MINIMALE_DE_LA_JUSTIFICATION = 20;

    /**
     * @param  list<string>  $operateurs  les noms des opérateurs authentifiés
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $justification,
        public string $piece,
        public array $operateurs,
    ) {
        if (mb_strlen(trim($justification)) < self::LONGUEUR_MINIMALE_DE_LA_JUSTIFICATION) {
            throw new InvalidArgumentException('Une procédure de l\'éditeur se justifie en '.self::LONGUEUR_MINIMALE_DE_LA_JUSTIFICATION.' caractères au moins.');
        }

        if (trim($piece) === '' || $operateurs === []) {
            throw new InvalidArgumentException('Une procédure de l\'éditeur cite sa pièce et ses opérateurs authentifiés.');
        }
    }

    /**
     * Qui l'a lancée et d'où, tel que le journal d'audit et le registre des
     * vérifications le retiennent : les opérateurs, la pièce, l'utilisateur
     * système et l'hôte, la version du module fiscal.
     *
     * @return array{operateurs: list<string>, piece: string, utilisateur_systeme: string, hote: string, version_module: string}
     */
    public function operateur(): array
    {
        return [
            'operateurs' => $this->operateurs,
            'piece' => $this->piece,
            'utilisateur_systeme' => get_current_user(),
            'hote' => (string) gethostname(),
            'version_module' => ModuleFiscal::VERSION,
        ];
    }
}
