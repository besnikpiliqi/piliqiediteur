<?php

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Models\Devis;
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

/**
 * Ouvre « Ajouter un produit », clique une fois sur la rangée de chacun de
 * ces produits, puis ajoute la sélection.
 */
function ajouterParLeTableau(Navigation $onglet, string ...$produits): Navigation
{
    $onglet->cliquerSur('Ajouter un produit');

    foreach ($produits as $produit) {
        cliquerSurLaRangee($onglet, $produit)->voir('× 1');
    }

    return $onglet->cliquerSur(count($produits) === 1 ? 'Ajouter le produit' : 'Ajouter les '.count($produits).' produits');
}

/**
 * Coche ce service dans « Ajouter un service », puis l'ajoute.
 */
function cocherLeService(Navigation $onglet, string $service): Navigation
{
    return $onglet->cliquerSur('Ajouter un service')
        ->cliquer(Navigation::FENETRE.' label.fi-fo-checkbox-list-option-label:has-text('.Navigation::texte($service).')')
        ->soumettre('Ajouter');
}

/**
 * Quitte le champ de ce rang de cette liste de lignes, comme une personne
 * qui passe au champ suivant : la saisie est faite.
 */
function quitterLeChamp(Navigation $onglet, string $liste, int $rang, string $champ): Navigation
{
    return $onglet->geste(fn () => $onglet->element('[id*=".'.$liste.'."][id$=".'.$champ.'"] >> nth='.$rang)->press('Tab'), "la sortie du champ {$champ} de la ligne {$rang}");
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
        ->voir('« Bougie parfumée » était déjà sur la facture : sa ligne compte désormais 12 articles à 3,333333 € HT, vendus 18,00 € TTC l\'article.')
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

    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture$');
    $inventaire = app(TenantContext::class)->runAs($societe->autreMagasin, fn (): InventaireOuverture => InventaireOuverture::query()->latest('id')->firstOrFail()->load('lignes'));
    expect($inventaire->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->cout_unitaire_ht])->all())->toBe([['Tasse en grès', 5, '3.25']]);
});

test('sur un devis du magasin, le propriétaire choisit deux fois le même produit : une seule ligne, dont la quantité monte, et le devis s’enregistre', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire)->aller('/app/'.$societe->magasin->uuid.'/devis/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterParLeTableau($onglet, 'Savon au lait');
    ajouterParLeTableau($onglet, 'Savon au lait')
        ->voir('Déjà sur une ligne : « Savon au lait » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/devis');

    $devis = app(TenantContext::class)->runAs($societe->magasin, fn (): Devis => Devis::query()->latest('id')->firstOrFail()->load('lignes'));
    expect($devis->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite])->all())->toBe([['Savon au lait', 2]]);
});

test('dans la démonstration, le propriétaire choisit de nouveau un produit à la caisse : sa ligne monte, et il encaisse', function () {
    $demonstration = $this->demonstration();
    $onglet = $this->seConnecter($demonstration->proprietaire, panneau: 'societe')->aller('/app/'.$demonstration->magasin->uuid.'/caisse');

    ajouterParLeTableau($onglet, 'Carnet A5');
    ajouterParLeTableau($onglet, 'Carnet A5')
        ->voir('Déjà sur une ligne : « Carnet A5 » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
    $onglet->remplirLaLigne('parts', 0, 'montant', app(TenantContext::class)->runAs($demonstration->magasin, fn (): string => (string) (2 * (float) Produit::query()->where('nom', 'Carnet A5')->value('prix_vente_ttc'))))
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = app(TenantContext::class)->runAs($demonstration->magasin, fn (): Facture => Facture::query()->latest('id')->firstOrFail()->load('lignes'));
    expect($ticket->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite])->all())->toBe([['Carnet A5', 2]]);
});

test('le vendeur et le responsable d’entraînement ajoutent un produit sur une facture de la démonstration, le reprennent, puis retirent sa ligne par la corbeille', function (string $compte) {
    $demonstration = $this->demonstration();
    $onglet = $this->seConnecter($demonstration->{$compte})->aller('/app/'.$demonstration->magasin->uuid.'/factures/create');

    ajouterParLeTableau($onglet, 'Stylo bille bleu', 'Tasse en céramique');
    ajouterParLeTableau($onglet, 'Stylo bille bleu')
        ->voir('Déjà sur une ligne : « Stylo bille bleu » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2', '1']);

    // La ligne du stylo, la première, se retire par sa corbeille.
    $onglet->cliquer('.fi-fo-repeater-item >> nth=0 >> '.Navigation::bouton('Supprimer'));
    $onglet->attendreQue('document.querySelectorAll(\'[id*=".lignes."][id$=".quantite"]\').length === 1', 'la ligne du stylo est restée');
    expect(quantitesDesLignes($onglet))->toBe(['1'])
        ->and($onglet->element('[id*=".lignes."][id$=".description"]')->inputValue())->toBe('Tasse en céramique');
})->with([
    'le vendeur d’entraînement' => ['vendeur'],
    'le responsable d’entraînement' => ['responsable'],
]);

