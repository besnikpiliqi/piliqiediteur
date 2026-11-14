<?php

namespace App\Integrations\EnvoiRecommande;

use App\Domain\Tenancy\Support\ValeursLues;
use UnexpectedValueException;

/**
 * Le destinataire d'un courrier recommandé à code : le représentant légal
 * de l'entreprise inscrit au registre national des entreprises, par son
 * nom (une personne morale dirigeante, par sa dénomination, « à
 * l'attention de son représentant »), à l'adresse officielle du siège de
 * l'entreprise (voir DestinataireOfficiel). L'adresse personnelle d'un
 * dirigeant n'est jamais utilisée : elle n'est pas publique au registre.
 */
final readonly class DestinataireDuCourrier
{
    public const A_L_ATTENTION_DE_SON_REPRESENTANT = 'À l\'attention de son représentant';

    /**
     * @param  string  $nom  « Jeanne DUPONT », ou la dénomination d'une personne morale dirigeante
     */
    public function __construct(
        public string $nom,
        public bool $personneMorale,
        public AdressePostale $adresse,
    ) {}

    /**
     * Le bloc du destinataire tel que le courrier l'imprime.
     *
     * @return list<string>
     */
    public function lignes(): array
    {
        return [$this->nom, ...($this->personneMorale ? [self::A_L_ATTENTION_DE_SON_REPRESENTANT] : []), ...$this->adresse->lignes()];
    }

    /**
     * @return array{nom: string, personne_morale: bool, adresse: array{denomination: string|null, adresse: string, complement_adresse: string|null, code_postal: string, ville: string, source: string}}
     */
    public function enTableau(): array
    {
        return ['nom' => $this->nom, 'personne_morale' => $this->personneMorale, 'adresse' => $this->adresse->enTableau()];
    }

    /**
     * @param  array<array-key, mixed>  $donnees  ce qu'a écrit enTableau(), relu de la base
     *
     * @throws UnexpectedValueException un tableau qui n'est pas celui qu'écrit enTableau()
     */
    public static function depuisTableau(array $donnees): self
    {
        return new self(ValeursLues::texteDe($donnees, 'nom'), ValeursLues::booleenDe($donnees, 'personne_morale'), AdressePostale::depuisTableau(ValeursLues::tableauDe($donnees, 'adresse')));
    }
}
