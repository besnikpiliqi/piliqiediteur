<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use Illuminate\Support\Facades\DB;

class CreerFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    public function handle(Client $client, array $lignes, array $attributs = []): Facture
    {
        return DB::transaction(function () use ($client, $lignes, $attributs) {
            $facture = Facture::create([...$attributs, 'client_id' => $client->getKey()]);

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes);

            foreach ($lignesConstruites as $ligne) {
                $facture->lignes()->create($ligne);
            }

            $facture->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $facture->fresh('lignes');
        });
    }
}
