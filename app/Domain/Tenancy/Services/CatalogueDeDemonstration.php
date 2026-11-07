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
 * OuvrirLaDemonstrationAction), selon le type d'activité de sa société,
 * tous ses prix TTC au taux du territoire du magasin : pour une société
 * qui a des articles, un fournisseur, une facture d'achat validée qui
 * crée trois produits et leur stock, et trois services à prix de
 * référence (emballage cadeau, livraison, main-d'œuvre de réparation) ;
 * pour une société de services, trois services à prix de référence (heure
 * de conseil, forfait déplacement, atelier d'une demi-journée), sans
 * fournisseur, achat ni produit ; pour toutes, deux clients, un
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

        $taux = $demonstration->tauxTvaParDefaut();

        if ($demonstration->societe->aDesArticles()) {
            $this->remplirLesArticles($auteur, $taux, $demonstration->calendrier()->aujourdhui());
        }

        $services = $demonstration->societe->aDesArticles()
            ? ['Emballage cadeau' => '3.00', 'Livraison à domicile' => '5.90', 'Main-d\'œuvre de réparation (30 min)' => '25.00']
            : ['Heure de conseil' => '72.00', 'Forfait déplacement' => '30.00', 'Atelier d\'une demi-journée' => '450.00'];

        foreach ($services as $nom => $prixTtc) {
            Service::query()->create(['nom' => $nom, 'prix_ttc' => $prixTtc, 'taux_tva' => $taux, 'actif' => true]);
        }

        Client::query()->create(['nom' => 'Marie Martin (cliente fictive)', 'type_client' => TypeClient::Particulier, 'pays' => 'FR']);
        Client::query()->create(['nom' => 'Entreprise cliente de démonstration', 'type_client' => TypeClient::Professionnel, 'siren' => '000000018', 'adresse' => '1 rue de l\'Exemple', 'code_postal' => '75001', 'ville' => 'Paris', 'pays' => 'FR']);
    }

    /**
     * Les articles d'une société qui en a : un fournisseur et une facture
     * d'achat validée, datée du jour du magasin, qui crée trois produits et
     * leur stock ; fictive, elle n'a pas de pièce du fournisseur, et en
     * porte la marque, comme une vraie saisie sans sa pièce.
     */
    private function remplirLesArticles(User $auteur, float $taux, string $aujourdhui): void
    {
        $fournisseur = Fournisseur::query()->create(['nom' => 'Grossiste de démonstration', 'pays' => 'FR']);

        $this->validerFactureAchat->handle($auteur, $this->creerFactureAchat->handle($auteur, $fournisseur, [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'DEMO-CARNET', 'prix_vente_ttc' => '4.90', 'taux_tva' => $taux, 'suivi_stock' => true, 'seuil_alerte' => 5], 'quantite' => 20, 'prix_unitaire_ht' => '1.50'],
            ['produit' => ['nom' => 'Stylo bille bleu', 'reference' => 'DEMO-STYLO', 'prix_vente_ttc' => '1.20', 'taux_tva' => $taux, 'suivi_stock' => true, 'seuil_alerte' => 10], 'quantite' => 50, 'prix_unitaire_ht' => '0.30'],
            ['produit' => ['nom' => 'Tasse en céramique', 'reference' => 'DEMO-TASSE', 'prix_vente_ttc' => '9.90', 'taux_tva' => $taux, 'suivi_stock' => true, 'seuil_alerte' => 2], 'quantite' => 10, 'prix_unitaire_ht' => '3.20'],
        ], ['date_facture' => $aujourdhui, 'numero_fournisseur' => 'DEMO-1']));
    }
}
