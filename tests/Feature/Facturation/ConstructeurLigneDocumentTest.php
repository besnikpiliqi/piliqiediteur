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
 * ConstructeurLigneDocument::apercu()) : calculé comme à l'enregistrement,
 * par clé de ligne, sans jamais compter une ligne incomplète.
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

        $this->assertSame(['ecran'], array_keys($apercu));
        $this->assertSame(['20.00', '30.00', '6.00', '36.00'], [
            number_format($apercu['ecran']['taux_tva'], 2, '.', ''),
            number_format($apercu['ecran']['montant_ht'], 2, '.', ''),
            number_format($apercu['ecran']['montant_tva'], 2, '.', ''),
            number_format($apercu['ecran']['montant_ttc'], 2, '.', ''),
        ]);
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

        $this->assertSame([], $apercu);
    }
}
