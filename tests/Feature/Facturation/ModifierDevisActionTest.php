<?php

namespace Tests\Feature\Facturation;

use App\Console\Commands\ExpirerDevis;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ModifierDevisActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'MABOUT']));
    }

    /**
     * Comme celles d'une facture, les conditions de paiement d'un devis
     * s'impriment dans le pied de chaque page : l'action refuse elle-même
     * plus de 500 caractères, à la création comme à la modification, et le
     * devis garde alors les siennes.
     */
    public function test_des_conditions_de_paiement_de_plus_de_500_caracteres_sont_refusees_a_la_creation_comme_a_la_modification(): void
    {
        $client = Client::factory()->create();
        $lignes = [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20])->id, 'quantite' => 1]];
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, $lignes, ['conditions_paiement' => str_repeat('a', 500)]);
        $refus = 'Les conditions de paiement comptent 500 caractères au plus : elles s\'impriment dans le pied de chaque page du document.';

        $this->assertThrows(fn () => app(CreerDevisAction::class)->handle(self::auteur(), $client, $lignes, ['conditions_paiement' => str_repeat('a', 501)]), InvalidArgumentException::class, $refus);
        $this->assertThrows(fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, $lignes, ['conditions_paiement' => str_repeat('a', 501)]), InvalidArgumentException::class, $refus);

        $this->assertSame([str_repeat('a', 500)], Devis::query()->pluck('conditions_paiement')->all());
    }

    public function test_un_devis_brouillon_est_modifiable(): void
    {
        $client = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $devis = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $autreClient, [
            ['produit_id' => $produit->id, 'quantite' => 3],
        ]);

        $this->assertSame($autreClient->id, $devis->client_id);
        $this->assertSame('150.00', $devis->total_ht);
        $this->assertSame(1, $devis->lignes()->count());
    }

    /**
     * Le client ne s'est engagé que sur la version qu'il a reçue (B15) :
     * modifié, le devis DEV-MABOUT-2026-000003 repasse en brouillon, garde
     * son numéro et la date à laquelle il a été remis à son client, ici
     * sans e-mail ; renvoyé, il repart sous le même numéro, sans en
     * consommer un autre, à sa nouvelle date : le devis suivant reçoit
     * DEV-MABOUT-2026-000004.
     */
    public function test_un_devis_envoye_modifie_repasse_en_brouillon_et_repart_sous_son_numero(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $this->envoyerUnDevis($client, $produit);
        $this->envoyerUnDevis($client, $produit);
        $devis = $this->envoyerUnDevis($client, $produit);
        $this->travelTo(self::aParis('2026-10-07 15:00:00'));

        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, [['produit_id' => $produit->id, 'quantite' => 2]]);

        $this->assertSame(['DEV-MABOUT-2026-000003', StatutDevis::Brouillon, self::aParis('2026-10-05 10:00')->getTimestamp()], [$modifie->numero, $modifie->statut, $modifie->date_emission->getTimestamp()]);
        $this->assertSame(['100.00', '20.00', '120.00'], [$modifie->total_ht, $modifie->total_tva, $modifie->total_ttc]);

        $renvoye = app(EmettreDevisAction::class)->handle(self::auteur(), $modifie);
        $suivant = $this->envoyerUnDevis($client, $produit);

        $this->assertSame(['DEV-MABOUT-2026-000003', StatutDevis::Envoye, self::aParis('2026-10-07 15:00')->getTimestamp()], [$renvoye->numero, $renvoye->statut, $renvoye->date_emission->getTimestamp()]);
        $this->assertSame('DEV-MABOUT-2026-000004', $suivant->numero);
    }

    /**
     * Un devis accepté se modifie comme un devis envoyé (K8) : il repasse
     * en brouillon, son acceptation effacée, et ne se convertit plus en
     * facture tant que son client n'a pas accepté la nouvelle version.
     */
    public function test_un_devis_accepte_modifie_repasse_en_brouillon_et_perd_son_acceptation(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = app(AccepterDevisAction::class)->handle(self::auteur(), $this->envoyerUnDevis($client, $produit));

        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, [['produit_id' => $produit->id, 'quantite' => 2]]);

        $this->assertSame([StatutDevis::Brouillon, $devis->numero, '120.00'], [$modifie->statut, $modifie->numero, $modifie->total_ttc]);
        $this->assertThrows(
            fn () => app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $modifie),
            LogicException::class,
            'Seul un devis accepté peut être converti en facture (statut actuel : [brouillon]).',
        );
    }

    /**
     * Refusé ou expiré, un devis reste tel quel : une nouvelle proposition
     * passe par un nouveau devis.
     */
    public function test_un_devis_refuse_n_est_plus_modifiable(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = app(RefuserDevisAction::class)->handle(self::auteur(), $this->envoyerUnDevis($client, $produit));

        $this->assertThrows(
            fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, [['produit_id' => $produit->id, 'quantite' => 4]]),
            LogicException::class,
            'Ce devis ne peut plus être modifié : un devis refusé reste tel quel, établissez-en un nouveau.',
        );

        $devis->refresh();
        $this->assertSame([StatutDevis::Refuse, '60.00', 1], [$devis->statut, $devis->total_ttc, $devis->lignes->sole()->quantite]);
    }

    public function test_un_devis_expire_n_est_plus_modifiable(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerUnDevis($client, $produit, '2026-10-08');
        $this->travelTo(self::aParis('2026-10-09 00:30:00'));
        $this->artisan(ExpirerDevis::class)->assertSuccessful();
        app(TenantContext::class)->set($devis->magasin);

        $this->assertThrows(
            fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, [['produit_id' => $produit->id, 'quantite' => 4]], ['date_validite' => '2026-11-30']),
            LogicException::class,
            'Ce devis ne peut plus être modifié : un devis expiré reste tel quel, établissez-en un nouveau.',
        );

        $devis->refresh();
        $this->assertSame([StatutDevis::Expire, '2026-10-08', '60.00'], [$devis->statut, $devis->date_validite, $devis->total_ttc]);
    }

    /**
     * Le lendemain du dernier jour de sa validité, un devis envoyé est
     * expiré, même avant que le passage de la nuit l'ait écrit.
     */
    public function test_un_devis_envoye_a_la_validite_depassee_n_est_plus_modifiable_avant_meme_son_expiration(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = $this->envoyerUnDevis($client, $produit, '2026-10-08');
        $this->travelTo(self::aParis('2026-10-09 00:10:00'));

        $this->assertThrows(
            fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, [['produit_id' => $produit->id, 'quantite' => 4]], ['date_validite' => '2026-11-30']),
            LogicException::class,
            'Ce devis ne peut plus être modifié : un devis expiré reste tel quel, établissez-en un nouveau.',
        );

        $devis->refresh();
        $this->assertSame([StatutDevis::Envoye, '2026-10-08', '60.00'], [$devis->statut, $devis->date_validite, $devis->total_ttc]);
    }

    public function test_un_devis_deja_converti_en_facture_nest_plus_modifiable(): void
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);
        $devis = app(AccepterDevisAction::class)->handle(self::auteur(), $this->envoyerUnDevis($client, $produit));
        app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis);

        $this->assertThrows(
            fn () => app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, [['produit_id' => $produit->id, 'quantite' => 9]]),
            LogicException::class,
            'Ce devis ne peut plus être modifié : il a déjà été transformé en facture.',
        );

        $devis->refresh();
        $this->assertSame([StatutDevis::Accepte, '60.00'], [$devis->statut, $devis->total_ttc]);
    }

    private function envoyerUnDevis(Client $client, Produit $produit, ?string $dateValidite = null): Devis
    {
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => $dateValidite]);

        return app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
    }
}
