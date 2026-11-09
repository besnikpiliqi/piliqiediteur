<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Services\ConstructeurLigneDocument;
use App\Domain\Facturation\Services\ContexteDeVente;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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

    /**
     * Les lignes enregistrées se rangent dans leur ordre, quelles que soient
     * les clés du formulaire, avec la description et la référence de leur
     * article ; un identifiant transmis en texte se lit comme un entier ;
     * un article offert (0 €) se vend, une remise de 100 % aussi.
     */
    public function test_les_lignes_enregistrees_se_rangent_dans_leur_ordre_avec_leur_article(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['nom' => 'Cahier', 'reference' => 'CAH-1', 'prix_vente_ttc' => '12.00']);
        $service = Service::factory()->auPrixDe('30.00')->create(['nom' => 'Pose']);

        $document = app(ConstructeurLigneDocument::class)->construireToutes([
            'b7' => ['produit_id' => (string) $produit->id, 'quantite' => '2', 'remise_pourcentage' => '100'],
            'a3' => ['service_id' => (string) $service->id, 'quantite' => 1, 'prix_unitaire_ttc' => '0'],
        ], new ContexteDeVente(TypeClient::Particulier, false, 20.0));

        $this->assertSame([
            [0, 'Cahier', 'CAH-1', '0.00'],
            [1, 'Pose', null, '0.00'],
        ], array_map(fn (array $ligne): array => [$ligne['ordre'], $ligne['description'], $ligne['reference'], $ligne['montant_ttc']], array_values($document->lignes)));
        $this->assertSame(['20.00' => ['montant_ht' => '0.00', 'montant_tva' => '0.00', 'montant_ttc' => '0.00']], $document->parTaux);
    }

    /**
     * Un prix TTC négatif, une remise au-delà de 100 % et un service sans
     * prix de référence ni prix saisi sont refusés avec leur motif ; un
     * produit ou un service introuvable dans le magasin, avec son
     * identifiant.
     */
    public function test_une_ligne_impossible_est_refusee_avec_son_motif(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => '12.00']);
        $sansPrix = Service::factory()->create(['nom' => 'Conseil']);
        $contexte = new ContexteDeVente(TypeClient::Particulier, false, 20.0);
        $construire = fn (array $ligne) => app(ConstructeurLigneDocument::class)->construireToutes([$ligne], $contexte);

        $this->assertThrows(fn () => $construire(['produit_id' => $produit->id, 'quantite' => 1, 'prix_unitaire_ttc' => '-0.01']), InvalidArgumentException::class, 'Le PU TTC d\'une ligne ne peut pas être négatif.');
        $this->assertThrows(fn () => $construire(['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => '100.01']), InvalidArgumentException::class, 'La remise doit être comprise entre 0 et 100 %.');
        $this->assertThrows(fn () => $construire(['service_id' => $sansPrix->id, 'quantite' => 1]), InvalidArgumentException::class, 'Le prix du service « Conseil » n\'est pas fixé : demandez à un responsable.');
        $this->assertThrows(fn () => $construire(['produit_id' => $produit->id + 1000, 'quantite' => 1]), fn (ModelNotFoundException $introuvable): bool => $introuvable->getModel() === Produit::class && $introuvable->getIds() === [$produit->id + 1000]);
        $this->assertThrows(fn () => $construire(['service_id' => $sansPrix->id + 1000, 'quantite' => 1]), fn (ModelNotFoundException $introuvable): bool => $introuvable->getModel() === Service::class && $introuvable->getIds() === [$sansPrix->id + 1000]);
    }

    /**
     * L'aperçu écarte une saisie qui n'est pas une ligne, et une ligne dont
     * les montants ne se calculent pas, même seule (une remise au-delà de
     * 100 %), sans perdre les autres, rangées dans leur ordre.
     */
    public function test_l_apercu_ecarte_ce_qui_n_est_pas_une_ligne_et_garde_les_autres_dans_leur_ordre(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $produit = Produit::factory()->create(['prix_vente_ttc' => '12.00']);

        $apercu = app(ConstructeurLigneDocument::class)->apercu([
            'texte' => 'pas une ligne',
            'premiere' => ['produit_id' => $produit->id, 'quantite' => 1],
            'remise_impossible' => ['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => '150'],
            'seconde' => ['produit_id' => $produit->id, 'quantite' => 2],
        ], new ContexteDeVente(TypeClient::Particulier, false, 20.0));

        $this->assertSame(['premiere' => 0, 'seconde' => 2], array_map(fn (array $ligne): int => $ligne['ordre'], $apercu->lignes));
        $this->assertSame('36.00', $apercu->totaux['total_ttc']);
    }
}
