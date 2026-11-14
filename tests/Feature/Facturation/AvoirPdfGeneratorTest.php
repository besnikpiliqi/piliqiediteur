<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AvoirPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un avoir rectifie une facture : il en reprend les mentions
     * obligatoires.
     */
    public function test_lavoir_porte_la_mention_de_loption_pour_les_debits_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['option_debits_tva' => true])->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $html = app(AvoirPdfGenerator::class)->html($avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'));

        $this->assertStringContainsString(e("Option pour le paiement de la taxe d'après les débits"), $html);
    }

    /**
     * Mêmes mentions du vendeur que sur la facture qu'il rectifie.
     */
    public function test_lavoir_imprime_l_identite_legale_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'siren' => '121212120',
            'numero_tva' => 'FR56121212120',
        ])->create([
            'adresse' => '12 quai des Pêcheurs',
            'code_postal' => '13002',
            'ville' => 'Marseille',
        ]));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $html = app(AvoirPdfGenerator::class)->html($avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'));

        foreach (['Martin et Fils SARL', '12 quai des Pêcheurs', '13002 Marseille', 'SIREN : 121212120', 'TVA intracommunautaire : FR56121212120'] as $mention) {
            $this->assertStringContainsString($mention, $html);
        }
    }

    /**
     * L'avoir d'une facture à un professionnel établi hors de France
     * imprime son client comme la facture : adresse complète, pays et n° de
     * TVA.
     */
    public function test_lavoir_imprime_l_adresse_le_pays_et_le_numero_de_tva_d_un_client_etranger(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->etranger('BE', 'BE0123456789')->create([
            'nom' => 'Brasserie de Namur',
            'adresse' => 'Rue de Fer 12',
            'complement_adresse' => 'Boîte 3',
            'code_postal' => '5000',
            'ville' => 'Namur',
        ]);
        $facture = $this->emettreUneFacture($client);

        $avoir = $this->crediter($client, $facture);

        $this->assertStringContainsString(
            '<td class="bloc"><div class="titre">Émis à</div><strong>Brasserie de Namur</strong><br>Rue de Fer 12<br>Boîte 3<br>5000 Namur<br>BE<br>N° TVA : BE0123456789<br></td>',
            $this->compacte($this->rendu($avoir)),
        );
    }

    /**
     * Un avoir sur un ticket corrige une vente à un particulier : il
     * imprime le client du ticket, sans identifiant, même si sa fiche,
     * devenue celle d'un professionnel depuis, en a un le jour de l'avoir.
     */
    public function test_lavoir_sur_un_ticket_n_imprime_aucun_identifiant_du_client(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(['nom' => 'Jean Ancien']);
        $ticket = $this->emettreUneFacture($client);
        $client->update(['nom' => 'Marc Nouveau', 'type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        $avoir = $this->crediter($client->fresh(), $ticket);

        $this->assertStringContainsString(
            '<td class="bloc"><div class="titre">Émis à</div><strong>Jean Ancien</strong><br></td>',
            $this->compacte($this->rendu($avoir)),
        );
    }

    /**
     * Un avoir rend le calcul de sa vente sur les articles rendus, par
     * différence avec les avoirs déjà émis sur la même ligne, le dernier
     * retour soldant la ligne : une note sous les lignes le dit tel qu'il
     * est fait, sur une facture comme sur un ticket. Sur trois lignes de
     * trois articles à 0,10 € à 20 % (0,02 € de TVA chacune, 0,06 € pour
     * une TVA du taux de 0,05 € : la première rend le centime, règle (b),
     * décision de l'éditeur du 14/11/2026), le retour de la première
     * crédite toute la ligne, 0,09 € + 0,01 €, et non 0,08 € + 0,02 € comme
     * une ligne seule : la note l'annonce ; sur l'avoir sur facture, qui
     * imprime le PU HT d'un article, prix TTC moins sa TVA arrondie au
     * centime, et en petit son PU HT exact (modèle M6-b), elle ajoute que la
     * quantité x ce prix peut différer de quelques centimes du total HT de
     * la ligne et ce qu'est le PU HT exact ; sur l'avoir sur ticket, qui ne
     * montre que des prix TTC (modèle P1), rien d'autre.
     */
    public function test_un_avoir_explique_le_calcul_de_ses_lignes(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $professionnel = Client::factory()->professionnel()->create();
        $particulier = Client::factory()->create();
        // Trois produits différents au même prix : un produit n'a qu'une
        // ligne (décision de l'éditeur du 26/10/2026, série 9).
        $lignes = array_map(fn (): array => ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => '0.10', 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1], range(1, 3));
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $professionnel, $lignes));

        $avoirProfessionnel = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $professionnel, [['ligne_facture_id' => $facture->lignes->sortBy('ordre')->firstOrFail()->id, 'quantite' => 1]]);
        $avoirSurTicket = $this->crediter($particulier, $this->emettreUneFacture($particulier));

        $this->assertSame(['0.09', '0.01', '0.10'], [$avoirProfessionnel->total_ht, $avoirProfessionnel->total_tva, $avoirProfessionnel->total_ttc]);
        $rendu = $this->compacte($this->rendu($avoirProfessionnel));
        $this->assertStringContainsString('<td class="montant">0,09 €</td><td class="montant">0,01 €</td><td class="montant">0,10 €</td>', $rendu);
        $this->assertStringContainsString('<div class="note-calcul">Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d&#039;une ligne rendus jusqu&#039;ici, le calcul de la vente sur leur TTC (TVA = TTC × taux ÷ (1 + taux), arrondie une fois au centime ; HT = TTC moins cette TVA), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime ; un centime peut être ajusté sur une ligne rendue en partie pour rapprocher la TVA de chaque taux de celle de son TTC total, calculée de même. Le PU HT affiché est celui d&#039;un article : prix TTC moins sa TVA arrondie au centime (9,99 € TTC à 20 % : 8,32 € HT). La quantité × ce prix peut différer de quelques centimes du total HT de la ligne, calculé sur toute la quantité. Le PU HT exact, écrit sous chaque article, est celui de la facture électronique : la quantité × ce prix retombe sur le total HT de la ligne à quelques centimes près.</div>', $rendu);
        $this->assertStringContainsString('<div class="note-calcul">Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d&#039;une ligne rendus jusqu&#039;ici, le calcul de la vente sur leur TTC (TVA = TTC × taux ÷ (1 + taux), arrondie une fois au centime ; HT = TTC moins cette TVA), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime ; un centime peut être ajusté sur une ligne rendue en partie pour rapprocher la TVA de chaque taux de celle de son TTC total, calculée de même.</div>', $this->compacte($this->rendu($avoirSurTicket)));
    }

    /**
     * Un avoir cite le numéro et la date d'émission de chaque document
     * qu'il rectifie, une ligne chacun.
     */
    public function test_lavoir_cite_le_numero_et_la_date_de_chaque_document_qu_il_rectifie(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'MABOUT']));
        $client = Client::factory()->professionnel()->create();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $premiere = $this->emettreUneFacture($client);
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $seconde = $this->emettreUneFacture($client);

        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $premiere->lignes->firstOrFail()->id, 'quantite' => 1],
            ['ligne_facture_id' => $seconde->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);

        $this->assertStringContainsString(
            '<strong>Sur facture FAC-MABOUT-2026-000001 du 09/10/2026</strong><br>Sur facture FAC-MABOUT-2026-000002 du 12/10/2026<br></td>',
            $this->compacte($this->rendu($avoir)),
        );
    }

    /**
     * Sur un ticket, l'avoir le nomme comme tel.
     */
    public function test_lavoir_sur_un_ticket_cite_le_ticket_et_sa_date(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'MABOUT']));
        $client = Client::factory()->create();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));

        $avoir = $this->crediter($client, $this->emettreUneFacture($client));

        $this->assertStringContainsString('<strong>Sur ticket de caisse TK-MABOUT-2026-000001 du 09/10/2026</strong><br></td>', $this->compacte($this->rendu($avoir)));
    }

    /**
     * K6 : l'avoir d'un ticket comptoir de 12,00 € annonce que son numéro
     * vaut bon d'avoir de 12,00 €, montant signé à son émission : il le
     * garde une fois le bon utilisé pour une vente de 20,00 €.
     */
    public function test_l_avoir_d_un_client_comptoir_vaut_bon_d_avoir_du_montant_de_son_emission(): void
    {
        app(TenantContext::class)->set($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);
        $avoir = $this->crediter($comptoir, $ticket);
        self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes, bonsDAvoir: [$avoir->numero]);

        $html = $this->rendu($avoir->fresh());

        $this->assertSame(0, $avoir->fresh()->creditEnCentimes());
        $this->assertStringContainsString('<div class="pied">Ce n° vaut bon d&#039;avoir de 12,00 € à présenter lors d&#039;un prochain achat.</div>', $this->compacte($html));
    }

    /**
     * Le crédit de l'avoir d'un client nommé lui appartient : son avoir
     * n'annonce aucun bon d'avoir.
     */
    public function test_l_avoir_d_un_client_nomme_n_annonce_aucun_bon_d_avoir(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);

        $avoir = $this->crediter($client, $ticket);

        $this->assertSame(1200, $avoir->fresh()->creditEnCentimes());
        $this->assertStringNotContainsString('bon d&#039;avoir', $this->rendu($avoir));
    }

    /**
     * Le PDF d'un avoir est, comme celui d'une facture, une vraie page A4
     * en portrait, numérotée (point 4).
     */
    public function test_le_pdf_d_un_avoir_est_une_page_a4_numerotee(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $avoir = $this->crediter($client, $this->emettreUneFacture($client));

        $pages = $this->pagesDuPdf(app(AvoirPdfGenerator::class)->generer($avoir)->output());

        $this->assertSame(['0.000 0.000 595.280 841.890'], array_column($pages, 'format'));
        $this->assertContains('Page 1 sur 1', array_column($pages[0]['textes'], 'texte'));
    }

    /**
     * Un avoir sur une facture imprime, comme elle, le PU HT et le total HT
     * de chaque ligne, avec son PU HT exact sous l'article (modèle M6-b) ;
     * un avoir sur un ticket, le PU TTC et le total TTC (modèle P1).
     * Chacun indique la facture ou le ticket de chaque ligne, son numéro
     * entier sur une seule ligne, comme la référence du produit (point 3).
     */
    public function test_un_avoir_sur_facture_imprime_les_colonnes_ht_et_un_avoir_sur_ticket_les_colonnes_ttc(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'MABOUT']));
        $produit = Produit::factory()->create(['reference' => 'TAS-01', 'nom' => 'Tasse', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $professionnel = Client::factory()->professionnel()->create();
        $particulier = Client::factory()->create();
        $vendre = fn (Client $client): Facture => app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));

        $surFacture = $this->tableauDesLignes($this->crediter($professionnel, $vendre($professionnel)));
        $surTicket = $this->tableauDesLignes($this->crediter($particulier, $vendre($particulier)));

        $this->assertStringContainsString('<tr><th>Réf.</th><th>Article</th><th>Facture d\'origine</th><th class="montant">Qté</th><th class="montant">PU HT</th><th class="montant">Rem.</th><th class="montant">TVA</th><th class="montant">Total HT</th></tr>', $surFacture);
        $this->assertStringContainsString('<tr><td class="reference">TAS-01</td><td>Tasse<span class="sous">PU HT exact : 10,000000 €</span></td><td class="numero">FAC-MABOUT-2026-000001</td><td class="montant">1</td><td class="montant">10,00 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">10,00 €</td></tr>', $surFacture);
        $this->assertStringContainsString('<tr><th>Réf.</th><th>Article</th><th>Facture d\'origine</th><th class="montant">Qté</th><th class="montant">PU TTC</th><th class="montant">Rem.</th><th class="montant">TVA</th><th class="montant">Total TTC</th></tr>', $surTicket);
        $this->assertStringContainsString('<tr><td class="reference">TAS-01</td><td>Tasse</td><td class="numero">TK-MABOUT-2026-000001</td><td class="montant">1</td><td class="montant">12,00 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">12,00 €</td></tr>', $surTicket);
    }

    /**
     * Un avoir a une colonne de plus que la facture, son n° de facture
     * d'origine, le plus long qu'un code de magasin de 10 caractères donne :
     * avec une référence de 60 caractères, une remise et de grands
     * montants, le tableau reste dans la largeur de la page, chaque colonne
     * obligatoire et chaque texte entre les marges ; la référence s'imprime
     * entière sur une seule ligne, dans un corps réduit. 8 000 des 9 000
     * articles à 9 999,97 € TTC remisés de 12,5 % : 69 999 790,00 € TTC,
     * dont 11 666 631,666... -> 11 666 631,67 € de TVA, soit 58 333 158,33 €
     * HT à 20 % (règle (b)), au PU HT d'un article de 9 999,97 €
     * moins sa TVA de 1 666,66 €, 8 333,31 € (décision de l'éditeur du
     * 14/11/2026).
     */
    public function test_une_reference_de_60_caracteres_et_de_grands_montants_laissent_toutes_les_colonnes_de_l_avoir_dans_la_page(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'ABCDEFGHIJ']));
        $reference = 'CABLE-USB-C-VERS-USB-C-TRESSE-NYLON-100W-2M-NOIR-MODELE-2026';
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['reference' => $reference, 'nom' => 'Câble USB-C vers USB-C tressé nylon', 'prix_vente_ttc' => '9999.97', 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 9000, 'remise_pourcentage' => 12.5],
        ]));
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 8000]]);

        $page = $this->pagesDuPdf(app(AvoirPdfGenerator::class)->generer($avoir)->output())[0];

        $this->assertChaqueTexteTientEntreLesMarges($page);
        foreach (['FACTURE D\'ORIGINE', 'QTÉ', 'PU HT', 'REM.', 'TVA', 'TOTAL HT', $reference, 'FAC-ABCDEFGHIJ-2026-000001', "8\u{202F}000", '8 333,31 €', '12,5 %', '58 333 158,33 €'] as $texte) {
            $this->assertContains($texte, array_column($page['textes'], 'texte'));
        }
        $this->assertMatchesRegularExpression('#<td class="reference" style="font-size: [1-7]\.\dpt">'.$reference.'</td>#', $this->tableauDesLignes($avoir));
    }

    /**
     * Le pied de page de l'avoir d'un client comptoir porte l'identité de
     * la société, les mentions du ticket qu'il rectifie et, en dernier, la
     * mention de son bon d'avoir ; son en-tête ne garde que le magasin
     * (point 4).
     */
    public function test_l_identite_de_la_societe_et_les_mentions_de_l_avoir_sont_dans_le_pied_de_page(): void
    {
        app(TenantContext::class)->set($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'raison_sociale' => 'Martin et Fils', 'forme_juridique' => FormeJuridique::Sas, 'option_debits_tva' => true]));
        $comptoir = Client::query()->where('est_comptoir', true)->sole();
        $ticket = self::vendreAuComptant(self::auteur(), $comptoir, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]], ModePaiement::Especes);

        $html = $this->compacte($this->rendu($this->crediter($comptoir, $ticket)));

        preg_match_all('#<div class="(?:identite|pied)">(.*?)</div>#', Str::betweenFirst($html, '<div id="pied-de-page">', '</div></div>').'</div>', $pied);
        $this->assertSame([
            'Martin et Fils SAS',
            'Catégorie de l\'opération : Livraison de biens',
            'Option pour le paiement de la taxe d\'après les débits',
            'Ce n° vaut bon d\'avoir de 12,00 € à présenter lors d\'un prochain achat.',
        ], array_map(fn (string $texte): string => html_entity_decode($texte, ENT_QUOTES), array_map(fn (string $texte): string => Str::before($texte, ' · '), $pied[1])));
        $this->assertStringNotContainsString('SIREN', Str::betweenFirst($html, '<table class="en-tete">', '</table>'));
    }

    /**
     * Le tableau des lignes du PDF de l'avoir, sans les blancs autour de
     * ses balises.
     */
    private function tableauDesLignes(Avoir $avoir): string
    {
        return Str::betweenFirst($this->compacte($this->rendu($avoir)), '<table class="lignes">', '</table>');
    }

    private function emettreUneFacture(Client $client): Facture
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    private function crediter(Client $client, Facture $facture): Avoir
    {
        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
    }

    private function rendu(Avoir $avoir): string
    {
        return app(AvoirPdfGenerator::class)->html($avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'));
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }
}
