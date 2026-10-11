<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Crée une facture brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire. Le
 * brouillon note le type de ce client, qui choisit sa série, et si le
 * magasin vend en franchise de TVA : l'un et l'autre se contrôlent à
 * l'émission (voir ContexteDeVente et EmettreFactureAction). Il note aussi
 * la nature de l'opération et la livraison reçues avec ses attributs (voir
 * NoteSaNatureDOperation), avec laquelle ses lignes se calculent : une
 * nature que ce client et le régime du magasin permettent (voir
 * ContexteDeVente::pourEnregistrer()), dont les autres conditions se
 * contrôlent à l'émission (voir ControleNatureOperation).
 */
class CreerFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly CalculateurLigne $calculateurLigne,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs  conditions_paiement, notes, et la nature et la livraison (voir NoteSaNatureDOperation::noterSaNatureDOperation())
     */
    public function handle(Client $client, array $lignes, array $attributs = []): Facture
    {
        return DB::transaction(function () use ($client, $lignes, $attributs) {
            $facture = new Facture($attributs);
            $contexte = ContexteDeVente::pourEnregistrer($client, app(TenantContext::class)->magasin(), $facture->noterSaNatureDOperation($attributs));
            $facture->forceFill(['client_id' => $client->getKey(), ...$contexte->colonnes()])->save();

            $lignesConstruites = $this->constructeurLigne->construireToutes($lignes, $contexte);

            foreach ($lignesConstruites as $ligne) {
                $facture->lignes()->create($ligne);
            }

            $facture->forceFill($this->calculateurLigne->totaliser($lignesConstruites))->save();

            return $facture->fresh('lignes');
        });
    }
}
