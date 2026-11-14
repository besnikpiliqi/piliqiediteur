<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Facturation\Services\DerogationsDePrix;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les remises et les prix dérogatoires que des lignes de vente introduisent
 * (voir DerogationsDePrix::introduites()), sur des lignes construites comme
 * à l'enregistrement : une phrase par ligne dérogatoire, dans l'ordre des
 * lignes, et chaque ligne déjà enregistrée ne couvre qu'une ligne en tout
 * point identique.
 */
class DerogationsDePrixTest extends TestCase
{
    use RefreshDatabase;

    private ContexteDeVente $contexte;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
        $this->contexte = new ContexteDeVente(TypeClient::Particulier, false, '20.00');
    }

    public function test_seules_les_lignes_derogatoires_ont_leur_phrase_dans_l_ordre_des_lignes(): void
    {
        Produit::factory()->create(['prix_vente_ttc' => '5.00']);
        $cahier = Produit::factory()->create(['nom' => 'Cahier', 'prix_vente_ttc' => '12.00']);
        $stylo = Produit::factory()->create(['nom' => 'Stylo', 'prix_vente_ttc' => '2.50']);
        $pose = Service::factory()->auPrixDe('25.00')->create(['nom' => 'Pose']);
        $conseil = Service::factory()->create(['nom' => 'Conseil']);

        $introduites = $this->introduites([], [
            'cahier' => ['produit_id' => $cahier->id, 'quantite' => 1],
            'stylo' => ['produit_id' => $stylo->id, 'quantite' => 2, 'prix_unitaire_ttc' => '2.00'],
            'pose' => ['service_id' => $pose->id, 'quantite' => 1],
            'pose_remisee' => ['service_id' => $pose->id, 'quantite' => 1, 'remise_pourcentage' => '12.5'],
            'conseil' => ['service_id' => $conseil->id, 'quantite' => 1, 'prix_unitaire_ttc' => '40.00', 'taux_tva' => '20'],
            'pose_plus_chere' => ['service_id' => $pose->id, 'quantite' => 1, 'prix_unitaire_ttc' => '30.00'],
            'pose_a_10' => ['service_id' => $pose->id, 'quantite' => 1, 'taux_tva' => '10'],
        ]);

        $this->assertSame([
            'stylo' => 'Vendre « Stylo » à 2,00 € au lieu de 2,50 €, le prix de sa fiche, '.DerogationsDePrix::AUTORISATION.'.',
            'pose_remisee' => 'Accorder une remise de 12,5 % sur « Pose » '.DerogationsDePrix::AUTORISATION.'.',
            'conseil' => 'Le prix du service « Conseil » n\'est pas fixé : demandez à un responsable.',
            'pose_plus_chere' => 'Vendre « Pose » à 30,00 € au lieu de 25,00 €, son prix de référence, '.DerogationsDePrix::AUTORISATION.'.',
            'pose_a_10' => 'Vendre « Pose » à 10 % de TVA au lieu de 20 %, le taux de son prix de référence, '.DerogationsDePrix::AUTORISATION.'.',
        ], $introduites);
    }

    /**
     * Une ligne remisée déjà enregistrée garde son accord pour une seule
     * ligne en tout point identique : une seconde ligne pareille, ou une
     * ligne qui change d'article, de quantité, de prix, de taux ou de
     * remise, introduit une dérogation.
     */
    public function test_une_ligne_deja_accordee_ne_couvre_qu_une_ligne_en_tout_point_identique(): void
    {
        $cahier = Produit::factory()->create(['nom' => 'Cahier', 'prix_vente_ttc' => '12.00']);
        $carnet = Produit::factory()->create(['nom' => 'Carnet', 'prix_vente_ttc' => '12.00']);
        $pose = Service::factory()->auPrixDe('25.00')->create(['nom' => 'Pose']);
        $remise = ['produit_id' => $cahier->id, 'quantite' => 2, 'remise_pourcentage' => '5'];
        $poseRemisee = ['service_id' => $pose->id, 'quantite' => 1, 'remise_pourcentage' => '5'];
        $avant = $this->lignes([$remise, $poseRemisee]);

        $this->assertSame([], $this->introduites($avant, [$remise, $poseRemisee]));
        $this->assertSame([1], array_keys($this->introduites($avant, [$remise, $remise])));
        $this->assertSame([0, 1, 2, 3, 4], array_keys($this->introduites($avant, [
            [...$remise, 'quantite' => 3],
            [...$remise, 'produit_id' => $carnet->id],
            [...$remise, 'prix_unitaire_ttc' => '11.00'],
            [...$remise, 'remise_pourcentage' => '6'],
            [...$poseRemisee, 'taux_tva' => '10'],
        ])));
    }

    /**
     * @param  list<array<string, mixed>>  $lignesAvant  des lignes déjà enregistrées, telles que leurs colonnes
     * @param  array<array-key, array<string, mixed>>  $saisies
     * @return array<array-key, string>
     */
    private function introduites(array $lignesAvant, array $saisies): array
    {
        return app(DerogationsDePrix::class)->introduites($lignesAvant, $this->lignes($saisies), $this->contexte);
    }

    /**
     * Les lignes telles que l'enregistrement les construit, par clé.
     *
     * @param  array<array-key, array<string, mixed>>  $saisies
     * @return array<array-key, array<string, mixed>>
     */
    private function lignes(array $saisies): array
    {
        return app(ConstructeurLigneDocument::class)->apercu($saisies, $this->contexte)->lignes;
    }
}
