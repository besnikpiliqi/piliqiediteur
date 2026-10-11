<?php

namespace App\Integrations\Identite;

use App\Domain\Tenancy\Enums\ResultatVerification;

/**
 * Le résultat d'une vérification d'identité, réduit à la liste blanche que
 * PILIQI retient (voir depuisLaReponse()) : le statut, le nom de naissance
 * et d'usage, les prénoms, le mois et l'année de naissance, et le niveau
 * de garantie. Jamais l'image, le numéro ni la date complète d'une pièce,
 * ni l'adresse de la personne, même si le prestataire les donne.
 */
final readonly class ResultatDIdentite
{
    /**
     * @param  list<string>  $prenoms
     * @param  string|null  $naissance  AAAA-MM
     */
    public function __construct(
        public ResultatVerification $statut,
        public ?string $nom = null,
        public ?string $nomUsage = null,
        public array $prenoms = [],
        public ?string $naissance = null,
        public ?string $niveauGarantie = null,
    ) {}

    /**
     * Lit la réponse d'un prestataire, et seulement les clés de la liste
     * blanche : « statut » (verifiee, refusee, a_examiner, en_cours),
     * « nom », « nom_usage », « prenoms », « date_naissance » (dont seuls le
     * mois et l'année sont gardés) et « niveau_garantie ». Un statut inconnu
     * n'est jamais tenu pour une identité vérifiée : il est à examiner.
     *
     * @param  array<mixed>  $reponse
     */
    public static function depuisLaReponse(array $reponse): self
    {
        $statut = match ($reponse['statut'] ?? null) {
            'verifiee' => ResultatVerification::Concluante,
            'refusee' => ResultatVerification::Negative,
            'en_cours' => ResultatVerification::EnAttente,
            default => ResultatVerification::AExaminer,
        };
        $naissance = is_string($reponse['date_naissance'] ?? null) && preg_match('/^(\d{4})-(\d{2})/', $reponse['date_naissance'], $date) === 1
            ? "{$date[1]}-{$date[2]}"
            : null;

        return new self(
            statut: $statut,
            nom: self::texte($reponse['nom'] ?? null),
            nomUsage: self::texte($reponse['nom_usage'] ?? null),
            prenoms: array_values(array_filter(array_map(self::texte(...), is_array($reponse['prenoms'] ?? null) ? $reponse['prenoms'] : []))),
            naissance: $naissance,
            niveauGarantie: self::texte($reponse['niveau_garantie'] ?? null),
        );
    }

    /**
     * L'identité concluante qu'une ligne du registre des vérifications a
     * retenue (voir details()) : de quoi la rapprocher de nouveau des
     * dirigeants quand le registre national ne répondait pas.
     *
     * @param  array<string, mixed>  $details
     */
    public static function depuisLesDetails(array $details): self
    {
        return new self(
            statut: ResultatVerification::Concluante,
            nom: $details['nom'] ?? null,
            nomUsage: $details['nom_usage'] ?? null,
            prenoms: $details['prenoms'] ?? [],
            naissance: $details['naissance'] ?? null,
            niveauGarantie: $details['niveau_garantie'] ?? null,
        );
    }

    /**
     * La personne telle que l'écran et la lettre d'information la nomment
     * : « Jeanne DUPONT ».
     */
    public function nomComplet(): string
    {
        return trim(($this->prenoms[0] ?? '').' '.($this->nom ?? ''));
    }

    /**
     * Les détails que retient la ligne d'identité du registre des
     * vérifications.
     *
     * @return array{nom: string|null, nom_usage: string|null, prenoms: list<string>, naissance: string|null, niveau_garantie: string|null}
     */
    public function details(): array
    {
        return [
            'nom' => $this->nom,
            'nom_usage' => $this->nomUsage,
            'prenoms' => $this->prenoms,
            'naissance' => $this->naissance,
            'niveau_garantie' => $this->niveauGarantie,
        ];
    }

    private static function texte(mixed $valeur): ?string
    {
        return is_string($valeur) && trim($valeur) !== '' ? trim($valeur) : null;
    }
}
