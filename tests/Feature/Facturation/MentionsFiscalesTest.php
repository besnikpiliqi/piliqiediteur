<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\MentionsFiscales;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La note de calcul que les PDF impriment sous les lignes d'une vente et
 * que sa fiche affiche (voir MentionsFiscales::calcul()) : chacune de ses
 * variantes, vente ou retour, avec ou sans TVA française, dit comment
 * chaque ligne se calcule depuis son prix TTC (règle (b), décision de
 * l'éditeur du 14/11/2026), un centime ajusté rendant à chaque taux la TVA
 * de son TTC total, sans jamais promettre qu'elle égale sa base HT x le
 * taux (décision de Claude, D76) ; partout où le document montre le PU HT
 * -- la fiche de toute vente, et le PDF d'une facture ou d'un avoir à un
 * professionnel et d'une vente sans TVA française (modèle M6-b) --, elle
 * ajoute la phrase du PU HT d'un article, prix TTC moins sa TVA arrondie au
 * centime, et le PDF celle du PU HT exact écrit sous chaque article ; le
 * PDF d'un ticket et d'un avoir sur ticket, qui ne montre que des prix TTC
 * (modèle P1), n'imprime que l'explication du calcul ; une vente en
 * franchise en base n'en a pas.
 */
class MentionsFiscalesTest extends TestCase
{
    use RefreshDatabase;

    private const PU_HT_D_UN_ARTICLE = 'Le PU HT affiché est celui d\'un article : prix TTC moins sa TVA arrondie au centime (9,99 € TTC à 20 % : 8,32 € HT). La quantité × ce prix peut différer de quelques centimes du total HT de la ligne, calculé sur toute la quantité.';

    private const PU_HT_EXACT = 'Le PU HT exact, écrit sous chaque article, est celui de la facture électronique : la quantité × ce prix retombe sur le total HT de la ligne à quelques centimes près.';

    private const CALCUL_VENTE = 'Montants calculés depuis les prix TTC : pour chaque ligne, la TVA est le TTC × taux ÷ (1 + taux), arrondie une fois au centime, et le HT est le TTC moins cette TVA ; un centime peut être ajusté sur une ligne pour que la TVA de chaque taux soit celle de son TTC total, calculée de même.';

    private const CALCUL_RETOUR = 'Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur leur TTC (TVA = TTC × taux ÷ (1 + taux), arrondie une fois au centime ; HT = TTC moins cette TVA), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime ; un centime peut être ajusté sur une ligne rendue en partie pour rapprocher la TVA de chaque taux de celle de son TTC total, calculée de même.';

    public function test_chaque_variante_de_la_note_de_calcul_explique_son_calcul_et_le_pu_ht_du_document_qui_le_montre(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $particulier = Client::factory()->create();
        $professionnel = Client::factory()->professionnel()->create();
        $ticket = $this->emettre($particulier, []);
        $facture = $this->emettre($professionnel, []);
        $export = $this->emettre($particulier, [
            'nature_operation' => NatureOperation::ExportHorsUe,
            'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]);

        $notes = array_map(app(MentionsFiscales::class)->calcul(...), [
            $ticket, $this->rendreUnArticle($particulier, $ticket),
            $facture, $this->rendreUnArticle($professionnel, $facture),
            $export, $this->rendreUnArticle($particulier, $export),
        ]);

        $this->assertSame([
            self::CALCUL_VENTE.' '.self::PU_HT_D_UN_ARTICLE,
            self::CALCUL_RETOUR.' '.self::PU_HT_D_UN_ARTICLE,
            self::CALCUL_VENTE.' '.self::PU_HT_D_UN_ARTICLE,
            self::CALCUL_RETOUR.' '.self::PU_HT_D_UN_ARTICLE,
            'Vente sans TVA française : chaque produit, et chaque service à son prix de référence, se vend à son prix catalogue TTC moins la TVA française de son taux (pour chaque ligne, cette TVA est le TTC × taux ÷ (1 + taux), arrondie une fois au centime ; un centime peut être ajusté sur une ligne pour que, pour chaque taux, elle soit celle du TTC total du taux, calculée de même) ; un autre service, à son prix sans TVA. '.self::PU_HT_D_UN_ARTICLE,
            'Vente sans TVA française : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité, sans dépasser le HT de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime. '.self::PU_HT_D_UN_ARTICLE,
        ], $notes);
        $this->assertSame(self::CALCUL_VENTE.' '.self::PU_HT_D_UN_ARTICLE.' '.self::PU_HT_EXACT, app(MentionsFiscales::class)->calcul($facture, surLePdf: true));
        $this->assertSame(self::CALCUL_VENTE, app(MentionsFiscales::class)->calcul($ticket, surLePdf: true));
    }

    public function test_une_vente_en_franchise_en_base_n_a_pas_de_note_de_calcul(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['regime_tva' => RegimeTva::FranchiseEnBase])->create());
        $client = Client::factory()->create();
        $vente = $this->emettre($client, []);

        $notes = array_map(app(MentionsFiscales::class)->calcul(...), [$vente, $this->rendreUnArticle($client, $vente)]);

        $this->assertSame([null, null], $notes);
    }

    /**
     * Une seule phrase pour tout document qui montre le PU HT, quel que
     * soit le nombre d'articles de ses lignes (décision de l'éditeur du
     * 14/11/2026, qui remplace T105) : une facture de 100 000 articles à
     * 1,00 € TTC à 20 % (83 333,33 € HT, PU HT enregistré 0,833333 €) et
     * une facture ordinaire de 3 articles ont la même note.
     */
    public function test_la_note_d_un_document_qui_montre_le_pu_ht_est_la_meme_quel_que_soit_le_nombre_d_articles(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $professionnel = Client::factory()->professionnel()->create();
        $grande = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $professionnel, [
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => '7.00', 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 3],
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => '1.00', 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 100000],
        ]));
        $ordinaire = $this->emettre($professionnel, []);

        $this->assertSame(['0.833333', '83333.33'], [$grande->lignes->last()->prix_unitaire_ht, $grande->lignes->last()->montant_ht]);
        $this->assertSame([
            self::CALCUL_VENTE.' '.self::PU_HT_D_UN_ARTICLE,
            self::CALCUL_VENTE.' '.self::PU_HT_D_UN_ARTICLE,
        ], array_map(app(MentionsFiscales::class)->calcul(...), [$grande, $ordinaire]));
    }

    /**
     * Une ligne de 3 articles à 7,00 € TTC à 20 % (3,50 € de TVA, 17,50 €
     * HT, PU HT 5,833333 €) rendue en trois avoirs d'un article (règle (b)
     * et D76, décision de l'éditeur du 14/11/2026) : le premier rend 7,00 x
     * 20 ÷ 120 = 1,166... -> 1,17 € de TVA, 5,83 € HT ; le deuxième, 14,00 €
     * rendus en tout (2,33 € de TVA), rendrait 1,16 €, que l'avoir ramène à
     * la TVA de ses 7,00 €, 1,17 €, sur cette ligne rendue en partie :
     * 5,83 € HT ; le dernier solde la ligne, 5,84 € HT et 1,16 €. Chacun
     * garde la phrase du PU HT d'un article.
     */
    public function test_chaque_avoir_d_une_ligne_rendue_en_trois_fois_garde_la_note_du_pu_ht_d_un_article(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $professionnel = Client::factory()->professionnel()->create();
        $facture = $this->emettre($professionnel, []);

        $avoirs = [$this->rendreUnArticle($professionnel, $facture), $this->rendreUnArticle($professionnel, $facture), $this->rendreUnArticle($professionnel, $facture)];

        $this->assertSame(['5.83', '5.83', '5.84'], array_map(fn (Avoir $avoir): string => $avoir->lignes->sole()->montant_ht, $avoirs));
        foreach ($avoirs as $avoir) {
            $this->assertStringEndsWith(self::PU_HT_D_UN_ARTICLE, (string) app(MentionsFiscales::class)->calcul($avoir));
        }
    }

    /**
     * Une vente de 3 articles à 7,00 € TTC à 20 % : un ticket, ou la
     * facture d'un client professionnel.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function emettre(Client $client, array $attributs): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => '7.00', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ], $attributs));
    }

    private function rendreUnArticle(Client $client, Facture $vente): Avoir
    {
        return app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $vente->lignes->sole()->id, 'quantite' => 1],
        ]);
    }
}
