<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Le vendeur tel qu'un ticket, une facture, un avoir ou un devis
 * l'imprime : l'identité légale de la société (raison sociale, forme
 * juridique, capital social, SIREN, immatriculation, n° de TVA, siège ou,
 * pour un entrepreneur individuel, adresse de l'entreprise), ses réglages
 * fiscaux (régime de TVA, option pour les débits) et son assurance
 * professionnelle quand elle est renseignée, et l'établissement qui vend :
 * le nom du magasin, son SIRET, son adresse, ses coordonnées et les
 * mentions de ses factures entre professionnels (voir IdentiteSociete,
 * ParametresFiscauxSociete et ParametresFactures). L'établissement
 * s'imprime en tête du document (voir lignesDeLEtablissement()),
 * l'identité de la société et son assurance dans le pied de chaque page
 * (voir lignesDeLaSociete(), ligneDeLAssurance() et PiedDePage).
 *
 * Un ticket, une facture ou un avoir signe son émetteur au registre
 * fiscal à son émission (voir ContenuFiscal) et l'imprime ensuite tel
 * qu'il l'a signé, pour toujours, quoi que deviennent la société et le
 * magasin -- siège, forme, capital et assurance compris (voir
 * duContenuSigne() et FigeSonEmetteur). Un devis, jamais signé, fige le
 * sien sur sa ligne à chaque envoi et l'imprime ensuite de même jusqu'à
 * sa prochaine modification (voir Devis::emetteur()) ; un brouillon
 * l'imprime tel qu'ils sont aujourd'hui (voir duMagasin()).
 *
 * Le magasin de démonstration imprime une identité fictive, sans SIREN,
 * SIRET, assurance ni aucun champ libre du magasin, et chacun de ses
 * documents porte « DÉMONSTRATION — SANS VALEUR » (voir deDemonstration(),
 * filigrane() et bandeauDeDemonstration()).
 */
final readonly class Emetteur
{
    /**
     * La dénomination fictive du magasin de démonstration.
     */
    public const DENOMINATION_DE_DEMONSTRATION = 'ENTREPRISE DE DÉMONSTRATION';

    /**
     * @param  string|null  $formeJuridique  le libellé imprimé de la forme (voir Societe::formeImprimee())
     * @param  int|null  $capitalSocial  en centimes, null s'il n'est pas renseigné
     * @param  array{penalites_retard: string, indemnite_recouvrement: string, escompte: string}  $mentionsEntreProfessionnels  dans l'ordre où une facture à un professionnel les imprime
     */
    public function __construct(
        public string $nom,
        public ?string $raisonSociale,
        public ?string $formeJuridique,
        public bool $estEntrepreneurIndividuel,
        public ?int $capitalSocial,
        public bool $capitalVariable,
        public ?string $siren,
        public ?string $siret,
        public ?string $immatriculation,
        public ?string $numeroTva,
        public ?string $adresse,
        public ?string $complementAdresse,
        public ?string $codePostal,
        public ?string $ville,
        public string $pays,
        public ?string $siegeAdresse,
        public ?string $siegeComplementAdresse,
        public ?string $siegeCodePostal,
        public ?string $siegeVille,
        public ?string $email,
        public ?string $telephone,
        public ?RegimeTva $regimeTva,
        public bool $optionDebitsTva,
        public array $mentionsEntreProfessionnels,
        public ?string $assuranceAssureur,
        public ?string $assuranceAdresse,
        public ?string $assuranceContrat,
        public ?string $assuranceCouverture,
        public bool $estDemonstration = false,
    ) {}

    /**
     * La société et le magasin tels qu'ils sont aujourd'hui : l'émetteur
     * que signe un document à son émission, que fige un devis à son envoi,
     * et celui qu'imprime un brouillon. Celui du magasin de démonstration
     * est fictif (voir deDemonstration()).
     */
    public static function duMagasin(Magasin $magasin): self
    {
        if ($magasin->est_demonstration) {
            return self::deDemonstration($magasin);
        }

        /** @var Societe $societe */
        $societe = $magasin->societe;

        return new self(
            nom: $magasin->nom,
            raisonSociale: $societe->raison_sociale,
            formeJuridique: $societe->formeImprimee(),
            estEntrepreneurIndividuel: $societe->estEntrepreneurIndividuel(),
            capitalSocial: $societe->capital_social === null ? null : app(CalculateurLigne::class)->enCentiemes($societe->capital_social),
            capitalVariable: (bool) $societe->capital_variable,
            siren: $societe->siren,
            siret: $magasin->siret,
            immatriculation: $societe->immatriculation,
            numeroTva: $societe->numero_tva,
            adresse: $magasin->adresse,
            complementAdresse: $magasin->complement_adresse,
            codePostal: $magasin->code_postal,
            ville: $magasin->ville,
            pays: $magasin->pays,
            siegeAdresse: $societe->siege_adresse,
            siegeComplementAdresse: $societe->siege_complement_adresse,
            siegeCodePostal: $societe->siege_code_postal,
            siegeVille: $societe->siege_ville,
            email: $magasin->email,
            telephone: $magasin->telephone,
            regimeTva: $societe->regime_tva,
            optionDebitsTva: (bool) $societe->option_debits_tva,
            mentionsEntreProfessionnels: $magasin->mentionsEntreProfessionnels(),
            assuranceAssureur: $societe->assurance_assureur,
            assuranceAdresse: $societe->assurance_adresse,
            assuranceContrat: $societe->assurance_contrat,
            assuranceCouverture: $societe->assurance_couverture,
        );
    }

    /**
     * L'émetteur fictif du magasin de démonstration : la dénomination
     * « ENTREPRISE DE DÉMONSTRATION », aucun SIREN, SIRET, immatriculation,
     * n° de TVA, adresse, coordonnée ni assurance, et les mentions entre
     * professionnels du logiciel, jamais celles qu'un magasin a saisies ;
     * seuls le régime de TVA et l'option pour les débits de la société,
     * qui règlent ses calculs, sont les siens.
     */
    public static function deDemonstration(Magasin $magasin): self
    {
        $societe = $magasin->societe;

        return new self(
            nom: $magasin->nom,
            raisonSociale: self::DENOMINATION_DE_DEMONSTRATION,
            formeJuridique: null,
            estEntrepreneurIndividuel: false,
            capitalSocial: null,
            capitalVariable: false,
            siren: null,
            siret: null,
            immatriculation: null,
            numeroTva: null,
            adresse: null,
            complementAdresse: null,
            codePostal: null,
            ville: null,
            pays: 'FR',
            siegeAdresse: null,
            siegeComplementAdresse: null,
            siegeCodePostal: null,
            siegeVille: null,
            email: null,
            telephone: null,
            regimeTva: $societe->regime_tva,
            optionDebitsTva: (bool) $societe->option_debits_tva,
            mentionsEntreProfessionnels: [
                'penalites_retard' => Magasin::MENTION_PENALITES_PAR_DEFAUT,
                'indemnite_recouvrement' => Magasin::MENTION_INDEMNITE_PAR_DEFAUT,
                'escompte' => Magasin::MENTION_ESCOMPTE_PAR_DEFAUT,
            ],
            assuranceAssureur: null,
            assuranceAdresse: null,
            assuranceContrat: null,
            assuranceCouverture: null,
            estDemonstration: true,
        );
    }

    /**
     * L'émetteur que le contenu signé d'un document a retenu à son
     * émission : le bloc « emetteur » de son contexte (voir ContenuFiscal),
     * écrit par enTableau(), comme celui qu'un devis a figé à son envoi
     * (voir Devis::emetteur()).
     *
     * Chaque champ est lu pour ce qu'enTableau() y écrit ; un champ absent
     * ou d'un autre type est refusé. Le marqueur « demonstration » n'est
     * écrit que pour le magasin de démonstration.
     *
     * @param  array<array-key, mixed>  $emetteur
     *
     * @throws UnexpectedValueException un champ absent ou d'un autre type
     */
    public static function duContenuSigne(array $emetteur): self
    {
        $siege = ValeursLues::tableauDe($emetteur, 'siege');
        $mentions = ValeursLues::tableauDe($emetteur, 'mentions');
        $assurance = ValeursLues::tableauDe($emetteur, 'assurance');
        $regimeTva = ValeursLues::texteOuNulDe($emetteur, 'regime_tva');

        return new self(
            nom: ValeursLues::texteDe($emetteur, 'nom'),
            raisonSociale: ValeursLues::texteOuNulDe($emetteur, 'raison_sociale'),
            formeJuridique: ValeursLues::texteOuNulDe($emetteur, 'forme_juridique'),
            estEntrepreneurIndividuel: ValeursLues::booleenDe($emetteur, 'entrepreneur_individuel'),
            capitalSocial: ValeursLues::entierOuNulDe($emetteur, 'capital_social'),
            capitalVariable: ValeursLues::booleenDe($emetteur, 'capital_variable'),
            siren: ValeursLues::texteOuNulDe($emetteur, 'siren'),
            siret: ValeursLues::texteOuNulDe($emetteur, 'siret'),
            immatriculation: ValeursLues::texteOuNulDe($emetteur, 'immatriculation'),
            numeroTva: ValeursLues::texteOuNulDe($emetteur, 'numero_tva'),
            adresse: ValeursLues::texteOuNulDe($emetteur, 'adresse'),
            complementAdresse: ValeursLues::texteOuNulDe($emetteur, 'complement_adresse'),
            codePostal: ValeursLues::texteOuNulDe($emetteur, 'code_postal'),
            ville: ValeursLues::texteOuNulDe($emetteur, 'ville'),
            pays: ValeursLues::texteDe($emetteur, 'pays'),
            siegeAdresse: ValeursLues::texteOuNulDe($siege, 'adresse'),
            siegeComplementAdresse: ValeursLues::texteOuNulDe($siege, 'complement_adresse'),
            siegeCodePostal: ValeursLues::texteOuNulDe($siege, 'code_postal'),
            siegeVille: ValeursLues::texteOuNulDe($siege, 'ville'),
            email: ValeursLues::texteOuNulDe($emetteur, 'email'),
            telephone: ValeursLues::texteOuNulDe($emetteur, 'telephone'),
            regimeTva: $regimeTva === null ? null : RegimeTva::from($regimeTva),
            optionDebitsTva: ValeursLues::booleenDe($emetteur, 'option_debits_tva'),
            mentionsEntreProfessionnels: [
                'penalites_retard' => ValeursLues::texteDe($mentions, 'penalites_retard'),
                'indemnite_recouvrement' => ValeursLues::texteDe($mentions, 'indemnite_recouvrement'),
                'escompte' => ValeursLues::texteDe($mentions, 'escompte'),
            ],
            assuranceAssureur: ValeursLues::texteOuNulDe($assurance, 'assureur'),
            assuranceAdresse: ValeursLues::texteOuNulDe($assurance, 'adresse'),
            assuranceContrat: ValeursLues::texteOuNulDe($assurance, 'contrat'),
            assuranceCouverture: ValeursLues::texteOuNulDe($assurance, 'couverture'),
            estDemonstration: array_key_exists('demonstration', $emetteur) && ValeursLues::booleenDe($emetteur, 'demonstration'),
        );
    }

    /**
     * L'émetteur champ par champ, tel que le contenu signé au registre
     * fiscal l'écrit (voir ContenuFiscal) : sans nombre à virgule
     * flottante, le capital social en centimes, le régime par sa valeur ;
     * celui du magasin de démonstration, et lui seul, porte
     * « demonstration » à vrai, comme l'en-tête de ses enregistrements
     * (voir RegistreFiscal).
     *
     * @return array<string, mixed>
     */
    public function enTableau(): array
    {
        return [
            'nom' => $this->nom,
            'raison_sociale' => $this->raisonSociale,
            'forme_juridique' => $this->formeJuridique,
            'entrepreneur_individuel' => $this->estEntrepreneurIndividuel,
            'capital_social' => $this->capitalSocial,
            'capital_variable' => $this->capitalVariable,
            'siren' => $this->siren,
            'siret' => $this->siret,
            'immatriculation' => $this->immatriculation,
            'numero_tva' => $this->numeroTva,
            'adresse' => $this->adresse,
            'complement_adresse' => $this->complementAdresse,
            'code_postal' => $this->codePostal,
            'ville' => $this->ville,
            'pays' => $this->pays,
            'siege' => [
                'adresse' => $this->siegeAdresse,
                'complement_adresse' => $this->siegeComplementAdresse,
                'code_postal' => $this->siegeCodePostal,
                'ville' => $this->siegeVille,
            ],
            'email' => $this->email,
            'telephone' => $this->telephone,
            'regime_tva' => $this->regimeTva?->value,
            'option_debits_tva' => $this->optionDebitsTva,
            'mentions' => $this->mentionsEntreProfessionnels,
            'assurance' => [
                'assureur' => $this->assuranceAssureur,
                'adresse' => $this->assuranceAdresse,
                'contrat' => $this->assuranceContrat,
                'couverture' => $this->assuranceCouverture,
            ],
            ...($this->estDemonstration ? ['demonstration' => true] : []),
        ];
    }

    /**
     * Le filigrane de tout document du magasin de démonstration, ticket
     * compris, null pour un magasin réel.
     */
    public function filigrane(): ?string
    {
        return $this->estDemonstration ? 'DÉMONSTRATION — SANS VALEUR' : null;
    }

    /**
     * Le bandeau en tête d'un document du magasin de démonstration, null
     * pour un magasin réel : un ticket dit en plus qu'il ne se remet jamais
     * à un client.
     */
    public function bandeauDeDemonstration(bool $estUnTicket): ?string
    {
        if (! $this->estDemonstration) {
            return null;
        }

        return $estUnTicket ? 'TICKET DE DÉMONSTRATION — NE PAS REMETTRE À UN CLIENT' : $this->filigrane();
    }

    /**
     * La dénomination imprimée : la raison sociale, suivie de la mention
     * « EI » pour un entrepreneur individuel quand elle ne la porte pas
     * déjà en mot entier, entre parenthèses ou suivie d'un point compris
     * (S5, art. R526-27 du Code de commerce : le nom précédé ou suivi
     * immédiatement de la mention, voir Societe::porteLaMentionEi()) ;
     * l'entrepreneur n'a rien à taper.
     */
    public function denominationImprimee(): ?string
    {
        if (blank($this->raisonSociale) || ! $this->estEntrepreneurIndividuel || Societe::porteLaMentionEi($this->raisonSociale)) {
            return $this->raisonSociale;
        }

        return "{$this->raisonSociale} EI";
    }

    /**
     * L'établissement qui vend, imprimé en tête de chaque document sous le
     * nom du magasin, sans ce qui n'est pas renseigné : son adresse, fermée
     * par le pays, son SIRET, puis son téléphone et son adresse e-mail.
     *
     * @return list<string>
     */
    public function lignesDeLEtablissement(): array
    {
        $adresse = self::lignesDAdresse($this->adresse, $this->complementAdresse, $this->codePostal, $this->ville);

        return array_values(array_filter([
            ...$adresse,
            $adresse !== [] ? $this->pays : null,
            filled($this->siret) ? "SIRET : {$this->siret}" : null,
            implode(' · ', array_filter([filled($this->telephone) ? "Tél. {$this->telephone}" : null, $this->email], filled(...))),
        ], filled(...)));
    }

    /**
     * L'identité légale de la société, imprimée dans le pied de chaque page
     * de chaque document, sans ce qui n'est pas renseigné : la dénomination
     * et la forme juridique, « à capital variable » le cas échéant, suivies
     * du capital social quand il est renseigné (H2) ; le siège social
     * (l'adresse de l'entreprise d'un entrepreneur individuel), sauf s'il
     * est à l'adresse du magasin, ce qui est alors dit sans la répéter
     * (S3) ; le SIREN, puis l'immatriculation (K7) ; le n° de TVA.
     *
     * @return list<string>
     */
    public function lignesDeLaSociete(): array
    {
        $adresse = self::lignesDAdresse($this->adresse, $this->complementAdresse, $this->codePostal, $this->ville);
        $siege = self::lignesDAdresse($this->siegeAdresse, $this->siegeComplementAdresse, $this->siegeCodePostal, $this->siegeVille);
        $libelleDuSiege = $this->estEntrepreneurIndividuel ? 'Adresse de l\'entreprise' : 'Siège social';
        $siegeAuMagasin = $siege !== [] && self::normaliser($siege) === self::normaliser($adresse);
        $capital = $this->capitalSocial === null || $this->estEntrepreneurIndividuel
            ? null
            : ($this->capitalVariable ? 'à capital variable, ' : '').'au capital de '.number_format($this->capitalSocial / 100, 2, ',', ' ').' €';

        return array_values(array_filter([
            implode(' ', array_filter([$this->denominationImprimee(), $this->formeJuridique, $capital], filled(...))),
            match (true) {
                $siegeAuMagasin => "{$libelleDuSiege} et établissement",
                $siege !== [] => "{$libelleDuSiege} : ".implode(', ', $siege),
                default => null,
            },
            filled($this->siren) ? "SIREN : {$this->siren}" : null,
            $this->immatriculation,
            filled($this->numeroTva) ? "TVA intracommunautaire : {$this->numeroTva}" : null,
        ], filled(...)));
    }

    /**
     * L'assurance professionnelle de la société, imprimée dans le pied de
     * chaque page sous son identité (voir PiedDePage), sans les champs
     * vides : « Assurance professionnelle : contrat n° {contrat},
     * {assureur}, {adresse}, couverture : {couverture} » ; null sans
     * assureur ni garant, qui ne s'enregistre jamais sans lui (voir
     * ModifierIdentiteSocieteAction). La loi impose cette mention, sur
     * chaque devis et chaque facture, aux artisans dont l'activité exige
     * une assurance (art. 22-2 de la loi n° 96-603 du 5 juillet 1996).
     */
    public function ligneDeLAssurance(): ?string
    {
        if (blank($this->assuranceAssureur)) {
            return null;
        }

        return 'Assurance professionnelle : '.implode(', ', array_filter([
            filled($this->assuranceContrat) ? "contrat n° {$this->assuranceContrat}" : null,
            $this->assuranceAssureur,
            $this->assuranceAdresse,
            filled($this->assuranceCouverture) ? "couverture : {$this->assuranceCouverture}" : null,
        ], filled(...)));
    }

    /**
     * @return list<string>
     */
    private static function lignesDAdresse(?string $adresse, ?string $complement, ?string $codePostal, ?string $ville): array
    {
        return array_values(array_filter([$adresse, $complement, trim("{$codePostal} {$ville}")], filled(...)));
    }

    /**
     * Une adresse telle qu'elle se compare : sans casse, accents,
     * ponctuation ni espaces multiples.
     *
     * @param  list<string>  $lignes
     */
    private static function normaliser(array $lignes): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii(implode(' ', $lignes))))));
    }
}
