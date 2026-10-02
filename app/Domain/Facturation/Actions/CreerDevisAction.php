<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use Illuminate\Support\Facades\DB;

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
            $devis = Devis::create([...$attributs, 'client_id' => $client->getKey()]);

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes);

            foreach ($lignesConstruites as $ligne) {
                $devis->lignes()->create($ligne);
            }

            $devis->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $devis->fresh('lignes');
        });
    }
}
