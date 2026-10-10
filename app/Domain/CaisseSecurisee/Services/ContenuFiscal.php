<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\CategorieOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Tenancy\Models\Magasin;
use Illuminate\Support\Collection;

/**
 * Les données qu'un document signe au registre fiscal (voir
 * RegistreFiscal), lues en base au moment où il est validé : montants en
 * centimes, taux et remises en centièmes de point, dates en texte, jamais
 * un nombre à virgule flottante (voir CalculateurLigne::enCentiemes()).
 *
 * Un ticket, une facture ou un avoir (document()) signe deux blocs :
 *
 * - « document », qui se recalcule depuis ses colonnes figées et celles
 *   de ses lignes : numéro, date d'émission, client (son type et la copie
 *   prise à l'émission, voir FigeLeClient, ainsi que le drapeau du client
 *   comptoir, lu sur sa fiche où seul CreerMagasinAction le pose),
 *   franchise de TVA (voir ContexteDeVente), auteur, devise, échéance,
 *   conditions, notes ou motif, lignes, ventilation par taux et totaux.
 *   Un avoir s'y écrit en négatif (quantités et montants), cite le numéro
 *   et la date d'émission de chaque ticket ou facture qu'il corrige, et
 *   chacune de ses lignes y référence la ligne et le numéro du document
 *   qu'elle corrige. Une date d'émission effacée ou un client
 *   introuvable dans le magasin s'y recalcule vide, pour que la
 *   vérification de l'intégrité le signale au lieu de s'interrompre ;
 * - « contexte », figé à l'émission parce qu'il change ensuite ailleurs :
 *   l'identité du magasin émetteur (capital social et mentions entre
 *   professionnels compris), la catégorie de l'opération, les
 *   mentions fiscales imprimées (voir MentionsFiscales), et ce qui a
 *   réglé le document dans la même transaction (paiements et crédits
 *   d'avoir d'une facture, imputations d'un avoir sur ses factures). Un
 *   document qui vient d'être émis n'a pas pu en recevoir avant : un
 *   brouillon n'attend aucun règlement.
 *
 * Un paiement (encaissement()) signe son mode, son montant, sa référence,
 * sa date de réception, la fiche de son client ce jour-là et ce qu'il
 * règle de chaque facture.
 */
