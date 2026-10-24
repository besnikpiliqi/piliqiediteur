<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'aperçu des lignes en cours de saisie (voir
 * ConstructeurLigneDocument::apercu()) : le document calculé comme à
 * l'enregistrement, ses lignes par clé, sans jamais compter une ligne
 * incomplète.
 */
class ConstructeurLigneDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_l_apercu_calcule_les_lignes_completes_par_cle_et_ignore_les_autres(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $service = Service::factory()->create();

        $apercu = app(ConstructeurLigneDocument::class)->apercu([
            'ecran' => ['produit_id' => $produit->id, 'taux_tva' => '5.5', 'quantite' => '3'],
            'vide' => ['produit_id' => null, 'quantite' => 1],
            'prix_a_saisir' => ['service_id' => $service->id, 'prix_unitaire_ttc' => '', 'quantite' => 1],
            'quantite_a_virgule' => ['produit_id' => $produit->id, 'quantite' => '1.5'],
        ], new ContexteDeVente(TypeClient::Particulier, false, 20.0));

        $this->assertSame(['ecran'], array_keys($apercu->lignes));
        $this->assertSame(['20.00', '30.00', '6.00', '36.00'], [
            $apercu->lignes['ecran']['taux_tva'],
            $apercu->lignes['ecran']['montant_ht'],
            $apercu->lignes['ecran']['montant_tva'],
            $apercu->lignes['ecran']['montant_ttc'],
        ]);
        $this->assertSame(['total_ht' => '30.00', 'total_tva' => '6.00', 'total_ttc' => '36.00'], $apercu->totaux);
    }

    /**
     * Un produit d'un autre magasin, glissé dans l'état du formulaire, n'est
     * ni lu ni compté.
     */
    public function test_l_apercu_ignore_un_produit_d_un_autre_magasin(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produitAutreMagasin = Produit::factory()->create(['prix_vente_ttc' => 12]);
        app(TenantContext::class)->set(Magasin::factory()->create());

        $apercu = app(ConstructeurLigneDocument::class)->apercu([
            ['produit_id' => $produitAutreMagasin->id, 'quantite' => 1],
        ], new ContexteDeVente(TypeClient::Particulier, false, 20.0));

        $this->assertSame([], $apercu->lignes);
        $this->assertSame('0.00', $apercu->totaux['total_ttc']);
    }

    /**
     * Le HT du taux se répartit entre les seules lignes complètes, dans
     * leur ordre : trois lignes de 0,10 € TTC (0,30 ÷ 1,2 = 0,25 € HT)
     * prennent 0,08, 0,09 et 0,08 € HT, la ligne encore vide entre elles ne
     * compte pas.
     */
    public function test_l_apercu_d_une_vente_professionnelle_repartit_le_ht_entre_les_lignes_completes(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => '0.10', 'taux_tva' => 20]);

        $apercu = app(ConstructeurLigneDocument::class)->apercu([
            'premiere' => ['produit_id' => $produit->id, 'quantite' => 1],
            'vide' => ['produit_id' => null, 'quantite' => 1],
            'deuxieme' => ['produit_id' => $produit->id, 'quantite' => 1],
            'troisieme' => ['produit_id' => $produit->id, 'quantite' => 1],
        ], new ContexteDeVente(TypeClient::Professionnel, false, 20.0));

        $this->assertSame(
            ['premiere' => ['0.08', '0.02', '0.10'], 'deuxieme' => ['0.09', '0.01', '0.10'], 'troisieme' => ['0.08', '0.02', '0.10']],
            array_map(fn (array $ligne): array => [$ligne['montant_ht'], $ligne['montant_tva'], $ligne['montant_ttc']], $apercu->lignes),
        );
    }

    /**
     * Des montants si absurdes que leur total ne se calcule plus (vingt
     * lignes de 99 x 9 999 999 999,99 € à 100 %) ne donnent aucun aperçu,
     * au lieu de faire planter la page.
     */
    public function test_l_apercu_d_une_vente_professionnelle_aux_montants_absurdes_est_vide(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $service = Service::factory()->create();

        $apercu = app(ConstructeurLigneDocument::class)->apercu(
            array_fill(0, 20, ['service_id' => $service->id, 'prix_unitaire_ttc' => '9999999999.99', 'taux_tva' => '100', 'quantite' => 99]),
            new ContexteDeVente(TypeClient::Professionnel, false, 20.0),
        );

        $this->assertSame([], $apercu->lignes);
        $this->assertSame('0.00', $apercu->totaux['total_ttc']);
    }
}
