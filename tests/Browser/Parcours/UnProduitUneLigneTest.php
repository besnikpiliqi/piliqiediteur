<?php

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use Tests\Browser\Support\Navigation;

/*
 * Un produit n'a qu'une ligne (décision de l'éditeur du 26/10/2026,
 * série 9), comme une personne le vit dans le navigateur : à la caisse,
 * chaque clic sur la rangée d'un produit en ajoute un, et le choisir de
 * nouveau, par le tableau ou la douchette, augmente la quantité de sa
 * ligne ; une ligne remisée voit sa quantité augmenter pour le responsable,
 * et l'ajout est refusé, avec la raison, au vendeur qui n'accorde pas de
 * remise ; un service coché de nouveau rejoint sa ligne ; sur une facture
 * d'achat, le produit ajouté de nouveau rejoint sa ligne au prix moyen, et
 * « Modifier » ne prend pas le produit d'une autre ligne ; sur un
 * inventaire d'ouverture, le produit choisi sur une seconde ligne rejoint
 * la première. La société de test a tous ses rôles et son catalogue (voir
 * TestCaseDuNavigateur::societeComplete()).
 */

/**
 * Les quantités des lignes du formulaire de la page, dans leur ordre.
 *
 * @return list<string>
 */
function quantitesDesLignes(Navigation $onglet): array
{
    return $onglet->page()->evaluate('() => [...document.querySelectorAll(\'[id*=".lignes."][id$=".quantite"]\')].map((champ) => champ.value)');
}

/**
 * Clique, dans la fenêtre « Ajouter un produit » ouverte, sur la rangée de
 * ce produit.
 */
function cliquerSurLaRangee(Navigation $onglet, string $produit): Navigation
{
    return $onglet->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte($produit).')');
}

test('à la caisse, le vendeur clique deux fois sur un produit, le choisit de nouveau par le tableau puis par la douchette : une seule ligne, dont la quantité monte, qu’il encaisse', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');

    $onglet->cliquerSur('Ajouter un produit');
    cliquerSurLaRangee($onglet, 'Bougie parfumée')->voir('× 1');
    cliquerSurLaRangee($onglet, 'Bougie parfumée')->voir('× 2');
    $onglet->cliquerSur('Ajouter le produit')->voir('36,00 €');
    expect(quantitesDesLignes($onglet))->toBe(['2']);

    $onglet->cliquerSur('Ajouter un produit');
    cliquerSurLaRangee($onglet, 'Bougie parfumée')->voir('× 1');
    $onglet->cliquerSur('Ajouter le produit')
        ->voir('Déjà sur une ligne : « Bougie parfumée » (3), sa quantité a augmenté.')
        ->voir('54,00 €');
    expect(quantitesDesLignes($onglet))->toBe(['3']);

    $onglet->remplir('Scanner ou taper une référence', 'BOUGIE')
        ->geste(fn () => $onglet->element(Navigation::champ('Scanner ou taper une référence'))->press('Enter'), 'Entrée dans le champ de la douchette')
        ->voir('72,00 €');
    expect(quantitesDesLignes($onglet))->toBe(['4']);

    $onglet->remplirLaLigne('parts', 0, 'montant', '72')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->vendeur->id)->whereHas('client', fn ($client) => $client->where('est_comptoir', true))->latest('id')->firstOrFail()->load('lignes'));
    expect($ticket->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite])->all())->toBe([['Bougie parfumée', 4]])
        ->and((string) $ticket->total_ttc)->toBe('72.00');
});

test('sur une facture, le responsable choisit de nouveau un produit de sa ligne remisée, dont la quantité monte avec sa remise ; le vendeur sans l’autorisation des remises est refusé avec la raison', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/create');

    $onglet->cliquer('[title="Chercher un client"]')
        ->cliquer(Navigation::FENETRE.' >> .fi-ta-text-item-label:text-is('.Navigation::texte('Hôtel des Pins').')');
    $onglet->cliquerSur('Ajouter un produit');
    cliquerSurLaRangee($onglet, 'Savon au lait')->voir('× 1');
    $onglet->cliquerSur('Ajouter le produit')
        ->remplirLaLigne('lignes', 0, 'remise_pourcentage', '10');
    $onglet->cliquerSur('Ajouter un produit');
    cliquerSurLaRangee($onglet, 'Savon au lait')->voir('× 1');
    $onglet->cliquerSur('Ajouter le produit')
        ->voir('Déjà sur une ligne : « Savon au lait » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2'])
        ->and($onglet->element('[id*=".lignes."][id$=".remise_pourcentage"]')->inputValue())->toBe('10');
    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/factures$');

    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->responsable->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($brouillon->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->remise_pourcentage])->all())->toBe([['Savon au lait', 2, '10.00']]);
    $onglet->seDeconnecter();

    $vendeur = app(TenantContext::class)->runAs($societe->magasin, fn () => $this->membre($societe->magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Léa Morel']));
    $onglet = $this->seConnecter($vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->getRouteKey().'/edit');
    $onglet->cliquerSur('Ajouter un produit');
    cliquerSurLaRangee($onglet, 'Savon au lait')->voir('× 1');
    $onglet->cliquerSur('Ajouter le produit')
        ->voir('« Savon au lait » a une remise sur sa ligne : demandez à un responsable de changer sa quantité.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
});

test('sur un devis, le vendeur coche deux fois « Emballage cadeau » par « Ajouter un service » : une seule ligne, de deux', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/devis/create');
    $service = Navigation::FENETRE.' label.fi-fo-checkbox-list-option-label:has-text('.Navigation::texte('Emballage cadeau').')';

    foreach ([1, 2] as $fois) {
        $onglet->cliquerSur('Ajouter un service')
            ->cliquer($service)
            ->soumettre('Ajouter');
        expect(quantitesDesLignes($onglet))->toBe([(string) $fois]);
    }

    $onglet->voir('Déjà sur une ligne : « Emballage cadeau » (2), sa quantité a augmenté.')->voir('10,00 €');
});

