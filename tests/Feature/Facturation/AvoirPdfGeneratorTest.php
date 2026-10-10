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
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        app(TenantContext::class)->set(Magasin::factory()->create(['option_debits_tva' => true]));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $html = view('avoirs.pdf', [
            'avoir' => $avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'),
        ])->render();

        $this->assertStringContainsString("Option pour le paiement de la taxe d'après les débits", $html);
    }

    /**
     * Mêmes mentions du vendeur que sur la facture qu'il rectifie.
     */
    public function test_lavoir_imprime_l_identite_legale_du_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'siren' => '123456789',
            'numero_tva' => 'FR32123456789',
            'adresse' => '12 quai des Pêcheurs',
            'code_postal' => '13002',
            'ville' => 'Marseille',
        ]));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));

        $html = view('avoirs.pdf', [
            'avoir' => $avoir->load('client', 'lignes.ligneFacture.facture', 'magasin'),
        ])->render();

        foreach (['Martin et Fils SARL', '12 quai des Pêcheurs', '13002 Marseille', 'SIREN : 123456789', 'TVA intracommunautaire : FR32123456789'] as $mention) {
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
            '<div class="bloc-client"><div class="titre">Émis à</div><strong>Brasserie de Namur</strong><br>Rue de Fer 12<br>Boîte 3<br>5000 Namur<br>BE<br>N° TVA : BE0123456789<br></div>',
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
            '<div class="bloc-client"><div class="titre">Émis à</div><strong>Jean Ancien</strong><br></div>',
            $this->compacte($this->rendu($avoir)),
        );
    }

    private function emettreUneFacture(Client $client): Facture
    {
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    private function crediter(Client $client, Facture $facture): Avoir
    {
        return app(EnregistrerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
    }

    private function rendu(Avoir $avoir): string
    {
        return view('avoirs.pdf', ['avoir' => $avoir->load('client', 'lignes.ligneFacture.facture', 'magasin')])->render();
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }
}
