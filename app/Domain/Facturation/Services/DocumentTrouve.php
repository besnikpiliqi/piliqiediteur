<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;

/**
 * Un document que trouve le téléchargement par période (voir
 * DocumentsDeLaPeriode), tel que la liste le montre : son type, son
 * magasin, son numéro, son jour dans le calendrier de son magasin, son
 * tiers (le client ou le fournisseur, tel que le document l'a figé quand il
 * le fige) et son TTC en centimes, tel qu'il est enregistré. Le relevé des
 * encaissements d'un magasin pour un mois a pour identifiant ce mois
 * (AAAA-MM). Sa clé, unique parmi tous les documents de toutes les
 * périodes, est ce que la sélection retient : jamais un document hors de
 * ceux que la personne peut voir, la clé n'étant qu'un choix parmi eux.
 */
final readonly class DocumentTrouve
{
    public function __construct(
        public TypeDeDocumentTelechargeable $type,
        public int $magasinId,
        public string $magasin,
        public string $identifiant,
        public string $numero,
        public string $jour,
        public ?string $tiers,
        public int $ttc,
        public ?string $origine = null,
    ) {}

    public function cle(): string
    {
        return "{$this->type->value}:{$this->magasinId}:{$this->identifiant}";
    }

    /**
     * Le mois de son jour, AAAA-MM : le dossier où le fichier ZIP le
     * range.
     */
    public function mois(): string
    {
        return substr($this->jour, 0, 7);
    }
}
