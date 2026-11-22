<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Crée un devis brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire, relu
 * dans le magasin actif avant toute écriture (voir
 * BelongsToMagasin::relueDansLeMagasin()), et note le type de ce client, si le magasin vend en franchise de TVA, la nature
 * de l'opération, que ce client et ce régime doivent permettre (voir
 * ContexteDeVente::pourEnregistrer()), la livraison (voir
 * NoteSaNatureDOperation) et, pour un dépannage à domicile, le lieu de
 * l'intervention (voir DepannageADomicile), que reprend la facture qui en est tirée (voir
 * CreerFactureDepuisDevisAction).
 *
 * Un magasin suspendu, fermé ou cédé, ou dont la société est en lecture
 * seule, n'en crée plus (voir Magasin::verifierQuIlEstOuvert()) : sa ligne
 * se relit sous un verrou partagé, premier verrou de la transaction, et
 * une fermeture du magasin, qui la verrouille (voir FermerMagasinAction),
 * passe tout entière avant ou après la création. Son auteur, relu ensuite
 * sous le verrou de sa ligne, doit pouvoir créer un devis dans le magasin
 * actif (voir LecteurDesDroits), et être autorisé à accorder des remises
 * et fixer les prix pour une ligne remisée ou à un autre prix que celui de
 * la fiche du produit ou du prix de référence du service (R2, voir
 * DerogationsDePrix) ; la création se fait à son nom (voir
 * OperateurCourant).
 */
class CreerDevisAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly DerogationsDePrix $derogations,
    ) {}

    /**
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs  date_validite, conditions_paiement, notes, la nature et la livraison (voir NoteSaNatureDOperation::noterSaNatureDOperation()), et le lieu de l'intervention d'un dépannage à domicile (voir Devis::noterLeDepannageADomicile())
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException quand les conditions de paiement sont trop longues pour le pied du PDF (voir PiedDePage)
     * @throws LogicException quand le magasin est en lecture seule, ou que le client n'est pas un client du magasin
     */
    public function handle(User $auteur, Client $client, array $lignes, array $attributs = []): Devis
    {
        PiedDePage::verifierLesConditionsDePaiement($attributs['conditions_paiement'] ?? null);

        return DB::transaction(function () use ($auteur, $client, $lignes, $attributs) {
            $magasin = app(TenantContext::class)->magasin();
            $relu = Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail();
            $relu->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Devis::class);
            $client = Client::relueDansLeMagasin($client, Client::REFUS_AUTRE_MAGASIN);

            return $this->operateur->pendant($compte, fn (): Devis => $this->creer($compte, $magasin, $relu, $client, $lignes, $attributs));
        });
    }

    /**
     * Le dépannage à domicile se décide sur la ligne du magasin relue sous
     * verrou ($relu), jamais sur le magasin du contexte, lu au début de la
     * requête : un réglage changé entre-temps vaut déjà, comme à l'envoi
     * du devis (voir EmettreDevisAction).
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     *
     * @throws AuthorizationException
     */
    private function creer(User $compte, Magasin $magasin, Magasin $relu, Client $client, array $lignes, array $attributs): Devis
    {
        $devis = new Devis($attributs);
        $contexte = ContexteDeVente::pourEnregistrer($client, $magasin, $devis->noterSaNatureDOperation($attributs));
        $devis->noterLeDepannageADomicile($attributs, $relu, $client->estProfessionnel());
        $document = $this->constructeurLigne->construireToutes($lignes, $contexte);
        $this->derogations->exiger($compte, [], $document->lignes, $contexte);
        $devis->forceFill(['client_id' => $client->getKey(), ...$contexte->colonnes()])->save();

        foreach ($document->lignes as $ligne) {
            $devis->lignes()->create($ligne);
        }

        $devis->forceFill($document->totaux)->save();

        return ModeleRelu::de($devis, 'lignes');
    }
}
