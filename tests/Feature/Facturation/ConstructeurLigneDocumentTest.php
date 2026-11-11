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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Un identifiant que seule une requête forgée envoie à la place d'un
     * choix (un booléen, un nombre décimal) désigne la fiche que PHP lit
     * sous cette clé, comme la cherche Collection::get() : vrai et « 1 »
     * la fiche 1, 12.0 et 12.5 la fiche 12, que l'autre ligne fait charger.
     *
     * @return array<string, array{string, mixed, int}>
     */
    public static function identifiantsLusCommeUneCle(): array
    {
        return [
            'produit vrai' => ['produit_id', true, 1],
            'produit « 1 »' => ['produit_id', '1', 1],
            'produit 12.0' => ['produit_id', 12.0, 12],
            'produit 12.5' => ['produit_id', 12.5, 12],
            'service vrai' => ['service_id', true, 1],
            'service « 1 »' => ['service_id', '1', 1],
            'service 12.0' => ['service_id', 12.0, 12],
            'service 12.5' => ['service_id', 12.5, 12],
        ];
    }

    /**
     * L'aperçu compte cette ligne avec sa fiche, et l'enregistrement la
     * prend, sans autre erreur.
     */
    #[DataProvider('identifiantsLusCommeUneCle')]
    public function test_la_ligne_d_un_identifiant_booleen_ou_decimal_prend_la_fiche_que_php_lit_sous_cette_cle(string $champ, mixed $identifiant, int $cle): void
    {
        $this->magasinAuxFichesUnEtDouze();
        $lignes = ['choisie' => [$champ => 12, 'quantite' => 1], 'forgee' => [$champ => $identifiant, 'quantite' => 1]];
        $contexte = new ContexteDeVente(TypeClient::Particulier, false, 20.0);

        $apercu = app(ConstructeurLigneDocument::class)->apercu($lignes, $contexte);
        $document = app(ConstructeurLigneDocument::class)->construireToutes($lignes, $contexte);

        $this->assertSame(['choisie' => 12, 'forgee' => $cle], array_map(fn (array $ligne): int => $ligne[$champ], $apercu->lignes));
        $this->assertSame([12, $cle], array_column($document->lignes, $champ));
    }

    /**
     * Sans fiche chargée sous la clé que PHP en lit (faux se lit 0 ; 12.5 se
     * lit 12, que seul un identifiant entier, ou un texte qui en écrit un,
     * fait charger), la ligne est introuvable.
     *
     * @return array<string, array{string, class-string, mixed, int}>
     */
    public static function identifiantsSansFiche(): array
    {
        return [
            'produit faux' => ['produit_id', Produit::class, false, 0],
            'produit 12.5' => ['produit_id', Produit::class, 12.5, 12],
            'service faux' => ['service_id', Service::class, false, 0],
            'service 12.5' => ['service_id', Service::class, 12.5, 12],
        ];
    }

    /**
     * L'aperçu ignore cette ligne et garde les autres ; l'enregistrement la
     * refuse comme une fiche introuvable, sous cette clé.
     *
     * @param  class-string  $modele
     */
    #[DataProvider('identifiantsSansFiche')]
    public function test_la_ligne_d_un_identifiant_booleen_ou_decimal_sans_fiche_sous_sa_cle_est_ignoree_par_l_apercu_et_refusee_a_l_enregistrement(string $champ, string $modele, mixed $identifiant, int $cle): void
    {
        $this->magasinAuxFichesUnEtDouze();
        $forgee = [$champ => $identifiant, 'quantite' => 1];
        $contexte = new ContexteDeVente(TypeClient::Particulier, false, 20.0);

        $apercu = app(ConstructeurLigneDocument::class)->apercu(['choisie' => [$champ => 1, 'quantite' => 1], 'forgee' => $forgee], $contexte);

        $this->assertSame(['choisie'], array_keys($apercu->lignes));
        $this->assertThrows(fn () => app(ConstructeurLigneDocument::class)->construireToutes([$forgee], $contexte), fn (ModelNotFoundException $introuvable): bool => $introuvable->getModel() === $modele && $introuvable->getIds() === [$cle]);
    }

    /**
     * Le magasin actif, avec ses produits 1 et 12 et ses services 1 et 12,
     * chacun à son prix : les fiches que désignent les clés 1 et 12.
     */
    private function magasinAuxFichesUnEtDouze(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());

        foreach ([1 => '10.00', 12 => '12.00'] as $id => $prix) {
            Produit::factory()->create(['id' => $id, 'prix_vente_ttc' => $prix, 'taux_tva' => 20]);
            Service::factory()->auPrixDe($prix)->create(['id' => $id]);
        }
    }
}
