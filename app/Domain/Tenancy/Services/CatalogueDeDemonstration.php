<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use LogicException;

/**
 * Le catalogue fictif d'un magasin de démonstration, à son ouverture (voir
 * OuvrirLaDemonstrationAction) : un fournisseur, une facture d'achat
 * validée qui crée trois produits et leur stock, deux services à prix de
 * référence, au taux du territoire du magasin, et deux clients, un
 * particulier et une entreprise, tous fictifs, pour vendre, encaisser et
 * clôturer tout de suite, même sans l'autorisation de fixer les prix (voir
 * DerogationsDePrix). Rien ne vient d'un magasin réel, et rien n'y va.
 */
class CatalogueDeDemonstration
{
    public function __construct(
        private readonly CreerFactureAchatAction $creerFactureAchat,
        private readonly ValiderFactureAchatAction $validerFactureAchat,
    ) {}

    /**
     * Remplit le magasin de démonstration, qui doit être le magasin actif,
     * au nom de la personne qui l'ouvre.
     *
     * @throws LogicException
     */
    public function remplir(User $auteur, Magasin $demonstration): void
    {
        if (! $demonstration->est_demonstration || app(TenantContext::class)->id() !== $demonstration->getKey()) {
            throw new LogicException('Le catalogue de démonstration ne remplit que le magasin de démonstration actif.');
        }

        $fournisseur = Fournisseur::query()->create(['nom' => 'Grossiste de démonstration', 'pays' => 'FR']);
        $taux = $demonstration->tauxTvaParDefaut();

        $this->validerFactureAchat->handle($auteur, $this->creerFactureAchat->handle($auteur, $fournisseur, [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'DEMO-CARNET', 'prix_vente_ttc' => '4.90', 'taux_tva' => $taux, 'suivi_stock' => true, 'seuil_alerte' => 5], 'quantite' => 20, 'prix_unitaire_ht' => '1.50'],
            ['produit' => ['nom' => 'Stylo bille bleu', 'reference' => 'DEMO-STYLO', 'prix_vente_ttc' => '1.20', 'taux_tva' => $taux, 'suivi_stock' => true, 'seuil_alerte' => 10], 'quantite' => 50, 'prix_unitaire_ht' => '0.30'],
            ['produit' => ['nom' => 'Tasse en céramique', 'reference' => 'DEMO-TASSE', 'prix_vente_ttc' => '9.90', 'taux_tva' => $taux, 'suivi_stock' => true, 'seuil_alerte' => 2], 'quantite' => 10, 'prix_unitaire_ht' => '3.20'],
        ]));

        Service::query()->create(['nom' => 'Emballage cadeau', 'prix_ttc' => '3.00', 'taux_tva' => $taux, 'actif' => true]);
        Service::query()->create(['nom' => 'Livraison à domicile', 'prix_ttc' => '5.90', 'taux_tva' => $taux, 'actif' => true]);

        Client::query()->create(['nom' => 'Marie Martin (cliente fictive)', 'type_client' => TypeClient::Particulier, 'pays' => 'FR']);
        Client::query()->create(['nom' => 'Entreprise cliente de démonstration', 'type_client' => TypeClient::Professionnel, 'siren' => '000000018', 'adresse' => '1 rue de l\'Exemple', 'code_postal' => '75001', 'ville' => 'Paris', 'pays' => 'FR']);
    }
}
