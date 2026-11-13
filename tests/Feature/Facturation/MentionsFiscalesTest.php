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
 * explication et se termine par la phrase des prix unitaires de son
 * document : tels qu'ils sont enregistrés sur une facture ou un avoir à
 * un professionnel et sur une vente sans TVA française, qui montrent le
 * PU HT (décision de l'éditeur du 09/11/2026), arrondis au centime sur un
 * ticket et un avoir sur ticket (décision de l'éditeur du 25/10/2026) ;
 * une vente en franchise en base n'en a pas.
 */
class MentionsFiscalesTest extends TestCase
{
    use RefreshDatabase;

    private const PRIX_UNITAIRES_AU_CENTIME = 'Les prix unitaires sont affichés arrondis au centime et les totaux calculés sans arrondi intermédiaire : la quantité × le prix unitaire affiché peut donc différer du total de la ligne.';

    private const PU_HT_TEL_QU_ENREGISTRE = 'Les prix unitaires HT sont affichés tels qu\'ils sont enregistrés et les totaux calculés sans arrondi intermédiaire : la quantité × le PU HT, remise déduite, retombe sur le total HT de la ligne à moins de deux centimes près.';

    private const PU_HT_ECART_POSSIBLE = 'Les prix unitaires HT sont affichés tels qu\'ils sont enregistrés et les totaux calculés sans arrondi intermédiaire : la quantité × le PU HT, remise déduite, peut s\'écarter de quelques centimes du total HT de la ligne.';

    private const CALCUL_VENTE = 'Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence. ';

    public function test_chaque_variante_de_la_note_de_calcul_finit_par_la_phrase_des_prix_unitaires_de_son_document(): void
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

        $vente = 'Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence. ';
        $retour = 'Montants calculés depuis les prix TTC : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité (HT = TTC ÷ (1 + taux), arrondi une fois au centime ; TVA = la différence), sans dépasser le HT ni la TVA de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime. ';
        $this->assertSame([
            $vente.self::PRIX_UNITAIRES_AU_CENTIME,
            $retour.self::PRIX_UNITAIRES_AU_CENTIME,
            $vente.self::PU_HT_TEL_QU_ENREGISTRE,
            $retour.self::PU_HT_TEL_QU_ENREGISTRE,
            'Vente sans TVA française : chaque produit, et chaque service à son prix de référence, se vend à son prix catalogue TTC sans la TVA française de son taux (HT de chaque taux = TTC du catalogue ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes) ; un autre service, à son prix sans TVA. '.self::PU_HT_TEL_QU_ENREGISTRE,
            'Vente sans TVA française : chaque retour rend, pour tous les articles d\'une ligne rendus jusqu\'ici, le calcul de la vente sur cette quantité, sans dépasser le HT de la ligne, déduction faite des avoirs déjà émis sur la même ligne ; le dernier retour solde la ligne au centime. '.self::PU_HT_TEL_QU_ENREGISTRE,
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
     * T105 : une facture de 100 000 articles à 1,00 € TTC à 20 % fait
     * 83 333,33 € HT pour un PU HT de 0,833333 €, dont 100 000 fois font
     * 83 333,30 €, à 0,03 € de la ligne : sa note dit qu'un petit écart est
     * possible ; une facture ordinaire, dont chaque ligne retombe à moins
     * de deux centimes, garde la première phrase.
     */
    public function test_la_note_ne_dit_moins_de_deux_centimes_que_si_chaque_ligne_le_verifie(): void
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
            self::CALCUL_VENTE.self::PU_HT_ECART_POSSIBLE,
            self::CALCUL_VENTE.self::PU_HT_TEL_QU_ENREGISTRE,
        ], array_map(app(MentionsFiscales::class)->calcul(...), [$grande, $ordinaire]));
    }

    /**
     * Une ligne de 3 articles à 7,00 € TTC à 20 % (17,50 € HT, PU HT
     * 5,833333 €) rendue en trois avoirs d'un article : 5,83 €, 5,84 € puis
     * 5,83 € HT, chacun à moins d'un centime de 5,833333 € ; le dernier,
     * qui solde la ligne, garde la première phrase, comme les deux autres.
     */
    public function test_le_dernier_avoir_d_une_ligne_rendue_en_trois_fois_retombe_a_moins_de_deux_centimes(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $professionnel = Client::factory()->professionnel()->create();
        $facture = $this->emettre($professionnel, []);

        $avoirs = [$this->rendreUnArticle($professionnel, $facture), $this->rendreUnArticle($professionnel, $facture), $this->rendreUnArticle($professionnel, $facture)];

        $this->assertSame(['5.83', '5.84', '5.83'], array_map(fn (Avoir $avoir): string => $avoir->lignes->sole()->montant_ht, $avoirs));
        $this->assertStringEndsWith(self::PU_HT_TEL_QU_ENREGISTRE, (string) app(MentionsFiscales::class)->calcul($avoirs[2]));
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
