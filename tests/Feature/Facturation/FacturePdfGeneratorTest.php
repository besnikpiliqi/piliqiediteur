<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacturePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_un_pdf_non_vide_pour_une_facture_emise(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 42, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $pdf = app(FacturePdfGenerator::class)->generer($facture);
        $contenu = $pdf->output();

        $this->assertStringStartsWith('%PDF', $contenu);
        $this->assertGreaterThan(500, strlen($contenu));
    }

    /**
     * Le client archivé depuis doit toujours sa facture : elle se
     * réimprime (voir Facture::client()).
     */
    public function test_genere_le_pdf_de_la_facture_d_un_client_archive(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(['nom' => 'Jeanne Archivée']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $client->delete();
        $facture = $facture->fresh();

        $generateur = app(FacturePdfGenerator::class);

        $this->assertStringStartsWith('%PDF', $generateur->generer($facture)->output());
        $this->assertSame("ticket-{$facture->numero}.pdf", $generateur->nomFichier($facture));
        $this->assertStringContainsString('Jeanne Archivée', view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render());
    }

    /**
     * Une mise à jour groupée ne passe pas par le modèle, qui retire le
     * SIRET d'un particulier : la copie de son client que prend le ticket
     * le garde, mais seul un document à un professionnel imprime les
     * identifiants de son client.
     */
    public function test_le_siret_du_client_ne_s_imprime_que_pour_un_professionnel(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $professionnel = Client::factory()->professionnel()->create(['siret' => '12345678900012']);
        $particulier = Client::factory()->create(['nom' => 'Jean Dupont', 'adresse' => '3 place du Marché', 'code_postal' => '75011', 'ville' => 'Paris']);
        Client::query()->whereKey($particulier->id)->update(['siret' => '98765432100013']);
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $emettre = fn (Client $client): Facture => app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client->fresh(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        [$facture, $ticket] = [$emettre($professionnel), $emettre($particulier)];

        $this->assertStringContainsString('SIRET : 12345678900012<br>', $this->rendu($facture));
        $this->assertSame('98765432100013', $ticket->client_siret);
        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Client</div><strong>Jean Dupont</strong><br>3 place du Marché<br>75011 Paris<br></div>',
            $this->compacte($this->rendu($ticket)),
        );
    }

    /**
     * Le client d'une facture à un professionnel établi hors de France
     * s'imprime avec son adresse complète, son pays et son n° de TVA.
     */
    public function test_la_facture_imprime_l_adresse_le_pays_et_le_numero_de_tva_d_un_client_etranger(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->etranger('BE', 'BE0123456789')->create([
            'nom' => 'Brasserie de Namur',
            'adresse' => 'Rue de Fer 12',
            'complement_adresse' => 'Boîte 3',
            'code_postal' => '5000',
            'ville' => 'Namur',
        ]);
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Facturé à</div><strong>Brasserie de Namur</strong><br>Rue de Fer 12<br>Boîte 3<br>5000 Namur<br>BE<br>N° TVA : BE0123456789<br></div>',
            $this->compacte($this->rendu($facture)),
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function choixDeLoptionPourLesDebits(): array
    {
        return [
            'option choisie' => [true],
            'option non choisie' => [false],
        ];
    }

    #[DataProvider('choixDeLoptionPourLesDebits')]
    public function test_la_mention_de_loption_pour_les_debits_suit_le_choix_du_magasin(bool $optionDebits): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['option_debits_tva' => $optionDebits]));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertSame($optionDebits, str_contains($html, "Option pour le paiement de la taxe d'après les débits"));
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function naturesDesLignes(): array
    {
        return [
            'un écran seul' => [['bien'], 'Livraison de biens'],
            'une main-d\'œuvre seule' => [['service'], 'Prestation de services'],
            'un écran et sa pose' => [['bien', 'service'], 'Livraison de biens et prestation de services'],
        ];
    }

    /**
     * @param  list<string>  $natures
     */
    #[DataProvider('naturesDesLignes')]
    public function test_la_facture_indique_la_categorie_de_l_operation(array $natures, string $categorie): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, array_map(
            fn (string $nature): array => [
                ...($nature === 'bien' ? ['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id] : ['service_id' => Service::factory()->create()->id]),
                'prix_unitaire_ttc' => 60,
                'taux_tva' => 20,
                'quantite' => 1,
            ],
            $natures,
        )));

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringContainsString("Catégorie de l'opération : {$categorie}</div>", $html);
    }

    /**
     * Les mentions du vendeur (voir IdentiteMagasin) : raison sociale et
     * forme juridique, adresse complète, SIREN, SIRET, n° de TVA et
     * coordonnées.
     */
    public function test_la_facture_imprime_l_identite_legale_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create([
            'nom' => 'Boutique du Port',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'siren' => '123456789',
            'siret' => '12345678900012',
            'numero_tva' => 'FR32123456789',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => 'Bâtiment B',
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'telephone' => '04 91 00 00 00',
            'email' => 'contact@boutique-du-port.fr',
        ]));
        $facture = $this->factureEmise();

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringContainsString(
            '<div class="magasin-nom">Boutique du Port</div>'
            .'Martin et Fils SARL<br>12 quai des Pêcheurs<br>Bâtiment B<br>13002 Marseille<br>FR<br>'
            .'SIREN : 123456789<br>SIRET : 12345678900012<br>TVA intracommunautaire : FR32123456789<br>'
            .'Tél. 04 91 00 00 00 · contact@boutique-du-port.fr',
            preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html),
        );
    }

    /**
     * Un magasin qui n'a pas encore rempli son identité n'imprime ni ligne
     * vide ni libellé sans valeur.
     */
    public function test_l_identite_non_renseignee_ne_s_imprime_pas(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create([
            'nom' => 'Boutique du Port',
            'raison_sociale' => null,
            'forme_juridique' => null,
            'siren' => null,
            'siret' => null,
            'numero_tva' => null,
            'adresse' => null,
            'complement_adresse' => null,
            'code_postal' => null,
            'ville' => null,
            'pays' => 'FR',
            'telephone' => null,
            'email' => null,
        ]));
        $facture = $this->factureEmise();

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $enTete = Str::betweenFirst($html, '<table class="en-tete">', '</table>');
        $this->assertStringContainsString('<div class="magasin-nom">Boutique du Port</div></td>', $this->compacte($enTete));
        $this->assertStringNotContainsString('SIREN', $enTete);
        $this->assertStringNotContainsString('TVA intracommunautaire', $enTete);
        $this->assertStringNotContainsString('Tél.', $enTete);
    }

    public function test_le_nom_de_fichier_utilise_le_numero_ou_brouillon(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertStringContainsString('brouillon-', $generateur->nomFichier($facture));

        $facture = app(EmettreFactureAction::class)->handle($facture);
        $this->assertSame("facture-{$facture->numero}.pdf", $generateur->nomFichier($facture));
    }

    /**
     * Un client particulier relève de l'e-reporting agrégé, pas d'une
     * facture transmise individuellement (voir
     * Facture::relevantDeLeInvoicing) : le document imprimé porte le
     * libellé usuel "ticket" plutôt que "facture", même s'il s'agit du
     * même enregistrement en base.
     */
    public function test_le_document_est_nomme_ticket_pour_un_client_particulier(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(); // particulier par défaut
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertSame("ticket-{$facture->numero}.pdf", $generateur->nomFichier($facture));

        // Le binaire PDF compilé n'est pas cherchable en texte brut
        // (flux compressés) : on vérifie le libellé sur le gabarit Blade
        // lui-même plutôt que sur la sortie de dompdf.
        $html = view('factures.pdf', ['facture' => $facture->load('client', 'lignes', 'magasin')])->render();
        $this->assertStringContainsString('Ticket de caisse', $html);
        $this->assertStringNotContainsString('>Facturé à<', $html);
    }

    /**
     * Un montant déjà réglé (encaissement et/ou crédit d'avoir) doit
     * apparaître sur le document remis au client -- sans ça, rien ne
     * prouve sur le PDF qu'une vente entièrement couverte par un avoir a
     * bien été soldée (voir AppliquerAvoirsDisponiblesAction).
     */
    public function test_le_credit_applique_par_un_avoir_apparait_sur_le_pdf(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $premiereFacture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        // Nouvel achat du même montant : couvert automatiquement par le crédit.
        $nouvelleFacture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $nouvelleFacture = app(EmettreFactureAction::class)->handle($nouvelleFacture)->fresh();

        $html = view('factures.pdf', [
            'facture' => $nouvelleFacture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringContainsString('Crédité par avoir', $html);
        $this->assertStringContainsString($avoir->fresh()->numero, $html);
        $this->assertStringContainsString('60,00', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }

    public function test_aucune_mention_de_reglement_sur_une_facture_non_payee(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringNotContainsString('Payé', $html);
        $this->assertStringNotContainsString('Crédité par avoir', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }

    private function rendu(Facture $facture): string
    {
        return view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }

    private function factureEmise(): Facture
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->professionnel()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }
}