test('l’administrateur autorisé à vendre choisit de nouveau un produit à la caisse, qu’il encaisse, puis sur une facture : une seule ligne chaque fois', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->administrateurVendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');

    ajouterParLeTableau($onglet, 'Savon au lait');
    ajouterParLeTableau($onglet, 'Savon au lait')->voir('Déjà sur une ligne : « Savon au lait » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
    $onglet->remplirLaLigne('parts', 0, 'montant', '12')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $onglet->aller('/app/'.$societe->magasin->uuid.'/factures/create');
    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterParLeTableau($onglet, 'Bougie parfumée');
    $onglet->remplir('Scanner ou taper une référence', 'BOUGIE')
        ->geste(fn () => $onglet->element(Navigation::champ('Scanner ou taper une référence'))->press('Enter'), 'Entrée dans le champ de la douchette')
        ->voir('Déjà sur une ligne : « Bougie parfumée » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/factures');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->administrateurVendeur->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($facture->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite])->all())->toBe([['Bougie parfumée', 2]]);
});

test('à la caisse, le vendeur coche deux fois « Emballage cadeau » : une seule ligne, de deux, qu’il encaisse', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/caisse');

    cocherLeService($onglet, 'Emballage cadeau');
    cocherLeService($onglet, 'Emballage cadeau')->voir('Déjà sur une ligne : « Emballage cadeau » (2), sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
    $onglet->remplirLaLigne('parts', 0, 'montant', '10')
        ->soumettre('Encaisser')
        ->voir('Vente encaissée');

    $ticket = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->vendeur->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($ticket->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite])->all())->toBe([['Emballage cadeau', 2]]);
});

test('sur une facture, le responsable coche deux fois « Réparation », sans prix de référence, et saisit le même prix sur les deux lignes : elles se réunissent dès le prix saisi', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->magasin, fn (): Service => Service::factory()->create(['nom' => 'Réparation']));
    $onglet = $this->seConnecter($societe->responsable)->aller('/app/'.$societe->magasin->uuid.'/factures/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    cocherLeService($onglet, 'Emballage cadeau');
    cocherLeService($onglet, 'Emballage cadeau')->voir('Déjà sur une ligne : « Emballage cadeau » (2), sa quantité a augmenté.');
    cocherLeService($onglet, 'Réparation');
    cocherLeService($onglet, 'Réparation');
    expect(quantitesDesLignes($onglet))->toBe(['2', '1', '1']);

    $onglet->remplirLaLigne('lignes', 1, 'prix_unitaire_ttc', '60');
    quitterLeChamp($onglet, 'lignes', 1, 'prix_unitaire_ttc');
    $onglet->remplirLaLigne('lignes', 2, 'prix_unitaire_ttc', '60');
    quitterLeChamp($onglet, 'lignes', 2, 'prix_unitaire_ttc')
        ->voir('« Réparation » : ses lignes au même prix sont réunies (quantité 2).');
    expect(quantitesDesLignes($onglet))->toBe(['2', '2']);
    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/factures');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->responsable->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($facture->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->prix_unitaire_ttc])->all())
        ->toBe([['Emballage cadeau', 2, '5.00'], ['Réparation', 2, '60.00']]);
});

