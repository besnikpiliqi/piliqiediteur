<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use Illuminate\Support\Facades\DB;

/**
 * Crée un devis brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire, et note
 * le type de ce client, que reprend la facture qui en est tirée (voir
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
            $devis = new Devis($attributs);
            $devis->forceFill(['client_id' => $client->getKey(), 'type_client' => $client->type_client])->save();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes);

            foreach ($lignesConstruites as $ligne) {
                $devis->lignes()->create($ligne);
            }

            $devis->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $devis->fresh('lignes');
        });
    }
}
