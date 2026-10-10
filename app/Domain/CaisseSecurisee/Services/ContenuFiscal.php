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
 * - « document », qui se recalcule depuis ses seules colonnes figées et
 *   celles de ses lignes : numéro, date d'émission, client, auteur,
 *   devise, échéance, conditions, notes ou motif, lignes, ventilation par
 *   taux et totaux. Un avoir s'y écrit en négatif (quantités et montants)
 *   et chacune de ses lignes y référence la ligne et le numéro du ticket
 *   ou de la facture qu'elle corrige ;
 * - « contexte », figé à l'émission parce qu'il change ensuite ailleurs :
 *   l'identité du magasin émetteur et la copie du client, la catégorie de
 *   l'opération, et ce qui a réglé le document dans la même transaction
 *   (paiements et crédits d'avoir d'une facture, imputations d'un avoir
 *   sur ses factures). Un document qui vient d'être émis n'a pas pu en
 *   recevoir avant : un brouillon n'attend aucun règlement.
 *
 * Un paiement (encaissement()) signe son mode, son montant, sa référence,
 * sa date de réception, son client et ce qu'il règle de chaque facture.
 */
class ContenuFiscal
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

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
        $facture->load('lignes');

        return [
            'numero' => $facture->numero,
            'date_emission' => $facture->date_emission->toIso8601String(),
            'client_id' => (int) $facture->client_id,
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
        $facture->load(['lignes', 'client', 'magasin', 'allocationsPaiement.paiement', 'allocationsAvoir.avoir']);

        return [
            'emetteur' => $this->emetteur($facture->magasin),
            'client' => $this->client($facture->client),
            'categorie_operation' => CategorieOperation::depuisNatures($facture->lignes->pluck('nature'))->value,
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
        $avoir->load('lignes.ligneFacture.facture');
        $documentsCorriges = $avoir->facturesConcernees();

        return [
            'numero' => $avoir->numero,
            'date_emission' => $avoir->date_emission->toIso8601String(),
            'client_id' => (int) $avoir->client_id,
            'cree_par' => $this->identifiant($avoir->cree_par),
            'devise' => $avoir->devise,
            'motif' => $avoir->motif,
            'corrige' => $avoir->corrigeDesTickets() ? 'ticket' : 'facture',
            'documents_corriges' => $documentsCorriges->pluck('numero')->all(),
            ...$this->lignesEtTotaux($avoir->lignes, $avoir, -1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contexteDeLAvoir(Avoir $avoir): array
    {
        $avoir->load(['lignes', 'client', 'magasin', 'allocations.facture']);

        return [
            'emetteur' => $this->emetteur($avoir->magasin),
            'client' => $this->client($avoir->client),
            'categorie_operation' => CategorieOperation::depuisNatures($avoir->lignes->pluck('nature'))->value,
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
     * L'identité du magasin le jour de l'émission (voir IdentiteMagasin et
     * ParametresFiscaux), qu'il peut changer ensuite.
     *
     * @return array<string, string|bool|null>
     */
    private function emetteur(Magasin $magasin): array
    {
        return [
            'nom' => $magasin->nom,
            'raison_sociale' => $magasin->raison_sociale,
            'forme_juridique' => $magasin->forme_juridique,
            'siren' => $magasin->siren,
            'siret' => $magasin->siret,
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
        ];
    }

    /**
     * La fiche du client le jour de l'émission, qu'il peut changer
     * ensuite : une donnée fiscale du document.
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
