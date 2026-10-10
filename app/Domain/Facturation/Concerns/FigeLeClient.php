<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Services\CopieClient;

/**
 * Un ticket, une facture ou un avoir émis ne change plus jamais : il garde
 * le client tel qu'il était le jour de son émission, son nom, son adresse,
 * ses identifiants et son type, même si la fiche du client change ensuite.
 *
 * Le type du client (type_client) s'écrit à chaque enregistrement du
 * brouillon et se contrôle à l'émission (voir EmettreFactureAction) ;
 * l'avoir prend celui des documents qu'il corrige (voir CreerAvoirAction).
 * La copie du client (colonnes client_*) se prend à l'émission (voir
 * figerLeClient()) et se signe au registre fiscal (voir ContenuFiscal) :
 * une fois le document numéroté, ProtegeLesDonneesValidees refuse de la
 * modifier, et la vérification de l'intégrité révèle une écriture directe
 * en base.
 */
trait FigeLeClient
{
    public function initializeFigeLeClient(): void
    {
        $this->mergeCasts([
            'type_client' => TypeClient::class,
            'client_etabli_en_france' => 'boolean',
        ]);
    }

    /**
     * Copie la fiche du client sur le document, à son émission, dans la
     * même sauvegarde que son numéro.
     */
    public function figerLeClient(Client $client): void
    {
        $colonnes = [];

        foreach (CopieClient::depuisClient($client)->enTableau() as $champ => $valeur) {
            $colonnes["client_{$champ}"] = $valeur;
        }

        $this->forceFill($colonnes);
    }

    /**
     * Le client que le document imprime : sa copie figée une fois le
     * document numéroté, la fiche du client tant qu'il est un brouillon.
     */
    public function copieClient(): CopieClient
    {
        if ($this->numero === null) {
            return CopieClient::depuisClient($this->client);
        }

        return new CopieClient(
            nom: $this->client_nom,
            adresse: $this->client_adresse,
            complementAdresse: $this->client_complement_adresse,
            codePostal: $this->client_code_postal,
            ville: $this->client_ville,
            pays: $this->client_pays,
            siren: $this->client_siren,
            siret: $this->client_siret,
            numeroTva: $this->client_numero_tva,
            identifiantEtranger: $this->client_identifiant_etranger,
            etabliEnFrance: $this->client_etabli_en_france,
        );
    }

    /**
     * Le type du client écrit sur le document, et non celui de sa fiche :
     * un ticket le reste même si son client devient professionnel depuis.
     */
    public function estPourUnProfessionnel(): bool
    {
        return $this->type_client === TypeClient::Professionnel;
    }
}
