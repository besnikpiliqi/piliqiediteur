<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use Illuminate\Support\Str;

/**
 * Le vendeur tel qu'un ticket, une facture, un avoir ou un devis
 * l'imprime : l'identité légale de la société (raison sociale, forme
 * juridique, capital social, SIREN, immatriculation, n° de TVA, siège ou,
 * pour un entrepreneur individuel, adresse de l'entreprise), ses réglages
 * fiscaux (régime de TVA, option pour les débits), et l'établissement qui
 * vend : le nom du magasin, son SIRET, son adresse, ses coordonnées et les
 * mentions de ses factures entre professionnels (voir IdentiteSociete,
 * ParametresFiscauxSociete et ParametresFactures).
 *
 * Un ticket, une facture ou un avoir signe son émetteur au registre
 * fiscal à son émission (voir ContenuFiscal) et l'imprime ensuite tel
 * qu'il l'a signé, pour toujours, quoi que deviennent la société et le
 * magasin -- siège, forme et capital compris (voir duContenuSigne() et
 * FigeSonEmetteur) ; un brouillon ou un devis l'imprime tel qu'ils sont
 * aujourd'hui (voir duMagasin()).
 */
final readonly class Emetteur
{
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
    ) {}

    /**
     * La société et le magasin tels qu'ils sont aujourd'hui : l'émetteur
     * que signe un document à son émission, et celui qu'imprime un
     * brouillon ou un devis.
     */
    public static function duMagasin(Magasin $magasin): self
    {
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
        );
    }

    /**
     * L'émetteur que le contenu signé d'un document a retenu à son
     * émission : le bloc « emetteur » de son contexte (voir ContenuFiscal),
     * écrit par enTableau().
     *
     * @param  array<string, mixed>  $emetteur
     */
    public static function duContenuSigne(array $emetteur): self
    {
        return new self(
            nom: $emetteur['nom'],
            raisonSociale: $emetteur['raison_sociale'],
            formeJuridique: $emetteur['forme_juridique'],
            estEntrepreneurIndividuel: $emetteur['entrepreneur_individuel'],
            capitalSocial: $emetteur['capital_social'],
            capitalVariable: $emetteur['capital_variable'],
            siren: $emetteur['siren'],
            siret: $emetteur['siret'],
            immatriculation: $emetteur['immatriculation'],
            numeroTva: $emetteur['numero_tva'],
            adresse: $emetteur['adresse'],
            complementAdresse: $emetteur['complement_adresse'],
            codePostal: $emetteur['code_postal'],
            ville: $emetteur['ville'],
            pays: $emetteur['pays'],
            siegeAdresse: $emetteur['siege']['adresse'],
            siegeComplementAdresse: $emetteur['siege']['complement_adresse'],
            siegeCodePostal: $emetteur['siege']['code_postal'],
            siegeVille: $emetteur['siege']['ville'],
            email: $emetteur['email'],
            telephone: $emetteur['telephone'],
            regimeTva: $emetteur['regime_tva'] === null ? null : RegimeTva::from($emetteur['regime_tva']),
            optionDebitsTva: $emetteur['option_debits_tva'],
            mentionsEntreProfessionnels: $emetteur['mentions'],
        );
    }

    /**
     * L'émetteur champ par champ, tel que le contenu signé au registre
     * fiscal l'écrit (voir ContenuFiscal) : sans nombre à virgule
     * flottante, le capital social en centimes, le régime par sa valeur.
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
        ];
    }

    /**
     * La dénomination imprimée : la raison sociale, suivie de la mention
     * « EI » pour un entrepreneur individuel quand elle ne la porte pas
     * déjà en mot entier (S5, art. R526-27 du Code de commerce : le nom
     * précédé ou suivi immédiatement de la mention) ; l'entrepreneur n'a
     * rien à taper.
     */
    public function denominationImprimee(): ?string
    {
        if (blank($this->raisonSociale) || ! $this->estEntrepreneurIndividuel || preg_match('/(^|\s)(EI|Entrepreneur individuel)(\s|$)/iu', $this->raisonSociale) === 1) {
            return $this->raisonSociale;
        }

        return "{$this->raisonSociale} EI";
    }

    /**
     * Les lignes imprimées sous le nom du magasin, sans ce qui n'est pas
     * renseigné : la dénomination et la forme juridique, « à capital
     * variable » le cas échéant, suivies du capital social quand il est
     * renseigné (H2) ; l'adresse du magasin, fermée par le pays, et son
     * SIRET ; le siège social (l'adresse de l'entreprise d'un entrepreneur
     * individuel), sauf s'il est à l'adresse du magasin, qui le dit alors
     * (S3) ; le SIREN, puis l'immatriculation (K7) ; le n° de TVA ; le
     * téléphone et l'adresse e-mail.
     *
     * @return list<string>
     */
    public function lignesDIdentite(): array
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
            ...$adresse,
            $adresse !== [] ? $this->pays : null,
            filled($this->siret) ? "SIRET : {$this->siret}" : null,
            match (true) {
                $siegeAuMagasin => "{$libelleDuSiege} et établissement",
                $siege !== [] => "{$libelleDuSiege} : ".implode(', ', $siege),
                default => null,
            },
            filled($this->siren) ? "SIREN : {$this->siren}" : null,
            $this->immatriculation,
            filled($this->numeroTva) ? "TVA intracommunautaire : {$this->numeroTva}" : null,
            implode(' · ', array_filter([filled($this->telephone) ? "Tél. {$this->telephone}" : null, $this->email], filled(...))),
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