test('le vendeur coche un service dont la ligne est remisée : refusé avec la raison, la fenêtre garde ses cases, il décoche ce service et ajoute l’autre', function () {
    $societe = $this->societeComplete();
    $brouillon = app(TenantContext::class)->runAs($societe->magasin, function () use ($societe): Facture {
        Service::factory()->auPrixDe('12.00')->create(['nom' => 'Livraison']);

        return app(CreerFactureAction::class)->handle($societe->responsable, Client::query()->where('nom', 'Hôtel des Pins')->sole(), [
            ['service_id' => Service::query()->where('nom', 'Emballage cadeau')->value('id'), 'quantite' => 1, 'remise_pourcentage' => '10'],
        ]);
    });
    $vendeur = app(TenantContext::class)->runAs($societe->magasin, fn () => $this->membre($societe->magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Léa Morel']));
    $onglet = $this->seConnecter($vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->getRouteKey().'/edit');
    $service = fn (string $nom): string => Navigation::FENETRE.' label.fi-fo-checkbox-list-option-label:has-text('.Navigation::texte($nom).')';

    $onglet->cliquerSur('Ajouter un service')
        ->cliquer($service('Livraison'))
        ->cliquer($service('Emballage cadeau'))
        ->soumettre('Ajouter')
        ->voir('« Emballage cadeau » a une remise sur sa ligne : demandez à un responsable de changer sa quantité.');
    expect($onglet->fenetreOuverte())->toBeTrue()
        ->and($onglet->element($service('Livraison').' input')->isChecked())->toBeTrue()
        ->and(quantitesDesLignes($onglet))->toBe(['1']);

    $onglet->cliquer($service('Emballage cadeau'))
        ->soumettre('Ajouter')
        ->attendreQue('document.querySelectorAll(\'[id*=".lignes."][id$=".quantite"]\').length === 2', 'la livraison n’a pas été ajoutée');
    expect(quantitesDesLignes($onglet))->toBe(['1', '1']);
});

test('le vendeur sans l’autorisation des remises choisit dans le tableau un produit dont la ligne est remisée : refusé, la sélection reste, il retire ce produit et ajoute le reste', function () {
    $societe = $this->societeComplete();
    $brouillon = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => app(CreerFactureAction::class)->handle($societe->responsable, Client::query()->where('nom', 'Hôtel des Pins')->sole(), [
        ['produit_id' => Produit::query()->where('nom', 'Savon au lait')->value('id'), 'quantite' => 1, 'remise_pourcentage' => '10'],
    ]));
    $vendeur = app(TenantContext::class)->runAs($societe->magasin, fn () => $this->membre($societe->magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES], ['name' => 'Léa Morel']));
    $onglet = $this->seConnecter($vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/'.$brouillon->getRouteKey().'/edit');

    $onglet->cliquerSur('Ajouter un produit');
    cliquerSurLaRangee($onglet, 'Bougie parfumée')->voir('× 1');
    cliquerSurLaRangee($onglet, 'Bougie parfumée')->voir('× 2');
    cliquerSurLaRangee($onglet, 'Savon au lait');
    $onglet->cliquerSur('Ajouter les 2 produits')
        ->voir('« Savon au lait » a une remise sur sa ligne : demandez à un responsable de changer sa quantité.')
        ->voir('Bougie parfumée (BOUGIE)');
    expect($onglet->fenetreOuverte())->toBeTrue()
        ->and(quantitesDesLignes($onglet))->toBe(['1']);

    $onglet->cliquerSur('Retirer Savon au lait')
        ->cliquerSur('Ajouter le produit');
    $onglet->attendreQue('document.querySelectorAll(\'[id*=".lignes."][id$=".quantite"]\').length === 2', 'les bougies n’ont pas été ajoutées');
    expect(quantitesDesLignes($onglet))->toBe(['1', '2'])
        ->and($onglet->fenetreOuverte())->toBeFalse();
});

test('sur une facture, le vendeur choisit de nouveau un produit dont la fiche a changé de prix depuis sa ligne : la ligne reprend le prix du catalogue et sa quantité monte', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->vendeur)->aller('/app/'.$societe->magasin->uuid.'/factures/create');

    $onglet->choisirLeClient('Hôtel des Pins');
    ajouterParLeTableau($onglet, 'Savon au lait');
    app(TenantContext::class)->runAs($societe->magasin, fn () => Produit::query()->where('nom', 'Savon au lait')->sole()->forceFill(['prix_vente_ttc' => '7.20'])->save());
    ajouterParLeTableau($onglet, 'Savon au lait')
        ->voir('Déjà sur une ligne : « Savon au lait » (2) au prix du catalogue d\'aujourd\'hui, 7,20 €, sa quantité a augmenté.');
    expect(quantitesDesLignes($onglet))->toBe(['2']);
    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/factures');

    $facture = app(TenantContext::class)->runAs($societe->magasin, fn (): Facture => Facture::query()->where('cree_par', $societe->vendeur->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($facture->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->prix_unitaire_ttc])->all())->toBe([['Savon au lait', 2, '7.20']]);
});

