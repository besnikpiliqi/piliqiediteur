<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\ChiffreDAffaires;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Widgets\FacturesApercu;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La politique d'arrondi unique de l'éditeur (point 15, réponses Q1-a et
 * Q2) suivie de l'écran de la caisse jusqu'à la clôture : toute vente, à un
 * particulier comme à un professionnel, se calcule une fois depuis ses
 * prix TTC -- le HT de chaque taux est le TTC du taux ÷ (1 + taux), arrondi
 * une fois au centime, un demi-centime au centime supérieur, réparti sur
 * les lignes ; la TVA est la différence -- et ensuite rien ne se
 * recalcule : le document signé, son PDF, sa fiche, la clôture et le
 * tableau de bord reprennent ou additionnent les montants enregistrés.
 */
class PolitiqueDArrondiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le magasin, à l'identité légale complète, et son responsable de test
     * connecté, qui y vend, aussi aux professionnels.
     */
    private function magasin(): Magasin
    {
        $this->travelTo('2026-10-14 10:00:00');
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);

        return $magasin;
    }

    private function article(string $nom, string $prixTtc, float $taux = 20): Produit
    {
        return Produit::factory()->create(['nom' => $nom, 'prix_vente_ttc' => $prixTtc, 'taux_tva' => $taux, 'suivi_stock' => false]);
    }

    /**
     * Une vente encaissée à la caisse, en espèces.
     *
     * @param  list<array{Produit, int}>  $articles
     */
    private function vendreALaCaisse(Client $client, array $articles): Facture
    {
        return self::vendreAuComptant(self::auteur(), $client, self::lignes($articles), ModePaiement::Especes);
    }

    /**
     * Une facture émise à un professionnel, créée depuis « Factures ».
     *
     * @param  list<array{Produit, int}>  $articles
     */
    private function facturerUnProfessionnel(array $articles): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), self::lignes($articles)));
    }

    /**
     * @param  list<array{Produit, int}>  $articles
     * @return list<array{produit_id: int, quantite: int}>
     */
    private static function lignes(array $articles): array
    {
        return array_map(fn (array $article): array => ['produit_id' => $article[0]->id, 'quantite' => $article[1]], $articles);
    }

    private function comptoir(): Client
    {
        return Client::query()->where('est_comptoir', true)->sole();
    }

    /**
     * @return list<list<string>> le HT, la TVA et le TTC de chaque ligne, dans leur ordre
     */
    private function montantsDesLignes(Facture|Avoir $document): array
    {
        return $document->lignes()->orderBy('ordre')->get()
            ->map(fn (object $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])
            ->all();
    }

    /**
     * @return array<string, mixed> le document tel que l'émission l'a signé au registre fiscal
     */
    private function documentSigne(Facture $facture): array
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Vente->value)
            ->where('document_type', $facture->getMorphClass())
            ->where('document_id', $facture->getKey())
            ->sole()
            ->contenuSigne()['donnees']['document'];
    }

    private function pdf(Facture $facture): string
    {
        return app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
    }

    /**
     * Le cas obligatoire de l'éditeur, sur un même ticket : 2 x 25,00 € TTC
     * à 20 % font 41,67 € HT et 8,33 € de TVA (50,00 ÷ 1,2 = 41,666...) sur
     * le ticket enregistré, son contenu signé (en centimes), son PDF, sa
     * fiche, la clôture du jour qui ne compte que lui et le tableau de
     * bord -- jamais 2 x 20,83 € = 41,66 € et 8,34 €.
     */
    public function test_un_ticket_de_deux_articles_a_25_euros_porte_41_67_ht_sur_le_ticket_son_pdf_sa_fiche_et_la_cloture_qui_ne_compte_que_lui(): void
    {
        $magasin = $this->magasin();
        $tshirt = $this->article('T-shirt', '25.00');

        $ticket = $this->vendreALaCaisse($this->comptoir(), [[$tshirt, 2]]);

        $this->assertSame(['41.67', '8.33', '50.00'], [$ticket->total_ht, $ticket->total_tva, $ticket->total_ttc]);
        $this->assertSame([['41.67', '8.33', '50.00']], $this->montantsDesLignes($ticket));
        $signe = $this->documentSigne($ticket);
        $this->assertSame([['ht' => 4167, 'tva' => 833, 'ttc' => 5000]], array_map(fn (array $ligne): array => Arr::only($ligne, ['ht', 'tva', 'ttc']), $signe['lignes']));
        $this->assertSame([['taux' => 2000, 'ht' => 4167, 'tva' => 833, 'ttc' => 5000]], $signe['ventilation']);
        $this->assertSame(['ht' => 4167, 'tva' => 833, 'ttc' => 5000], $signe['totaux']);
        $pdf = $this->pdf($ticket);
        $this->assertStringContainsString('41,67 €', $pdf);
        $this->assertStringContainsString('8,33 €', $pdf);
        $this->assertStringNotContainsString('41,66 €', $pdf);
        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertSee('41,67')
            ->assertSee('8,33')
            ->assertDontSee('41,66');
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $ticket->date_emission);
        $this->assertSame(['41.67', '8.33', '50.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame(['ht' => 41.67, 'tva' => 8.33, 'ttc' => 50.0, 'factures' => 1, 'avoirs' => 0], app(ChiffreDAffaires::class)->netSurPeriode(now()->startOfDay(), now()->endOfDay()));
        Livewire::test(FacturesApercu::class)->assertSeeInOrder(['Cette semaine', '41,67 € HT', '50,00 € TTC · 1 facture']);
    }

    /**
     * 50,00 € TTC à 20 % : en une ligne, en 2 x 25,00 € ou en deux lignes
     * de 25,00 €, pour un particulier comme pour un professionnel, l'écran
     * de la caisse affiche 41,67 € HT et 8,33 € de TVA, et la vente
     * encaissée les porte, comme son contenu signé, son PDF et sa fiche ;
     * la clôture du ticket aussi. Deux lignes de 25,00 € reçoivent 20,83 €
     * puis 20,84 € HT.
     *
     * @return array<string, array{list<array{string, int}>, bool, list<list<string>>}>
     */
    public static function decoupagesDeCinquanteEuros(): array
    {
        $uneLigne = [['50.00', 1]];
        $deuxArticles = [['25.00', 2]];
        $deuxLignes = [['25.00', 1], ['25.00', 1]];

        return [
            'une ligne, particulier' => [$uneLigne, false, [['41.67', '8.33', '50.00']]],
            'une ligne, professionnel' => [$uneLigne, true, [['41.67', '8.33', '50.00']]],
            '2 x 25,00 €, particulier' => [$deuxArticles, false, [['41.67', '8.33', '50.00']]],
            '2 x 25,00 €, professionnel' => [$deuxArticles, true, [['41.67', '8.33', '50.00']]],
            'deux lignes de 25,00 €, particulier' => [$deuxLignes, false, [['20.83', '4.17', '25.00'], ['20.84', '4.16', '25.00']]],
            'deux lignes de 25,00 €, professionnel' => [$deuxLignes, true, [['20.83', '4.17', '25.00'], ['20.84', '4.16', '25.00']]],
        ];
    }

    /**
     * @param  list<array{string, int}>  $articles  [PU TTC, quantité] de chaque ligne
     * @param  list<list<string>>  $lignesAttendues
     */
    #[DataProvider('decoupagesDeCinquanteEuros')]
    public function test_cinquante_euros_ttc_font_41_67_ht_a_l_ecran_et_sur_la_vente_quel_que_soit_le_decoupage_et_le_client(array $articles, bool $professionnel, array $lignesAttendues): void
    {
        $magasin = $this->magasin();
        $client = $professionnel ? Client::factory()->professionnel()->create() : $this->comptoir();
        $lignes = array_map(function (array $article): array {
            $produit = $this->article("Article à {$article[0]} €", $article[0]);

            return ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => $article[1], 'prix_unitaire_ttc' => $article[0], 'remise_pourcentage' => 0];
        }, $articles);

        Livewire::test(Caisse::class)
            ->fillForm(['client_id' => $client->id, 'lignes' => $lignes, 'mode_paiement' => ModePaiement::Especes->value, 'montant_recu' => 50])
            ->assertSee('Total HT 41,67 € · TVA 8,33 € · Total TTC 50,00 €')
            ->call('encaisser')
            ->assertHasNoFormErrors();

        $vente = Facture::query()->where('client_id', $client->id)->sole();
        $this->assertSame(['41.67', '8.33', '50.00'], [$vente->total_ht, $vente->total_tva, $vente->total_ttc]);
        $this->assertSame($lignesAttendues, $this->montantsDesLignes($vente));
        $this->assertSame(['ht' => 4167, 'tva' => 833, 'ttc' => 5000], $this->documentSigne($vente)['totaux']);
        $this->assertSame([['taux' => 2000, 'ht' => 4167, 'tva' => 833, 'ttc' => 5000]], $this->documentSigne($vente)['ventilation']);
        $this->assertStringContainsString('41,67 €', $this->pdf($vente));
        $this->assertStringNotContainsString('41,66 €', $this->pdf($vente));
        Livewire::test(ViewFacture::class, ['record' => $vente->getRouteKey()])->assertSee('41,67')->assertSee('8,33');

        if (! $professionnel) {
            $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $vente->date_emission);
            $this->assertSame(['41.67', '8.33', '50.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        }
    }

    /**
     * Deux tickets séparés d'un article à 25,00 € sont justes chacun
     * (20,83 € HT et 4,17 € de TVA) ; la clôture et le chiffre d'affaires
     * du jour les additionnent : 41,66 € HT et 8,34 € de TVA, jamais
     * « 50,00 ÷ 1,2 » recalculé sur le total (exigence 8 du point 15).
     */
    public function test_deux_tickets_de_25_euros_font_41_66_ht_dans_la_cloture_qui_additionne_les_tickets(): void
    {
        $magasin = $this->magasin();
        $tshirt = $this->article('T-shirt', '25.00');

        $premier = $this->vendreALaCaisse($this->comptoir(), [[$tshirt, 1]]);
        $second = $this->vendreALaCaisse($this->comptoir(), [[$tshirt, 1]]);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $premier->date_emission);

        $this->assertSame(['20.83', '4.17', '25.00'], [$premier->total_ht, $premier->total_tva, $premier->total_ttc]);
        $this->assertSame(['20.83', '4.17', '25.00'], [$second->total_ht, $second->total_tva, $second->total_ttc]);
        $this->assertSame(['41.66', '8.34', '50.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame(['ht' => 41.66, 'tva' => 8.34, 'ttc' => 50.0, 'factures' => 2, 'avoirs' => 0], app(ChiffreDAffaires::class)->netSurPeriode(now()->startOfDay(), now()->endOfDay()));
    }

    /**
     * Le même panier, deux lignes de 25,00 € et un article à 9,99 €, a les
     * mêmes montants en ticket et en facture à un professionnel : le HT du
     * taux, 59,99 ÷ 1,2 = 49,99 €, se répartit 20,83 €, 20,84 € et 8,32 €,
     * et la TVA fait 10,00 €.
     */
    public function test_le_meme_panier_donne_les_memes_montants_a_un_particulier_et_a_un_professionnel(): void
    {
        $this->magasin();
        $tshirt = $this->article('T-shirt', '25.00');
        $chaussettes = $this->article('Chaussettes', '9.99');
        $panier = [[$tshirt, 1], [$tshirt, 1], [$chaussettes, 1]];

        $ticket = $this->vendreALaCaisse(Client::factory()->create(), $panier);
        $facture = $this->facturerUnProfessionnel($panier);

        foreach ([$ticket, $facture] as $vente) {
            $this->assertSame([['20.83', '4.17', '25.00'], ['20.84', '4.16', '25.00'], ['8.32', '1.67', '9.99']], $this->montantsDesLignes($vente));
            $this->assertSame([['taux_tva' => '20.00', 'montant_ht' => 49.99, 'montant_tva' => 10.0, 'montant_ttc' => 59.99]], $vente->ventilation_tva);
            $this->assertSame(['49.99', '10.00', '59.99'], [$vente->total_ht, $vente->total_tva, $vente->total_ttc]);
        }
        $this->assertStringStartsWith('TK-', $ticket->numero);
        $this->assertStringStartsWith('FAC-', $facture->numero);
    }

    /**
     * Un professionnel paie le prix affiché : un article à 9,99 € TTC lui
     * est facturé 9,99 € (8,33 € HT et 1,66 € de TVA), jamais 10,00 €.
     */
    public function test_une_facture_professionnelle_n_ajoute_jamais_un_centime_au_prix(): void
    {
        $this->magasin();

        $facture = $this->facturerUnProfessionnel([[$this->article('Chaussettes', '9.99'), 1]]);

        $this->assertSame(['8.33', '1.66', '9.99'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Le retour complet d'une vente de plusieurs lignes et deux taux, en
     * ticket comme en facture, reprend exactement les montants de chaque
     * ligne et du document.
     */
    public function test_un_retour_complet_reprend_exactement_les_montants_de_la_vente(): void
    {
        $this->magasin();
        $tshirt = $this->article('T-shirt', '25.00');
        $livre = $this->article('Livre', '10.00', 5.5);
        $panier = [[$tshirt, 1], [$tshirt, 1], [$livre, 2]];
        $ticket = $this->vendreALaCaisse(Client::factory()->create(), $panier);
        $facture = $this->facturerUnProfessionnel($panier);

        foreach ([$ticket, $facture] as $vente) {
            $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $vente->client, $vente->lignes()->orderBy('ordre')->get()->map(fn (object $ligne): array => ['ligne_facture_id' => $ligne->id, 'quantite' => $ligne->quantite])->all(), 'Retour complet');

            $this->assertSame([['20.83', '4.17', '25.00'], ['20.84', '4.16', '25.00'], ['18.96', '1.04', '20.00']], $this->montantsDesLignes($vente));
            $this->assertSame($this->montantsDesLignes($vente), $this->montantsDesLignes($avoir));
            $this->assertSame([$vente->total_ht, $vente->total_tva, $vente->total_ttc], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]);
        }
    }

    /**
     * 3 x 0,99 € (2,97 € TTC : 2,475 -> 2,48 € HT et 0,49 € de TVA), rendus
     * un par un, en ticket comme en facture : 0,83 € + 0,16 €, puis 0,82 € +
     * 0,17 € (1,98 € rendus en tout, 1,65 € HT), puis 0,83 € + 0,16 €, qui
     * soldent la ligne au centime.
     */
    public function test_les_retours_partiels_soldent_la_ligne_au_centime(): void
    {
        $this->magasin();
        $stylo = $this->article('Stylo', '0.99');
        $ticket = $this->vendreALaCaisse(Client::factory()->create(), [[$stylo, 3]]);
        $facture = $this->facturerUnProfessionnel([[$stylo, 3]]);

        foreach ([$ticket, $facture] as $vente) {
            $ligne = $vente->lignes->sole();
            $retours = array_map(
                fn (): array => $this->montantsDesLignes(app(EnregistrerAvoirAction::class)->handle(self::auteur(), $vente->client, [['ligne_facture_id' => $ligne->id, 'quantite' => 1]], 'Retour'))[0],
                range(1, 3),
            );

            $this->assertSame(['2.48', '0.49', '2.97'], [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
            $this->assertSame([['0.83', '0.16', '0.99'], ['0.82', '0.17', '0.99'], ['0.83', '0.16', '0.99']], $retours);
        }
    }

    /**
     * Une journée de trois tickets (2 x 25,00 €, 2 x 17,50 € et un livre à
     * 10,00 € à 5,5 %) et d'un avoir partiel (un des deux articles à
     * 25,00 €) : la clôture est la somme au centime des documents, 70,00 €
     * TTC, 59,49 € HT et 10,51 € de TVA, et sa ventilation la somme de
     * leurs lignes -- à 20 %, 50,01 € HT pour 60,00 € TTC, jamais 60,00 ÷
     * 1,2 = 50,00 € recalculé.
     */
    public function test_la_cloture_additionne_les_tickets_et_les_avoirs_enregistres(): void
    {
        $magasin = $this->magasin();
        $tshirt = $this->article('T-shirt', '25.00');
        $casquette = $this->article('Casquette', '17.50');
        $livre = $this->article('Livre', '10.00', 5.5);
        $premier = $this->vendreALaCaisse($this->comptoir(), [[$tshirt, 2]]);
        $second = $this->vendreALaCaisse($this->comptoir(), [[$casquette, 2]]);
        $troisieme = $this->vendreALaCaisse($this->comptoir(), [[$livre, 1]]);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $this->comptoir(), [['ligne_facture_id' => $premier->lignes->sole()->id, 'quantite' => 1]], 'Retour');

        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $premier->date_emission);

        $this->assertSame(
            [['41.67', '8.33', '50.00'], ['29.17', '5.83', '35.00'], ['9.48', '0.52', '10.00'], ['20.83', '4.17', '25.00']],
            [[$premier->total_ht, $premier->total_tva, $premier->total_ttc], [$second->total_ht, $second->total_tva, $second->total_ttc], [$troisieme->total_ht, $troisieme->total_tva, $troisieme->total_ttc], [$avoir->total_ht, $avoir->total_tva, $avoir->total_ttc]],
        );
        $this->assertSame(['59.49', '10.51', '70.00'], [$cloture->total_ht, $cloture->total_tva, $cloture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 50.01, 'montant_tva' => 9.99, 'montant_ttc' => 60.0],
            ['taux_tva' => '5.50', 'montant_ht' => 9.48, 'montant_tva' => 0.52, 'montant_ttc' => 10.0],
        ], $cloture->ventilation_tva);
    }

    /**
     * Hors de la TVA française, un particulier paie le HT de son panier,
     * calculé de la même façon : 2 x 40,00 € TTC à 20 % font 66,67 € à
     * l'export, sans TVA, jamais 2 x 33,33 € = 66,66 €. En franchise en
     * base, il paie le prix TTC sans TVA : 80,00 €.
     */
    public function test_hors_tva_et_franchise_gardent_leur_principe(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
        $cable = $this->article('Câble', '40.00');
        $export = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $cable->id, 'quantite' => 2]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['regime_tva' => RegimeTva::FranchiseEnBase])->create(['code' => 'PARIS1']));
        $cableEnFranchise = $this->article('Câble', '40.00');

        $franchise = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $cableEnFranchise->id, 'quantite' => 2]]));

        $this->assertSame(['66.67', '0.00', '66.67'], [$export->total_ht, $export->total_tva, $export->total_ttc]);
        $this->assertSame(['80.00', '0.00', '80.00'], [$franchise->total_ht, $franchise->total_tva, $franchise->total_ttc]);
    }
}