test('sur une facture d’achat, le responsable ajoute de nouveau un produit, dont la ligne compte la quantité totale au prix moyen, et « Modifier » une autre ligne ne lui prend pas ce produit', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->responsable);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/facture-achats/create')
        ->choisirDansLaListe('Fournisseur', 'Grossiste du Nord')
        ->remplir('Date de la facture du fournisseur', '2026-03-09')
        ->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->remplir('Quantité reçue', '10')
        ->remplir('Prix d\'achat HT', '4')
        ->soumettre('Ajouter à la facture')
        ->voir('10 x 4,00 € HT = 40,00 € HT');

    $onglet->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->voir('Ce produit est déjà sur cette facture d\'achat, qui n\'a qu\'une ligne par produit')
        ->remplir('Quantité reçue', '2')
        ->remplir('Prix d\'achat HT', '0')
        ->soumettre('Ajouter à la facture')
        ->voir('« Bougie parfumée » était déjà sur la facture : sa ligne compte désormais 12 articles à 3,333333 € HT.')
        ->voir('12 x 3,333333 € HT = 40,00 € HT');

    $onglet->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Savon au lait')
        ->remplir('Quantité reçue', '5')
        ->remplir('Prix d\'achat HT', '2')
        ->soumettre('Ajouter à la facture')
        ->voir('5 x 2,00 € HT = 10,00 € HT')
        ->cliquer('.fi-fo-repeater-item:has-text('.Navigation::texte('Savon au lait').') >> '.Navigation::bouton('Modifier'))
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->soumettre('Enregistrer la ligne')
        ->voir('« Bougie parfumée » est déjà sur une autre ligne de cette facture d\'achat : modifiez cette ligne-là, une facture d\'achat n\'a qu\'une ligne par produit.');
    $onglet->fermerLaFenetre()->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/facture-achats$');

    $achat = app(TenantContext::class)->runAs($societe->magasin, fn (): FactureAchat => FactureAchat::query()->where('cree_par', $societe->responsable->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($achat->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->prix_unitaire_ht, (string) $ligne->montant_ht])->all())
        ->toBe([['Bougie parfumée', 12, '3.333333', '40.00'], ['Savon au lait', 5, '2.00', '10.00']]);
});

test('sur un inventaire d’ouverture, le propriétaire choisit sur une seconde ligne un produit déjà inventorié : il rejoint sa ligne, dont la quantité monte', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->autreMagasin, fn (): Produit => Produit::factory()->create(['nom' => 'Tasse en grès', 'reference' => 'TASSE-GRES', 'stock_quantite' => 0]));
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture/create');

    $onglet->choisirDansLaListe('Produit déjà au catalogue, jamais reçu', 'Tasse en grès')
        ->remplirLaLigne('lignes', 0, 'quantite', '4')
        ->remplirLaLigne('lignes', 0, 'cout_unitaire_ht', '3.25')
        ->cliquerSur('Ajouter un produit');
    $seconde = '.choices:has([id='.Navigation::texte($onglet->page()->evaluate('() => [...document.querySelectorAll(\'select[id*=".lignes."][id$=".produit_id"]\')][1].id')).'])';
    $onglet->cliquer($seconde.' .choices__inner');
    $onglet->geste(fn () => $onglet->element($seconde.' input.choices__input--cloned')->type('Tasse'), 'la recherche de la tasse sur la seconde ligne');
    $onglet->cliquer($seconde.' .choices__list--dropdown .choices__item--choice:has-text('.Navigation::texte('Tasse en grès').')')
        ->voir('« Tasse en grès » était déjà sur une ligne de cet inventaire, qui n\'a qu\'une ligne par produit : sa quantité passe à 5. Vérifiez son coût unitaire.');
    expect(quantitesDesLignes($onglet))->toBe(['5']);

    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture/[^/]+');
    $inventaire = app(TenantContext::class)->runAs($societe->autreMagasin, fn (): InventaireOuverture => InventaireOuverture::query()->latest('id')->firstOrFail()->load('lignes'));
    expect($inventaire->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->cout_unitaire_ht])->all())->toBe([['Tasse en grès', 5, '3.25']]);
});
