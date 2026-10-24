<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Services\CopieClient;

/**
 * Un ticket, une facture ou un avoir émis ne change plus jamais : il garde
 * le client tel qu'il l'a imprimé à son émission, son nom, son adresse,
 * ses identifiants et son type, même si la fiche du client change ensuite.
 *
 * Le type du client (type_client) s'écrit à chaque enregistrement du
 * brouillon d'une facture et se contrôle à son émission (voir
 * EmettreFactureAction) ; l'avoir prend celui des documents qu'il corrige
 * (voir CreerAvoirAction). La copie du client (colonnes client_*) vient de
 * la même source que le type (voir clientAFiger()) : la fiche du client
 * pour une facture, le document corrigé pour un avoir. Elle se prend à
 * l'émission (voir figerLeClient()) et se signe au registre fiscal (voir
 * ContenuFiscal) : une fois le document numéroté, ProtegeLesDonneesValidees
 * refuse de la modifier, et la vérification de l'intégrité révèle une
 * écriture directe en base.
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
     * Le client que le document imprimera une fois émis, pris sur la même
     * source que son type.
     */
    abstract protected function clientAFiger(): CopieClient;

    /**
     * Copie le client sur le document, à son émission, dans la même
     * sauvegarde que son numéro.
     */
    public function figerLeClient(): void
    {
        $colonnes = [];

        foreach ($this->clientAFiger()->enTableau() as $champ => $valeur) {
            $colonnes["client_{$champ}"] = $valeur;
        }

        $this->forceFill($colonnes);
    }

    /**
     * Le client que le document imprime : sa copie figée une fois le
     * document numéroté, celle qu'il prendra à son émission tant qu'il est
     * un brouillon.
     */
    public function copieClient(): CopieClient
    {
        if ($this->numero === null) {
            return $this->clientAFiger();
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
     * Le nom du client affiché dans les listes et sur la fiche du
     * document : celui de la fiche du client tant que le document est un
     * brouillon, le nom figé une fois numéroté. Un document numéroté dont
     * la copie a été effacée en base, ce que signale la vérification de
     * l'intégrité, s'affiche alors sans nom au lieu d'empêcher d'afficher
     * les listes et sa fiche.
     */
    public function nomDuClient(): ?string
    {
        return $this->numero === null ? $this->client->nom : $this->client_nom;
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
