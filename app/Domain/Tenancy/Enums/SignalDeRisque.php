<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Un signal de risque d'usurpation sur une société en cours de
 * vérification (voir SignauxDeRisque, qui les relève) : tant qu'un signal
 * est actif, la société n'est pas validée, et chacun ne se lève que par la
 * vérification qu'il exige (voir leveePar()), jamais par une autre. Les
 * pièces examinées par l'éditeur (B4) n'en lèvent aucun.
 *
 * Un signal tiré de l'état de la société (diffusion partielle, autre
 * inscription du même SIREN, forme ou adresse contraires au répertoire...)
 * disparaît aussi de lui-même quand son état cesse : une forme corrigée
 * n'est plus contraire. Un signal constaté (essais répétés, doute de
 * l'éditeur, pli revenu) reste jusqu'à sa levée par l'éditeur.
 */
enum SignalDeRisque: string implements HasLabel
{
    case DiffusionPartielle = 'diffusion_partielle';
    case InscriptionsConcurrentes = 'inscriptions_concurrentes';
    case AntecedentUsurpation = 'antecedent_usurpation';
    case DirigeantRecent = 'dirigeant_recent';
    case FormeContradictoire = 'forme_contradictoire';
    case AdresseContradictoire = 'adresse_contradictoire';
    case IdentiteNonConcluante = 'identite_non_concluante';
    case EssaisRepetes = 'essais_repetes';
    case DecisionEditeur = 'decision_editeur';

    /**
     * Le code d'une lettre recommandée électronique qualifiée remise à un
     * dirigeant identifié, saisi dans le compte.
     */
    public const LRE = 'lre';

    /**
     * Le code d'un courrier recommandé à l'adresse officielle, saisi dans
     * le compte.
     */
    public const COURRIER = 'courrier';

    /**
     * Le code d'un courrier recommandé à l'adresse du siège lue au
     * registre national des entreprises, quand le répertoire Sirene la
     * masque.
     */
    public const COURRIER_A_L_ADRESSE_DU_RNE = 'courrier_rne';

    /**
     * La levée motivée de ce signal par l'éditeur, après son dernier
     * constat.
     */
    public const MANUELLE = 'manuelle';

    public function getLabel(): string
    {
        return match ($this) {
            self::DiffusionPartielle => 'Entreprise en diffusion partielle au répertoire Sirene',
            self::InscriptionsConcurrentes => 'Une autre inscription du même SIREN a prouvé sa qualité',
            self::AntecedentUsurpation => 'Une inscription de ce SIREN a déjà été révoquée',
            self::DirigeantRecent => 'Dirigeant en fonction depuis moins de 30 jours',
            self::FormeContradictoire => 'Forme juridique contraire au répertoire Sirene',
            self::AdresseContradictoire => 'Adresse contraire au répertoire Sirene, ou courrier revenu',
            self::IdentiteNonConcluante => 'Identité non concluante ou sans dirigeant correspondant',
            self::EssaisRepetes => 'Essais répétés (codes faux ou vérifications d\'identité échouées)',
            self::DecisionEditeur => 'Doute de l\'éditeur',
        };
    }

    /**
     * Tient-il à une autre société du même SIREN (une autre inscription qui
     * a prouvé sa qualité, une révocation) ? Aucun écran ne le montre à la
     * société avant une preuve de sa propre qualité (voir
     * EtatDeVerification::vuParLaSociete()).
     */
    public function tientAUneAutreSociete(): bool
    {
        return $this === self::InscriptionsConcurrentes || $this === self::AntecedentUsurpation;
    }

    /**
     * Le signal tel que la société le lit : ceux qui tiennent à une autre
     * société du même SIREN ne le disent pas, car aucun écran ne révèle
     * qu'un SIREN est déjà client.
     */
    public function libellePourLaSociete(): string
    {
        return $this->tientAUneAutreSociete() ? 'Un examen de votre dossier par l\'éditeur est nécessaire.' : $this->getLabel();
    }

    /**
     * Ce qui le lève : l'une des alternatives, chacune réunissant toutes
     * ses vérifications (voir les constantes de cette énumération).
     *
     * @return list<list<string>>
     */
    public function leveePar(): array
    {
        return match ($this) {
            self::DiffusionPartielle => [[self::LRE], [self::COURRIER_A_L_ADRESSE_DU_RNE], [self::MANUELLE]],
            self::InscriptionsConcurrentes, self::AntecedentUsurpation, self::IdentiteNonConcluante => [[self::COURRIER, self::MANUELLE]],
            self::DirigeantRecent => [[self::COURRIER], [self::LRE]],
            self::FormeContradictoire, self::AdresseContradictoire, self::EssaisRepetes, self::DecisionEditeur => [[self::MANUELLE]],
        };
    }

    /**
     * Bloque-t-il les voies automatiques (vérification d'identité, demande
     * d'accord d'un dirigeant, courrier demandé par le propriétaire, saisie
     * d'un code) jusqu'à sa levée par l'éditeur ?
     */
    public function bloqueLesVoiesAutomatiques(): bool
    {
        return $this === self::EssaisRepetes;
    }
}
