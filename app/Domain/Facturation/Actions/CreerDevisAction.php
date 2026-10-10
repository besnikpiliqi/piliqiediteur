<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Crée un devis brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire, et note
 * le type de ce client et si le magasin vend en franchise de TVA, que
 * reprend la facture qui en est tirée (voir ContexteDeVente et
 * CreerFactureDepuisDevisAction).
 */
class CreerDevisAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    public function handle(Client $client, array $lignes, array $attributs = []): Devis
    {
        return DB::transaction(function () use ($client, $lignes, $attributs) {
            $contexte = ContexteDeVente::pour($client, app(TenantContext::class)->magasin());
            $devis = new Devis($attributs);
            $devis->forceFill(['client_id' => $client->getKey(), ...$contexte->colonnes()])->save();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes, $contexte);

            foreach ($lignesConstruites as $ligne) {
                $devis->lignes()->create($ligne);
            }

            $devis->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $devis->fresh('lignes');
        });
    }
}
