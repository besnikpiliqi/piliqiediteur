<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Crée un devis brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire, et note
 * le type de ce client, si le magasin vend en franchise de TVA, la nature
 * de l'opération, que ce client et ce régime doivent permettre (voir
 * ContexteDeVente::pourEnregistrer()), et la livraison (voir
 * NoteSaNatureDOperation), que reprend la facture qui en est tirée (voir
 * CreerFactureDepuisDevisAction). Son auteur, relu en base au moment où
 * elle écrit, doit pouvoir créer un devis dans le magasin actif (voir
 * LecteurDesDroits), et être autorisé à accorder des remises et fixer les
 * prix pour une ligne remisée ou à un autre prix que celui de la fiche du
 * produit ou du prix de référence du service (R2, voir DerogationsDePrix).
 */
class CreerDevisAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly LecteurDesDroits $lecteur,
        private readonly DerogationsDePrix $derogations,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs  date_validite, conditions_paiement, notes, et la nature et la livraison (voir NoteSaNatureDOperation::noterSaNatureDOperation())
     *
     * @throws AuthorizationException
     */
    public function handle(User $auteur, Client $client, array $lignes, array $attributs = []): Devis
    {
        return DB::transaction(function () use ($auteur, $client, $lignes, $attributs) {
            $compte = $this->lecteur->exiger($auteur, 'create', app(TenantContext::class)->magasin(), Devis::class);
            $devis = new Devis($attributs);
            $contexte = ContexteDeVente::pourEnregistrer($client, app(TenantContext::class)->magasin(), $devis->noterSaNatureDOperation($attributs));
            $document = $this->constructeurLigne->construireToutes($lignes, $contexte);
            $this->derogations->exiger($compte, [], $document->lignes, $contexte);
            $devis->forceFill(['client_id' => $client->getKey(), ...$contexte->colonnes()])->save();

            foreach ($document->lignes as $ligne) {
                $devis->lignes()->create($ligne);
            }

            $devis->forceFill($document->totaux)->save();

            return $devis->fresh('lignes');
        });
    }
}
