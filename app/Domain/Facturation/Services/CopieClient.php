<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Models\Client;

/**
 * Le client tel qu'un document de vente l'imprime et le signe : son nom,
 * son adresse et ses identifiants. Un ticket, une facture ou un avoir en
 * prend la copie à son émission (voir FigeLeClient) et la garde pour
 * toujours, quoi que devienne la fiche du client ; un brouillon ou un devis
 * la lit sur la fiche.
 */
final readonly class CopieClient
{
    public function __construct(
        public string $nom,
        public ?string $adresse,
        public ?string $complementAdresse,
        public ?string $codePostal,
        public ?string $ville,
        public string $pays,
        public ?string $siren,
        public ?string $siret,
        public ?string $numeroTva,
        public ?string $identifiantEtranger,
        public bool $etabliEnFrance,
    ) {}

    public static function depuisClient(Client $client): self
    {
        return new self(
            nom: $client->nom,
            adresse: $client->adresse,
            complementAdresse: $client->complement_adresse,
            codePostal: $client->code_postal,
            ville: $client->ville,
            pays: $client->pays,
            siren: $client->siren,
            siret: $client->siret,
            numeroTva: $client->numero_tva,
            identifiantEtranger: $client->identifiant_etranger,
            etabliEnFrance: $client->estEtabliEnFrance(),
        );
    }

    /**
     * La copie champ par champ, sous les noms de la fiche du client : les
     * colonnes client_* d'un document émis la reprennent préfixées (voir
     * FigeLeClient), et le contenu signé au registre fiscal tel quel (voir
     * ContenuFiscal).
     *
     * @return array{nom: string, adresse: string|null, complement_adresse: string|null, code_postal: string|null, ville: string|null, pays: string, siren: string|null, siret: string|null, numero_tva: string|null, identifiant_etranger: string|null, etabli_en_france: bool}
     */
    public function enTableau(): array
    {
        return [
            'nom' => $this->nom,
            'adresse' => $this->adresse,
            'complement_adresse' => $this->complementAdresse,
            'code_postal' => $this->codePostal,
            'ville' => $this->ville,
            'pays' => $this->pays,
            'siren' => $this->siren,
            'siret' => $this->siret,
            'numero_tva' => $this->numeroTva,
            'identifiant_etranger' => $this->identifiantEtranger,
            'etabli_en_france' => $this->etabliEnFrance,
        ];
    }

    /**
     * L'adresse imprimée sous le nom, sans ligne vide : rue, complément,
     * code postal et ville, et le pays quand il n'est pas la France.
     *
     * @return list<string>
     */
    public function lignesAdresse(): array
    {
        return array_values(array_filter([
            $this->adresse,
            $this->complementAdresse,
            trim("{$this->codePostal} {$this->ville}"),
            $this->pays === 'FR' ? null : $this->pays,
        ], filled(...)));
    }

    /**
     * Les identifiants renseignés, tels qu'ils s'impriment sur un document
     * à un professionnel.
     *
     * @return list<string>
     */
    public function identifiants(): array
    {
        return array_values(array_filter([
            filled($this->siren) ? "SIREN : {$this->siren}" : null,
            filled($this->siret) ? "SIRET : {$this->siret}" : null,
            filled($this->numeroTva) ? "N° TVA : {$this->numeroTva}" : null,
            filled($this->identifiantEtranger) ? "Identifiant : {$this->identifiantEtranger}" : null,
        ]));
    }
}
