<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Facturation\Services\PiedDePage;
use App\Domain\Facturation\Services\VenteDuStockSeul;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ModeleRelu;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Crée une facture brouillon pour un client déjà résolu par une requête
 * limitée au magasin, jamais un client_id brut venu du formulaire, et
 * relu dans le magasin actif avant toute écriture (voir
 * BelongsToMagasin::relueDansLeMagasin()) : celui d'un autre magasin est
 * refusé, même déjà chargé ou avec son magasin_id changé en mémoire. Le
 * brouillon note le type de ce client, qui choisit sa série, et si le
 * magasin vend en franchise de TVA : l'un et l'autre se contrôlent à
 * l'émission (voir ContexteDeVente et EmettreFactureAction). Il note aussi
 * la nature de l'opération et la livraison reçues avec ses attributs (voir
 * NoteSaNatureDOperation), avec laquelle ses lignes se calculent : une
 * nature que ce client et le régime de la société permettent (voir
 * ContexteDeVente::pourEnregistrer()), dont les autres conditions se
 * contrôlent à l'émission (voir ControleNatureOperation).
 *
 * Un magasin suspendu, fermé ou cédé, ou dont la société est en lecture
 * seule, n'en crée plus (voir Magasin::verifierQuIlEstOuvert()) : sa ligne
 * se relit sous un verrou partagé, premier verrou de la transaction (ou
 * juste après le registre, que la vente à la caisse a déjà verrouillé et
 * sous lequel elle a déjà relu cette ligne, voir
 * EnregistrerVenteCaisseAction), et une fermeture du magasin, qui la
 * verrouille (voir FermerMagasinAction), passe tout entière avant ou après
 * la création. Son auteur, relu ensuite sous le verrou de sa ligne, doit
 * pouvoir créer une facture dans le magasin actif (voir LecteurDesDroits) :
 * c'est lui que le brouillon enregistre comme auteur, avec son nom figé. Une ligne remisée,
 * ou à un autre prix que celui de la fiche du produit ou du prix de
 * référence du service, exige en plus qu'il soit autorisé à accorder des
 * remises et fixer les prix (R2, voir DerogationsDePrix).
 *
 * La facture du stock vendu seul d'un magasin que sa société a fermé (voir
 * VenteDuStockSeul et FacturerLeStockAction) se crée par le même chemin :
 * le magasin, fermé, y est tenu pour ouvert le temps de la transaction, et
 * le brouillon porte alors la colonne vente_du_stock_seul, que la base ne
 * laisse plus changer et qu'elle n'accepte que pour un professionnel, en
 * vente en France, sans devis ni rectification.
 */
class CreerFactureAction
{
    public function __construct(
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
        private readonly DerogationsDePrix $derogations,
        private readonly VenteDuStockSeul $venteDuStock,
    ) {}

    /**
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs  conditions_paiement, notes, et la nature et la livraison (voir NoteSaNatureDOperation::noterSaNatureDOperation())
     * @param  bool  $venteDuStockSeul  la facture du stock vendu seul d'un magasin fermé (voir FacturerLeStockAction, seule à la demander)
     *
     * @throws AuthorizationException quand l'auteur ne peut pas créer de facture, ou introduit une remise ou un prix sans y être autorisé
     * @throws InvalidArgumentException quand les conditions de paiement sont trop longues pour le pied du PDF (voir PiedDePage)
     * @throws LogicException quand le magasin est en lecture seule, ou que le client n'est pas un client du magasin
     */
    public function handle(User $auteur, Client $client, array $lignes, array $attributs = [], bool $venteDuStockSeul = false): Facture
    {
        PiedDePage::verifierLesConditionsDePaiement($attributs['conditions_paiement'] ?? null);

        $creation = fn (): Facture => DB::transaction(function () use ($auteur, $client, $lignes, $attributs, $venteDuStockSeul) {
            $magasin = app(TenantContext::class)->magasin();
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $compte = $this->lecteur->exigerSousVerrou($auteur, 'create', $magasin, Facture::class);
            $client = Client::relueDansLeMagasin($client, Client::REFUS_AUTRE_MAGASIN);

            return $this->operateur->pendant($compte, fn (): Facture => $this->creer($compte, $client, $lignes, $attributs, $venteDuStockSeul));
        });

        return $venteDuStockSeul ? $this->venteDuStock->pendant(app(TenantContext::class)->magasin(), $creation) : $creation();
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @param  array<string, mixed>  $attributs
     */
    private function creer(User $compte, Client $client, array $lignes, array $attributs, bool $venteDuStockSeul): Facture
    {
        $facture = new Facture($attributs);
        $contexte = ContexteDeVente::pourEnregistrer($client, app(TenantContext::class)->magasin(), $facture->noterSaNatureDOperation($attributs));
        $document = $this->constructeurLigne->construireToutes($lignes, $contexte);
        $this->derogations->exiger($compte, [], $document->lignes, $contexte);
        $facture->forceFill(['client_id' => $client->getKey(), 'cree_par' => $compte->getKey(), 'vente_du_stock_seul' => $venteDuStockSeul, ...$contexte->colonnes()])->save();

        foreach ($document->lignes as $ligne) {
            $facture->lignes()->create($ligne);
        }

        $facture->forceFill($document->totaux)->save();

        return ModeleRelu::de($facture, 'lignes');
    }
}
