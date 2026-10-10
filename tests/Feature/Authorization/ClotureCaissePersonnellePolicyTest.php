<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Quiconque vend ou encaisse ferme sa propre caisse et consulte ses
 * relevés (voir ClotureCaissePersonnellePolicy::fermer()). Le relevé
 * « Fermer ma caisse » d'un membre n'est visible que par lui et par qui a
 * cloture_caisse.view_toutes (voir ClotureCaissePersonnellePolicy::view()).
 */
class ClotureCaissePersonnellePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_ne_voit_que_ses_propres_releves(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeurA);
        $releveDeA = ClotureCaissePersonnelle::factory()->create();
        $this->actingAs($vendeurB);
        $releveDeB = ClotureCaissePersonnelle::factory()->create();

        $this->assertTrue($vendeurB->can('view', $releveDeB));
        $this->assertFalse($vendeurB->can('view', $releveDeA));
    }

    public function test_avec_la_vue_totale_un_membre_voit_les_releves_de_toute_l_equipe(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $comptable = $this->membre($magasin, RoleMagasin::Comptable);
        $this->actingAs($vendeur);
        $releveDuVendeur = ClotureCaissePersonnelle::factory()->create();

        $this->assertTrue($comptable->can('view', $releveDuVendeur));
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    public static function autorisationsDevantSaCaisse(): array
    {
        return [
            'qui vend (factures : créer)' => [[PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE], true],
            'qui encaisse sans vendre (paiements : enregistrer)' => [[PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PAIEMENT_VIEW, PermissionRegistry::PAIEMENT_CREER], true],
            'qui ne fait que consulter' => [[PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PAIEMENT_VIEW], false],
        ];
    }

    /**
     * @param  list<string>  $autorisations
     */
    #[DataProvider('autorisationsDevantSaCaisse')]
    public function test_quiconque_vend_ou_encaisse_ferme_sa_caisse(array $autorisations, bool $tientUneCaisse): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);

        $this->assertSame(
            [$tientUneCaisse, $tientUneCaisse],
            [$membre->can('fermer', ClotureCaissePersonnelle::class), $membre->can('viewAny', ClotureCaissePersonnelle::class)],
        );
    }
}
