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
 * variantes, vente ou retour, avec ou sans TVA française, garde son
 * explication et se termine par la phrase de l'arrondi des prix unitaires
 * (décision de l'éditeur du 25/10/2026) ; une vente en franchise en base
 * n'en a pas.
 */
class MentionsFiscalesTest extends TestCase
{
    use RefreshDatabase;

    private const PRIX_UNITAIRES_AU_CENTIME = 'Les prix unitaires sont affichés arrondis au centime et les totaux calculés sans arrondi intermédiaire : la quantité × le prix unitaire affiché peut donc différer du total de la ligne.';

    public function test_chaque_variante_de_la_note_de_calcul_finit_par_la_phrase_de_l_arrondi_des_prix_unitaires(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $vente = $this->emettre($client, []);
        $export = $this->emettre($client, [
            'nature_operation' => NatureOperation::ExportHorsUe,
            'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]);

        $notes = array_map(app(MentionsFiscales::class)->calcul(...), [$vente, $this->rendreUnArticle($client, $vente), $export, $this->rendreUnArticle($client, $export)]);

        $this->assertSame([
            'Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence. '.self::PRIX_UNITAIRES_AU_CENTIME,
            'Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité (HT = TTC ÷ (1 + taux), arrondi une fois au centime ; TVA = la différence), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime. '.self::PRIX_UNITAIRES_AU_CENTIME,
            'Vente sans TVA française : chaque produit, et chaque service à son prix de référence, se vend à son prix catalogue TTC sans la TVA française de son taux (HT de chaque taux = TTC du catalogue ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes) ; un autre service, à son prix sans TVA. '.self::PRIX_UNITAIRES_AU_CENTIME,
            'Vente sans TVA française : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité, sans dépasser le HT de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime. '.self::PRIX_UNITAIRES_AU_CENTIME,
        ], $notes);
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
     * Un ticket de 3 articles à 7,00 € TTC à 20 %.
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
