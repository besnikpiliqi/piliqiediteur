<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Enums\SignalDeRisque;

/**
 * Où en est la vérification d'une société (voir EvaluateurDeVerification)
 * : l'entreprise et ses établissements (A), la preuve de la qualité de
 * représentant du propriétaire (B), le contrôle de l'adresse officielle
 * (C), les signaux de risque encore actifs, et ce qui en découle.
 */
final readonly class EtatDeVerification
{
    /** L'identité du propriétaire, vérifiée et rapprochée d'un dirigeant (B1). */
    public const PREUVE_IDENTITE_RAPPROCHEE = 'identite_rapprochee';

    /** Le mandat qu'un dirigeant vérifié a donné au propriétaire vérifié (B2). */
    public const PREUVE_MANDAT = 'mandat';

    /** La connexion vérifiée à la plateforme agréée au même SIREN (B3). */
    public const PREUVE_PLATEFORME = 'plateforme';

    /** Les pièces examinées par l'éditeur, avec le code du courrier (B4 et C). */
    public const PREUVE_PIECES_ET_COURRIER = 'pieces_et_courrier';

    /**
     * @param  bool  $entrepriseEtEtablissements  A : l'entreprise active au répertoire Sirene, et chaque magasin réel actif son établissement vérifié
     * @param  string|null  $preuveDeQualite  B : l'une des constantes PREUVE_*, null tant qu'aucune n'est réunie
     * @param  bool  $piecesExaminees  B4 : les pièces examinées par l'éditeur, qui n'attendent que le code du courrier
     * @param  bool  $identiteVerifiee  l'identité du propriétaire vérifiée par le prestataire, rapprochée ou non
     * @param  bool  $adresseControlee  C : le code d'un courrier ou d'une lettre électronique saisi
     * @param  list<SignalDeRisque>  $signauxActifs
     * @param  bool  $autreSocieteValidee  une autre société du même SIREN est validée
     */
    public function __construct(
        public bool $entrepriseEtEtablissements,
        public ?string $preuveDeQualite,
        public bool $piecesExaminees,
        public bool $identiteVerifiee,
        public bool $adresseControlee,
        public array $signauxActifs,
        public bool $autreSocieteValidee,
        public bool $validee,
        public bool $refusee,
        public bool $active,
    ) {}

    /**
     * La société peut-elle être validée ? A, une preuve de qualité, aucun
     * signal actif, aucune autre société du même SIREN validée, et ni déjà
     * validée, ni refusée, ni suspendue.
     */
    public function estValidable(): bool
    {
        return ! $this->validee && ! $this->refusee && $this->active
            && $this->entrepriseEtEtablissements
            && $this->preuveDeQualite !== null
            && $this->signauxActifs === []
            && ! $this->autreSocieteValidee;
    }

    /**
     * Une preuve de qualité alors qu'une autre société du même SIREN est
     * validée : la réclamation que l'éditeur peut ouvrir (voir
     * editeur:reclamation:ouvrir). Seule une preuve de qualité révèle ce
     * conflit.
     */
    public function reclamationPossible(): bool
    {
        return ! $this->validee && $this->preuveDeQualite !== null && $this->autreSocieteValidee;
    }

    /**
     * Un courrier recommandé ou une lettre électronique ferait-il avancer la
     * vérification ? Les pièces examinées l'attendent, ou un signal actif
     * peut se lever par lui.
     */
    public function attendUnCourrier(): bool
    {
        if ($this->adresseControlee) {
            return false;
        }

        return ($this->piecesExaminees && $this->preuveDeQualite === null)
            || collect($this->signauxActifs)->contains(fn (SignalDeRisque $signal): bool => collect($signal->leveePar())->flatten()->intersect([SignalDeRisque::COURRIER, SignalDeRisque::COURRIER_A_L_ADRESSE_DU_RNE, SignalDeRisque::LRE])->isNotEmpty());
    }

    /**
     * L'état tel que la société le voit : les signaux qui tiennent à une
     * autre société du même SIREN n'y paraissent qu'après une preuve de sa
     * qualité (identité rapprochée, mandat, plateforme, ou pièces
     * examinées), car aucun écran ne révèle qu'un SIREN est déjà client
     * avant elle ; ni eux ni ce qu'ils font attendre (un courrier) ne se
     * devinent avant.
     */
    public function vuParLaSociete(): self
    {
        $prouvee = $this->preuveDeQualite !== null || $this->piecesExaminees;

        return new self(
            entrepriseEtEtablissements: $this->entrepriseEtEtablissements,
            preuveDeQualite: $this->preuveDeQualite,
            piecesExaminees: $this->piecesExaminees,
            identiteVerifiee: $this->identiteVerifiee,
            adresseControlee: $this->adresseControlee,
            signauxActifs: $prouvee ? $this->signauxActifs : array_values(array_filter($this->signauxActifs, fn (SignalDeRisque $signal): bool => ! $signal->tientAUneAutreSociete())),
            autreSocieteValidee: $this->autreSocieteValidee,
            validee: $this->validee,
            refusee: $this->refusee,
            active: $this->active,
        );
    }

    /**
     * Les voies automatiques sont-elles bloquées jusqu'à une décision de
     * l'éditeur (voir SignalDeRisque::bloqueLesVoiesAutomatiques()) ?
     */
    public function voiesAutomatiquesBloquees(): bool
    {
        return collect($this->signauxActifs)->contains(fn (SignalDeRisque $signal): bool => $signal->bloqueLesVoiesAutomatiques());
    }
}
