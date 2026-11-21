<?php

namespace App\Integrations\Rne;

/**
 * Un dirigeant d'une entreprise tel que le registre national des
 * entreprises le donne (voir RegistreNationalDesEntreprises) : une
 * personne physique, avec ses nom de naissance et d'usage, ses prénoms et
 * le mois et l'année de sa naissance (seuls publics), ou une personne
 * morale (une holding présidente), avec son SIREN ; son rôle ; s'il a le
 * pouvoir de représenter l'entreprise (gérant, président, directeur
 * général...) ; le jour où il a pris ses fonctions, quand le registre le
 * dit.
 */
final readonly class RepresentantRne
{
    /**
     * @param  string  $nom  le nom de naissance d'une personne physique, la dénomination d'une personne morale
     * @param  list<string>  $prenoms
     * @param  string|null  $naissance  AAAA-MM
     * @param  string|null  $sirenPersonneMorale  le SIREN d'un dirigeant personne morale, null pour une personne physique
     * @param  string|null  $debutFonction  AAAA-MM-JJ
     */
    public function __construct(
        public string $nom,
        public ?string $nomUsage,
        public array $prenoms,
        public ?string $naissance,
        public string $role,
        public bool $pouvoirDeRepresentation,
        public ?string $sirenPersonneMorale,
        public ?string $debutFonction,
    ) {}

    public function estUnePersonneMorale(): bool
    {
        return $this->sirenPersonneMorale !== null;
    }

    /**
     * Le dirigeant tel que le courrier recommandé le nomme : « Jeanne
     * DUPONT », son premier prénom et son nom d'usage, ou de naissance ;
     * une personne morale, par sa dénomination.
     */
    public function nomPourLeCourrier(): string
    {
        return $this->estUnePersonneMorale() ? $this->nom : trim(($this->prenoms[0] ?? '').' '.($this->nomUsage ?? $this->nom));
    }

    /**
     * @return array{nom: string, nom_usage: string|null, prenoms: list<string>, naissance: string|null, role: string, pouvoir_de_representation: bool, siren_personne_morale: string|null, debut_fonction: string|null}
     */
    public function enTableau(): array
    {
        return [
            'nom' => $this->nom,
            'nom_usage' => $this->nomUsage,
            'prenoms' => $this->prenoms,
            'naissance' => $this->naissance,
            'role' => $this->role,
            'pouvoir_de_representation' => $this->pouvoirDeRepresentation,
            'siren_personne_morale' => $this->sirenPersonneMorale,
            'debut_fonction' => $this->debutFonction,
        ];
    }

    /**
     * @param  array{nom: string, nom_usage: string|null, prenoms: list<string>, naissance: string|null, role: string, pouvoir_de_representation: bool, siren_personne_morale: string|null, debut_fonction: string|null}  $donnees  ce qu'a écrit enTableau()
     */
    public static function depuisTableau(array $donnees): self
    {
        return new self(
            nom: $donnees['nom'],
            nomUsage: $donnees['nom_usage'],
            prenoms: $donnees['prenoms'],
            naissance: $donnees['naissance'],
            role: $donnees['role'],
            pouvoirDeRepresentation: $donnees['pouvoir_de_representation'],
            sirenPersonneMorale: $donnees['siren_personne_morale'],
            debutFonction: $donnees['debut_fonction'],
        );
    }
}
