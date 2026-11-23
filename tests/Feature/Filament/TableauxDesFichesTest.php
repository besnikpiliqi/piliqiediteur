<?php

namespace Tests\Feature\Filament;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ViewClotureCaisse;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\TableauEntry;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les listes des fiches au modèle D choisi par l'éditeur le 13/11/2026
 * (lot DEMO-V, voir App\Filament\TableauEntry) : un seul tableau, les
 * titres une fois en haut, une ligne par élément dans l'ordre fixe, « – »
 * en gris pour une valeur nulle, la ligne en gris quand toutes ses
 * valeurs sont nulles, le total en dernière ligne, en gras, et les lignes
 * d'information dessous, en gris. Exemple de l'éditeur : la journée d'un
 * seul paiement par carte de 32,30 €.
 */
class TableauxDesFichesTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_valeur_chiffree_nulle_s_ecrit_d_un_tiret_et_un_taux_de_zero_reste(): void
    {
        foreach ([0, '0', '0.00', '0,00 €', "0,00\u{00A0}€", '-0,00 €'] as $nulle) {
            $this->assertTrue(TableauEntry::estNulle($nulle), var_export($nulle, true));
        }

        foreach ([null, '', '0 %', '32,30 €', 1, '0,01 €', 'Espèces'] as $valeur) {
            $this->assertFalse(TableauEntry::estNulle($valeur), var_export($valeur, true));
        }
    }

    public function test_les_encaissements_de_la_cloture_sont_un_seul_tableau_au_modele_d(): void
    {
        $html = Livewire::test(ViewClotureCaisse::class, ['record' => $this->journeeDUnPaiementParCarte()->getRouteKey()])->html();

        $this->assertSame(1, substr_count($html, '>Mode de paiement</th>'));
        $this->assertSame([
            ['', ['Mode de paiement', 'Paiements', 'Contre-passations', 'Montant encaissé']],
            ['piliqi-ligne-nulle', ['Espèces', '–', '–', '–']],
            ['', ['Carte bancaire', '1', '–', '32,30 €']],
            ['piliqi-ligne-nulle', ['Chèque', '–', '–', '–']],
            ['piliqi-ligne-nulle', ['Virement', '–', '–', '–']],
            ['piliqi-ligne-nulle', ['Prélèvement', '–', '–', '–']],
            ['piliqi-total', ['Total encaissé', '1', '–', '32,30 €']],
            ['piliqi-information', ['Crédit d\'avoir utilisé (n\'entre pas dans les encaissements)', '–']],
        ], self::lignesDuTableau($html, 'Mode de paiement'));
    }

    public function test_les_ventes_et_avoirs_de_la_cloture_finissent_par_leur_net_puis_les_informations_en_gris(): void
    {
        $html = Livewire::test(ViewClotureCaisse::class, ['record' => $this->journeeDUnPaiementParCarte()->getRouteKey()])->html();

        $this->assertSame([
            ['', ['Rubrique', 'Nombre', 'Total HT', 'TVA', 'Total TTC']],
            ['', ['Ventes aux particuliers (tickets)', '1', '26,92 €', '5,38 €', '32,30 €']],
            ['', ["\u{2003}dont TVA à 20 %", '', '26,92 €', '5,38 €', '32,30 €']],
            ['piliqi-ligne-nulle', ['Avoirs sur des tickets', '–', '–', '–', '–']],
            ['piliqi-total', ['Net des ventes aux particuliers (base de l\'e-reporting)', '26,92 €', '5,38 €', '32,30 €']],
            ['piliqi-information', ['Factures aux professionnels (pour information)', '–', '–', '–', '–']],
            ['piliqi-information', ['Avoirs aux professionnels (pour information)', '–', '–', '–', '–']],
        ], self::lignesDuTableau($html, 'Rubrique'));
    }

    public function test_un_taux_de_zero_reste_ecrit_0_pour_cent_sur_la_fiche_d_un_document(): void
    {
        $ticket = $this->ticketDUnLivreAZeroEtDUnSavonAVingt();

        $html = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->html();

        $this->assertSame([
            ['', ['Description', 'Quantité', 'PU HT', 'PU TTC', 'Taux TVA', 'Total HT', 'TVA', 'Total TTC']],
            ['', ['Livre', '1', '10,00 €', '10,00 €', '0 %', "10,00\u{00A0}€", '–', "10,00\u{00A0}€"]],
            ['', ['Savon', '1', '8,32 €', '9,99 €', '20 %', "8,32\u{00A0}€", "1,67\u{00A0}€", "9,99\u{00A0}€"]],
        ], self::lignesDuTableau($html, 'Description'));
        $this->assertSame([
            ['', ['Taux', 'Base HT', 'TVA', 'Total TTC']],
            ['', ['20 %', "8,32\u{00A0}€", "1,67\u{00A0}€", "9,99\u{00A0}€"]],
            ['', ['0 %', "10,00\u{00A0}€", '–', "10,00\u{00A0}€"]],
        ], self::lignesDuTableau($html, 'Taux'));
    }

    public function test_un_taux_de_zero_reste_ecrit_0_pour_cent_dans_la_tva_par_taux_de_la_cloture(): void
    {
        $ticket = $this->ticketDUnLivreAZeroEtDUnSavonAVingt();
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $ticket->magasin, Calendrier::deParis()->aujourdhui());

        $html = Livewire::test(ViewClotureCaisse::class, ['record' => $cloture->getRouteKey()])->html();

        $this->assertSame([
            ['', ['Taux', 'Base HT', 'TVA', 'Total TTC']],
            ['', ['20 %', "8,32\u{00A0}€", "1,67\u{00A0}€", "9,99\u{00A0}€"]],
            ['', ['0 %', "10,00\u{00A0}€", '–', "10,00\u{00A0}€"]],
        ], self::lignesDuTableau($html, 'Taux'));
    }

    /**
     * Un ticket payé en espèces à la caisse par le responsable connecté :
     * un livre à 10,00 € TTC à 0 % et un savon à 9,99 € TTC à 20 %.
     */
    private function ticketDUnLivreAZeroEtDUnSavonAVingt(): Facture
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);
        $livre = Produit::factory()->create(['nom' => 'Livre', 'prix_vente_ttc' => '10.00', 'taux_tva' => 0, 'suivi_stock' => false]);
        $savon = Produit::factory()->create(['nom' => 'Savon', 'prix_vente_ttc' => '9.99', 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $livre->id, 'quantite' => 1],
            ['produit_id' => $savon->id, 'quantite' => 1],
        ]);
    }

    /**
     * La clôture d'une journée d'un seul ticket de 32,30 € TTC à 20 %,
     * payé par carte à la caisse par le responsable connecté.
     */
    private function journeeDUnPaiementParCarte(): ClotureCaisse
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => '32.30', 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::CarteBancaire);

        return app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui());
    }

    /**
     * Les lignes du tableau dont la première colonne porte ce titre : pour
     * chacune, sa classe et le texte visible de chaque cellule, espaces
     * resserrés (le libellé de chaque valeur, que seul un lecteur d'écran
     * lit, n'y est pas : le titre de sa colonne le dit à l'œil).
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private static function lignesDuTableau(string $html, string $premierTitre): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $xpath = new DOMXPath($document);
        $tableaux = $xpath->query('//div[contains(@class, "piliqi-tableau")]/table[thead/tr/th[1][normalize-space(.) = "'.$premierTitre.'"]]');
        self::assertNotFalse($tableaux);
        self::assertSame(1, $tableaux->length);

        foreach (iterator_to_array($xpath->query('.//*[contains(concat(" ", @class, " "), " sr-only ")]', $tableaux->item(0)) ?: []) as $libelleCache) {
            $libelleCache->parentNode?->removeChild($libelleCache);
        }

        $lignes = [];

        foreach ($xpath->query('.//tr', $tableaux->item(0)) ?: [] as $ligne) {
            self::assertInstanceOf(DOMElement::class, $ligne);
            $cellules = [];

            foreach ($xpath->query('./th|./td', $ligne) ?: [] as $cellule) {
                $cellules[] = trim((string) preg_replace('/[ \t\n\r]+/', ' ', $cellule->textContent));
            }

            $lignes[] = [$ligne->getAttribute('class'), $cellules];
        }

        return $lignes;
    }
}
