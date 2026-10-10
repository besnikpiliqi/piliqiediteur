<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;

/**
 * Le vendeur tel qu'un ticket, une facture, un avoir ou un devis
 * l'imprime : le nom du magasin, son identité légale (raison sociale,
 * forme juridique, capital social, SIREN, SIRET, immatriculation, n° de
 * TVA), son adresse, ses coordonnées, son régime de TVA, son option pour
 * les débits et les mentions de ses factures entre professionnels (voir
 * IdentiteMagasin, ParametresFiscaux et ParametresFactures).
 *
 * Un ticket, une facture ou un avoir signe son émetteur au registre
 * fiscal à son émission (voir ContenuFiscal) et l'imprime ensuite tel
 * qu'il l'a signé, pour toujours, quoi que devienne le magasin (voir
 * duContenuSigne() et FigeSonEmetteur) ; un brouillon ou un devis
 * l'imprime tel que le magasin est aujourd'hui (voir duMagasin()).
 */
final readonly class Emetteur
{
    /**
     * @param  int|null  $capitalSocial  en centimes, null s'il n'est pas renseigné
     * @param  array{penalites_retard: string, indemnite_recouvrement: string, escompte: string}  $mentionsEntreProfessionnels  dans l'ordre où une facture à un professionnel les imprime
     */
    public function __construct(
        public string $nom,
        public ?string $raisonSociale,
        public ?string $formeJuridique,
        public ?int $capitalSocial,
        public ?string $siren,
        public ?string $siret,
        public ?string $immatriculation,
        public ?string $numeroTva,
        public ?string $adresse,
        public ?string $complementAdresse,
        public ?string $codePostal,
        public ?string $ville,
        public string $pays,
        public ?string $email,
        public ?string $telephone,
        public ?RegimeTva $regimeTva,
        public bool $optionDebitsTva,
        public array $mentionsEntreProfessionnels,
    ) {}

    /**
     * Le magasin tel qu'il est aujourd'hui : l'émetteur que signe un
     * document à son émission, et celui qu'imprime un brouillon ou un
     * devis.
     */
    public static function duMagasin(Magasin $magasin): self
    {
        return new self(
            nom: $magasin->nom,
            raisonSociale: $magasin->raison_sociale,
            formeJuridique: $magasin->forme_juridique,
            capitalSocial: $magasin->capital_social === null ? null : app(CalculateurLigne::class)->enCentiemes($magasin->capital_social),
            siren: $magasin->siren,
            siret: $magasin->siret,
            immatriculation: $magasin->immatriculation,
            numeroTva: $magasin->numero_tva,
            adresse: $magasin->adresse,
            complementAdresse: $magasin->complement_adresse,
            codePostal: $magasin->code_postal,
            ville: $magasin->ville,
            pays: $magasin->pays,
            email: $magasin->email,
            telephone: $magasin->telephone,
            regimeTva: $magasin->regime_tva,
            optionDebitsTva: (bool) $magasin->option_debits_tva,
            mentionsEntreProfessionnels: $magasin->mentionsEntreProfessionnels(),
        );
    }

    /**
     * L'émetteur que le contenu signé d'un document a retenu à son
     * émission : le bloc « emetteur » de son contexte (voir ContenuFiscal),
     * écrit par enTableau().
     *
     * @param  array{nom: string, raison_sociale: string|null, forme_juridique: string|null, capital_social: int|null, siren: string|null, siret: string|null, immatriculation: string|null, numero_tva: string|null, adresse: string|null, complement_adresse: string|null, code_postal: string|null, ville: string|null, pays: string, email: string|null, telephone: string|null, regime_tva: string|null, option_debits_tva: bool, mentions: array{penalites_retard: string, indemnite_recouvrement: string, escompte: string}}  $emetteur
     */
    public static function duContenuSigne(array $emetteur): self
    {
        return new self(
            nom: $emetteur['nom'],
            raisonSociale: $emetteur['raison_sociale'],
            formeJuridique: $emetteur['forme_juridique'],
            capitalSocial: $emetteur['capital_social'],
            siren: $emetteur['siren'],
            siret: $emetteur['siret'],
            immatriculation: $emetteur['immatriculation'],
            numeroTva: $emetteur['numero_tva'],
            adresse: $emetteur['adresse'],
            complementAdresse: $emetteur['complement_adresse'],
            codePostal: $emetteur['code_postal'],
            ville: $emetteur['ville'],
            pays: $emetteur['pays'],
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
     * @return array{nom: string, raison_sociale: string|null, forme_juridique: string|null, capital_social: int|null, siren: string|null, siret: string|null, immatriculation: string|null, numero_tva: string|null, adresse: string|null, complement_adresse: string|null, code_postal: string|null, ville: string|null, pays: string, email: string|null, telephone: string|null, regime_tva: string|null, option_debits_tva: bool, mentions: array{penalites_retard: string, indemnite_recouvrement: string, escompte: string}}
     */
    public function enTableau(): array
    {
        return [
            'nom' => $this->nom,
            'raison_sociale' => $this->raisonSociale,
            'forme_juridique' => $this->formeJuridique,
            'capital_social' => $this->capitalSocial,
            'siren' => $this->siren,
            'siret' => $this->siret,
            'immatriculation' => $this->immatriculation,
            'numero_tva' => $this->numeroTva,
            'adresse' => $this->adresse,
            'complement_adresse' => $this->complementAdresse,
            'code_postal' => $this->codePostal,
            'ville' => $this->ville,
            'pays' => $this->pays,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'regime_tva' => $this->regimeTva?->value,
            'option_debits_tva' => $this->optionDebitsTva,
            'mentions' => $this->mentionsEntreProfessionnels,
        ];
    }

    /**
     * Les lignes imprimées sous le nom du magasin, sans ce qu'il n'a pas
     * renseigné : raison sociale et forme juridique, suivies du capital
     * social quand il est renseigné (H2) ; l'adresse, fermée par le pays ;
     * le SIREN et le SIRET, puis l'immatriculation (K7) ; le n° de TVA ;
     * le téléphone et l'adresse e-mail.
     *
     * @return list<string>
     */
    public function lignesDIdentite(): array
    {
        $adresse = array_filter([
            $this->adresse,
            $this->complementAdresse,
            trim("{$this->codePostal} {$this->ville}"),
        ], filled(...));
        $capital = $this->capitalSocial === null ? null : 'au capital de '.number_format($this->capitalSocial / 100, 2, ',', ' ').' €';

        return array_values(array_filter([
            implode(' ', array_filter([$this->raisonSociale, $this->formeJuridique, $capital], filled(...))),
            ...$adresse,
            $adresse !== [] ? $this->pays : null,
            filled($this->siren) ? "SIREN : {$this->siren}" : null,
            filled($this->siret) ? "SIRET : {$this->siret}" : null,
            $this->immatriculation,
            filled($this->numeroTva) ? "TVA intracommunautaire : {$this->numeroTva}" : null,
            implode(' · ', array_filter([filled($this->telephone) ? "Tél. {$this->telephone}" : null, $this->email], filled(...))),
        ], filled(...)));
    }
}
