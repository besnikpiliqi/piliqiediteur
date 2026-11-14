<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\TypeDeDocumentTelechargeable;

/**
 * Un document que trouve le téléchargement par période (voir
 * DocumentsDeLaPeriode), tel que la liste le montre : son type, son
 * magasin, son numéro, son jour dans le calendrier de son magasin, son
 * tiers (le client ou le fournisseur, tel que le document l'a figé quand il
 * le fige), son TTC en centimes, tel qu'il est enregistré, et, pour un
 * achat, son origine et la marque « pièce du fournisseur manquante ». Le
 * relevé des encaissements d'un magasin pour un mois a pour identifiant ce
 * mois (AAAA-MM), et dit s'il réunit les encaissements de toute l'équipe ou
 * ceux de la seule personne.
 *
 * Sa clé, unique parmi tous les documents de toutes les périodes, est ce
 * que la sélection retient, puis ce que le fichier préparé contient (voir
 * PreparerLeTelechargementDesDocumentsJob) : « type:magasin:identifiant »,
 * suivie, pour un relevé, de « :equipe » ou « :siens » ; jamais un document
 * hors de ceux que la personne peut voir, la clé n'étant qu'un choix parmi
 * eux. Le lien du fichier relit chaque clé pour savoir si la personne voit
 * encore ce document (voir DocumentsDeLaPeriode::nombreEncoreVisibles()).
 */
final readonly class DocumentTrouve
{
    /**
     * La portée d'un relevé des encaissements de toute l'équipe, puis
     * celle d'un relevé des seuls encaissements de la personne.
     */
    public const RELEVE_DE_L_EQUIPE = 'equipe';

    public const RELEVE_DE_LA_PERSONNE = 'siens';

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
        public bool $pieceManquante = false,
        public ?string $porteeDuReleve = null,
    ) {}

    public function cle(): string
    {
        return "{$this->type->value}:{$this->magasinId}:{$this->identifiant}".($this->porteeDuReleve === null ? '' : ":{$this->porteeDuReleve}");
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