test('sur une facture d’achat, le propriétaire corrige le prix de vente sur la première ligne, ajoute de nouveau le produit offert : la ligne réunie garde sa correction au prix moyen', function () {
    $societe = $this->societeComplete();
    $onglet = $this->seConnecter($societe->proprietaire);
    $onglet->aller('/app/'.$societe->magasin->uuid.'/facture-achats/create')
        ->choisirDansLaListe('Fournisseur', 'Grossiste du Nord')
        ->remplir('Date de la facture du fournisseur', '2026-03-09')
        ->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->remplir('Quantité reçue', '10')
        ->remplir('Prix d\'achat HT', '4')
        ->remplir('Prix de vente TTC', '21')
        ->soumettre('Ajouter à la facture')
        ->voir('10 x 4,00 € HT = 40,00 € HT');

    $onglet->cliquerSur('Ajouter un produit')
        ->choisirDansLaListe('Produit', 'Bougie parfumée')
        ->voir('Ce produit est déjà sur cette facture d\'achat, qui n\'a qu\'une ligne par produit');
    expect($onglet->element(Navigation::FENETRE.' >> '.Navigation::champ('Prix de vente TTC'))->inputValue())->toBe('21.00');
    $onglet->remplir('Quantité reçue', '2')
        ->remplir('Prix d\'achat HT', '0')
        ->soumettre('Ajouter à la facture')
        ->voir('« Bougie parfumée » était déjà sur la facture : sa ligne compte désormais 12 articles à 3,333333 € HT, vendus 21,00 € TTC l\'article.');
    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->magasin->uuid.'/facture-achats$');

    $achat = app(TenantContext::class)->runAs($societe->magasin, fn (): FactureAchat => FactureAchat::query()->where('cree_par', $societe->proprietaire->id)->latest('id')->firstOrFail()->load('lignes'));
    expect($achat->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->prix_unitaire_ht])->all())->toBe([['Bougie parfumée', 12, '3.333333']])
        ->and(app(TenantContext::class)->runAs($societe->magasin, fn (): string => (string) Produit::query()->where('nom', 'Bougie parfumée')->value('prix_vente_ttc')))->toBe('21.00');
});

test('sur un inventaire d’ouverture, un responsable choisit sur une seconde ligne un produit déjà inventorié : il rejoint sa ligne, dont la quantité monte', function () {
    $societe = $this->societeComplete();
    app(TenantContext::class)->runAs($societe->autreMagasin, fn (): Produit => Produit::factory()->create(['nom' => 'Tasse en grès', 'reference' => 'TASSE-GRES', 'stock_quantite' => 0]));
    $responsable = $this->membre($societe->autreMagasin, RoleMagasin::Responsable, identite: ['name' => 'Gaël Roux']);
    $onglet = $this->seConnecter($responsable)->aller('/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture/create');

    $onglet->choisirDansLaListe('Produit déjà au catalogue, jamais reçu', 'Tasse en grès')
        ->remplirLaLigne('lignes', 0, 'quantite', '6')
        ->remplirLaLigne('lignes', 0, 'cout_unitaire_ht', '3.25')
        ->cliquerSur('Ajouter un produit');
    $seconde = '.choices:has([id='.Navigation::texte($onglet->page()->evaluate('() => [...document.querySelectorAll(\'select[id*=".lignes."][id$=".produit_id"]\')][1].id')).'])';
    $onglet->cliquer($seconde.' .choices__inner');
    $onglet->geste(fn () => $onglet->element($seconde.' input.choices__input--cloned')->type('Tasse'), 'la recherche de la tasse sur la seconde ligne');
    $onglet->cliquer($seconde.' .choices__list--dropdown .choices__item--choice:has-text('.Navigation::texte('Tasse en grès').')')
        ->voir('« Tasse en grès » était déjà sur une ligne de cet inventaire, qui n\'a qu\'une ligne par produit : sa quantité passe à 7. Vérifiez son coût unitaire.');
    expect(quantitesDesLignes($onglet))->toBe(['7']);

    $onglet->soumettre('Créer')->attendreLaPage('^/app/'.$societe->autreMagasin->uuid.'/inventaires-ouverture$');
    $inventaire = app(TenantContext::class)->runAs($societe->autreMagasin, fn (): InventaireOuverture => InventaireOuverture::query()->latest('id')->firstOrFail()->load('lignes'));
    expect($inventaire->lignes->map(fn ($ligne): array => [$ligne->description, $ligne->quantite, (string) $ligne->cout_unitaire_ht])->all())->toBe([['Tasse en grès', 7, '3.25']]);
});