class ContenuFiscal
{
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly MentionsFiscales $mentions,
    ) {}

    /**
     * @return array{document: array<string, mixed>, contexte: array<string, mixed>}
     */
    public function document(Facture|Avoir $document): array
    {
        return [
            'document' => $this->blocDocument($document),
            'contexte' => $document instanceof Facture ? $this->contexteDeLaFacture($document) : $this->contexteDeLAvoir($document),
        ];
    }

    /**
     * Le seul bloc « document », recalculé depuis les colonnes figées du
     * document et de ses lignes : celui que la vérification de l'intégrité
     * (voir VerificateurIntegrite) compare au bloc signé à l'émission.
     *
     * @return array<string, mixed>
     */
    public function blocDocument(Facture|Avoir $document): array
    {
        return $document instanceof Facture ? $this->blocDeLaFacture($document) : $this->blocDeLAvoir($document);
    }

    /**
     * @return array{paiement: array<string, mixed>, client: array<string, mixed>, allocations: list<array{facture_id: int, numero: string|null, montant: int}>}
     */
    public function encaissement(Paiement $paiement): array
    {
        $paiement->load(['client', 'allocations.facture']);

        return [
            'paiement' => [
                'client_id' => (int) $paiement->client_id,
                'mode' => $paiement->mode_paiement->value,
                'montant' => $this->calculateur->enCentiemes($paiement->montant),
                'reference' => $paiement->reference,
                'notes' => $paiement->notes,
                'recu_le' => $paiement->recu_le->toDateString(),
                'cree_par' => $this->identifiant($paiement->cree_par),
            ],
            'client' => $this->client($paiement->client),
            'allocations' => $paiement->allocations->sortBy('id')
                ->map(fn (PaiementAllocation $allocation): array => [
                    'facture_id' => (int) $allocation->facture_id,
                    'numero' => $allocation->facture->numero,
                    'montant' => $this->calculateur->enCentiemes($allocation->montant_alloue),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocDeLaFacture(Facture $facture): array
    {
        $facture->load(['lignes', 'client']);

        return [
            'numero' => $facture->numero,
            'date_emission' => $facture->date_emission?->toIso8601String(),
            'client' => $this->clientFige($facture),
            'franchise_en_base' => $facture->franchise_en_base,
            'cree_par' => $this->identifiant($facture->cree_par),
            'devis_id' => $this->identifiant($facture->devis_id),
            'devise' => $facture->devise,
            'date_echeance' => $facture->date_echeance?->toDateString(),
            'conditions_paiement' => $facture->conditions_paiement,
            'notes' => $facture->notes,
            ...$this->lignesEtTotaux($facture->lignes, $facture, 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contexteDeLaFacture(Facture $facture): array
    {
        $facture->load(['lignes', 'magasin', 'allocationsPaiement.paiement', 'allocationsAvoir.avoir']);

        return [
            'emetteur' => $this->emetteur($facture->magasin),
            'categorie_operation' => CategorieOperation::depuisNatures($facture->lignes->pluck('nature'))->value,
            'mentions' => $this->mentions->pour($facture),
            'reglements' => $facture->allocationsPaiement->sortBy('id')
                ->map(fn (PaiementAllocation $allocation): array => [
                    'paiement_id' => (int) $allocation->paiement_id,
                    'mode' => $allocation->paiement->mode_paiement->value,
                    'reference' => $allocation->paiement->reference,
                    'montant' => $this->calculateur->enCentiemes($allocation->montant_alloue),
                ])
                ->values()
                ->all(),
            'credits_avoir' => $facture->allocationsAvoir->sortBy('id')
                ->map(fn (AvoirAllocation $allocation): array => [
                    'avoir_id' => (int) $allocation->avoir_id,
                    'numero' => $allocation->avoir->numero,
                    'montant' => $this->calculateur->enCentiemes($allocation->montant_alloue),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function blocDeLAvoir(Avoir $avoir): array
    {
        $avoir->load(['lignes.ligneFacture.facture', 'client']);
        $documentsCorriges = $avoir->facturesConcernees();

        return [
            'numero' => $avoir->numero,
            'date_emission' => $avoir->date_emission?->toIso8601String(),
            'client' => $this->clientFige($avoir),
            'franchise_en_base' => $avoir->franchise_en_base,
            'cree_par' => $this->identifiant($avoir->cree_par),
            'devise' => $avoir->devise,
            'motif' => $avoir->motif,
            'corrige' => $avoir->corrigeDesTickets() ? 'ticket' : 'facture',
            'documents_corriges' => $documentsCorriges
                ->map(fn (Facture $facture): array => [
                    'numero' => $facture->numero,
                    'date_emission' => $facture->date_emission?->toDateString(),
                ])
                ->all(),
            ...$this->lignesEtTotaux($avoir->lignes, $avoir, -1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contexteDeLAvoir(Avoir $avoir): array
    {
        $avoir->load(['lignes', 'magasin', 'allocations.facture']);

        return [
            'emetteur' => $this->emetteur($avoir->magasin),
            'categorie_operation' => CategorieOperation::depuisNatures($avoir->lignes->pluck('nature'))->value,
            'mentions' => $this->mentions->pour($avoir),
            'imputations' => $avoir->allocations->sortBy('id')
                ->map(fn (AvoirAllocation $allocation): array => [
                    'facture_id' => (int) $allocation->facture_id,
                    'numero' => $allocation->facture->numero,
                    'montant' => $this->calculateur->enCentiemes($allocation->montant_alloue),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Les lignes du document, dans leur ordre, la ventilation de leurs
     * montants par taux (du plus fort au plus faible) et les totaux du
     * document, tels qu'enregistrés : rien n'est recalculé ici. Le sens
     * (-1 pour un avoir) s'applique aux quantités et aux montants, jamais
     * aux prix unitaires, aux taux ni aux remises.
     *
     * @param  Collection<int, LigneFacture>|Collection<int, LigneAvoir>  $lignes
     * @return array{lignes: list<array<string, mixed>>, ventilation: list<array{taux: int, ht: int, tva: int, ttc: int}>, totaux: array{ht: int, tva: int, ttc: int}}
     */
    private function lignesEtTotaux(Collection $lignes, Facture|Avoir $document, int $sens): array
    {
        $lignesSignees = $lignes->map(fn (LigneFacture|LigneAvoir $ligne): array => [
            'ordre' => (int) $ligne->ordre,
            'nature' => $ligne->nature->value,
            'produit_id' => $this->identifiant($ligne->produit_id),
            ...($ligne instanceof LigneFacture ? ['service_id' => $this->identifiant($ligne->service_id)] : []),
            'reference' => $ligne->reference,
            'description' => $ligne->description,
            'quantite' => $sens * $ligne->quantite,
            'prix_unitaire_ht' => $this->calculateur->enCentiemes($ligne->prix_unitaire_ht),
            'prix_unitaire_ttc' => $this->calculateur->enCentiemes($ligne->prix_unitaire_ttc),
            'taux_tva' => $this->calculateur->enCentiemes($ligne->taux_tva),
            'remise' => $this->calculateur->enCentiemes($ligne->remise_pourcentage),
            'ht' => $sens * $this->calculateur->enCentiemes($ligne->montant_ht),
            'tva' => $sens * $this->calculateur->enCentiemes($ligne->montant_tva),
            'ttc' => $sens * $this->calculateur->enCentiemes($ligne->montant_ttc),
            ...($ligne instanceof LigneAvoir ? ['origine' => [
                'ligne_facture_id' => (int) $ligne->ligne_facture_id,
                'facture_id' => (int) $ligne->ligneFacture->facture_id,
                'numero' => $ligne->ligneFacture->facture->numero,
            ]] : []),
        ])->values();

        return [
            'lignes' => $lignesSignees->all(),
            'ventilation' => $lignesSignees
                ->groupBy('taux_tva')
                ->sortKeysDesc()
                ->map(fn (Collection $lignesDuTaux, int $taux): array => [
                    'taux' => $taux,
                    'ht' => $lignesDuTaux->sum('ht'),
                    'tva' => $lignesDuTaux->sum('tva'),
                    'ttc' => $lignesDuTaux->sum('ttc'),
                ])
                ->values()
                ->all(),
            'totaux' => [
                'ht' => $sens * $this->calculateur->enCentiemes($document->total_ht),
                'tva' => $sens * $this->calculateur->enCentiemes($document->total_tva),
                'ttc' => $sens * $this->calculateur->enCentiemes($document->total_ttc),
            ],
        ];
    }

    /**
     * L'identité du magasin le jour de l'émission (voir IdentiteMagasin,
     * ParametresFiscaux et ParametresFactures), qu'il peut changer
     * ensuite : le capital social en centimes (vide s'il n'est pas
     * renseigné) et les mentions qu'imprime une facture à un professionnel
     * (voir Magasin::mentionsEntreProfessionnels()).
     *
     * @return array<string, mixed>
     */
    private function emetteur(Magasin $magasin): array
    {
        return [
            'nom' => $magasin->nom,
            'raison_sociale' => $magasin->raison_sociale,
            'forme_juridique' => $magasin->forme_juridique,
            'capital_social' => $magasin->capital_social === null ? null : $this->calculateur->enCentiemes($magasin->capital_social),
            'siren' => $magasin->siren,
            'siret' => $magasin->siret,
            'immatriculation' => $magasin->immatriculation,
            'numero_tva' => $magasin->numero_tva,
            'adresse' => $magasin->adresse,
            'complement_adresse' => $magasin->complement_adresse,
            'code_postal' => $magasin->code_postal,
            'ville' => $magasin->ville,
            'pays' => $magasin->pays,
            'email' => $magasin->email,
            'telephone' => $magasin->telephone,
            'regime_tva' => $magasin->regime_tva?->value,
            'option_debits_tva' => (bool) $magasin->option_debits_tva,
            'mentions' => $magasin->mentionsEntreProfessionnels(),
        ];
    }

    /**
     * Le client du ticket, de la facture ou de l'avoir, lu sur ses colonnes
     * figées à l'émission (voir FigeLeClient) : son identifiant, le client
     * comptoir ou non (vide si le client_id ne désigne plus un client du
     * magasin), le type écrit sur le document et la copie du client.
     *
     * @return array<string, int|string|bool|null>
     */
    private function clientFige(Facture|Avoir $document): array
    {
        return [
            'id' => (int) $document->client_id,
            'comptoir' => $document->client?->estComptoir(),
            'type_client' => $document->type_client->value,
            ...$document->copieClient()->enTableau(),
        ];
    }

    /**
     * La fiche du client d'un paiement le jour de son enregistrement,
     * qu'il peut changer ensuite.
     *
     * @return array<string, int|string|bool|null>
     */
    private function client(Client $client): array
    {
        return [
            'id' => (int) $client->getKey(),
            'nom' => $client->nom,
            'type_client' => $client->type_client->value,
            'comptoir' => $client->estComptoir(),
            'siret' => $client->siret,
            'numero_tva' => $client->numero_tva,
            'adresse' => $client->adresse,
            'complement_adresse' => $client->complement_adresse,
            'code_postal' => $client->code_postal,
            'ville' => $client->ville,
            'pays' => $client->pays,
            'email' => $client->email,
            'telephone' => $client->telephone,
        ];
    }

    private function identifiant(int|string|null $identifiant): ?int
    {
        return $identifiant === null ? null : (int) $identifiant;
    }
}
