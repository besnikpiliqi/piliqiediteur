<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Crée une facture brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire. Le
 * brouillon note le type de ce client, qui choisit sa série, et si le
 * magasin vend en franchise de TVA : l'un et l'autre se contrôlent à
 * l'émission (voir ContexteDeVente et EmettreFactureAction). Il note aussi
 * la nature de l'opération et la livraison reçues avec ses attributs (voir
 * NoteSaNatureDOperation), avec laquelle ses lignes se calculent : une
 * nature que ce client et le régime de la société permettent (voir
 * ContexteDeVente::pourEnregistrer()), dont les autres conditions se
 * contrôlent à l'émission (voir ControleNatureOperation).
 *
 * Son auteur, relu en base au moment où elle écrit, doit pouvoir créer une
 * facture dans le magasin actif (voir LecteurDesDroits) : c'est lui que le
 * brouillon enregistre comme auteur, avec son nom figé. Une ligne remisée,
 * ou à un autre prix que celui de la fiche du produit ou du prix de
 * référence du service, exige en plus qu'il soit autorisé à accorder des
 * remises et fixer les prix (R2, voir DerogationsDePrix).
 */
class CreerFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly DerogationsDePrix $derogations,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs  conditions_paiement, notes, et la nature et la livraison (voir NoteSaNatureDOperation::noterSaNatureDOperation())
     *
     * @throws AuthorizationException quand l'auteur ne peut pas créer de facture, ou introduit une remise ou un prix sans y être autorisé
     */
    public function handle(User $auteur, Client $client, array $lignes, array $attributs = []): Facture
    {
        return DB::transaction(function () use ($auteur, $client, $lignes, $attributs) {
            $magasin = app(TenantContext::class)->magasin();
            $compte = $this->lecteur->exiger($auteur, 'create', $magasin, Facture::class);

            return $this->operateur->pendant($compte, fn (): Facture => $this->creer($compte, $client, $lignes, $attributs));
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    private function creer(User $compte, Client $client, array $lignes, array $attributs): Facture
    {
        $facture = new Facture($attributs);
        $contexte = ContexteDeVente::pourEnregistrer($client, app(TenantContext::class)->magasin(), $facture->noterSaNatureDOperation($attributs));
        $document = $this->constructeurLigne->construireToutes($lignes, $contexte);
        $this->derogations->exiger($compte, [], $document->lignes, $contexte);
        $facture->forceFill(['client_id' => $client->getKey(), 'cree_par' => $compte->getKey(), ...$contexte->colonnes()])->save();

        foreach ($document->lignes as $ligne) {
            $facture->lignes()->create($ligne);
        }

        $facture->forceFill($document->totaux)->save();

        return $facture->fresh('lignes');
    }
}
