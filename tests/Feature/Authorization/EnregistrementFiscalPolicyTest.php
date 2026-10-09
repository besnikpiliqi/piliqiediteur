<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La caisse sécurisée se consulte avec caisse_securisee.consulter, dans le
 * magasin actif seulement ; rien ne s'y crée, ne s'y modifie ni ne s'y
 * supprime (voir EnregistrementFiscalPolicy).
 */
class EnregistrementFiscalPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function evenement(): EnregistrementFiscal
    {
        return DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
    }

    private function vendeur(Magasin $magasin): User
    {
        return app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
    }

    public function test_la_permission_ouvre_la_consultation_quel_que_soit_le_role(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->vendeur($magasin);
        $evenement = $this->evenement();
        $this->assertFalse($vendeur->can('viewAny', EnregistrementFiscal::class));
        $this->assertFalse($vendeur->can('view', $evenement));

        app(MettreAJourMembreMagasinAction::class)->handle(
            $magasin->membres()->where('user_id', $vendeur->getKey())->firstOrFail(),
            RoleMagasin::Vendeur,
            StatutMembreMagasin::Actif,
            [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::CAISSE_SECURISEE_CONSULTER],
        );

        $this->assertTrue($vendeur->fresh()->can('viewAny', EnregistrementFiscal::class));
        $this->assertTrue($vendeur->fresh()->can('view', $evenement));
    }

    public function test_un_enregistrement_d_un_autre_magasin_ne_se_consulte_pas(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $evenementDeLAutre = $this->evenement();

        app(TenantContext::class)->set($magasin);

        $this->assertTrue($proprietaire->can('viewAny', EnregistrementFiscal::class));
        $this->assertFalse($proprietaire->can('view', $evenementDeLAutre));
    }

    public function test_sans_magasin_actif_rien_ne_se_consulte(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $evenement = $this->evenement();

        app(TenantContext::class)->clear();

        $this->assertFalse($proprietaire->can('viewAny', EnregistrementFiscal::class));
        $this->assertFalse($proprietaire->can('view', $evenement));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ecritures(): array
    {
        return [
            'création' => ['create'],
            'modification' => ['update'],
            'suppression' => ['delete'],
            'suppression en masse' => ['deleteAny'],
            'suppression définitive' => ['forceDelete'],
            'suppression définitive en masse' => ['forceDeleteAny'],
            'restauration' => ['restore'],
            'restauration en masse' => ['restoreAny'],
            'duplication' => ['replicate'],
            'réordonnancement' => ['reorder'],
        ];
    }

    #[DataProvider('ecritures')]
    public function test_meme_le_proprietaire_n_ecrit_rien_dans_le_registre(string $capacite): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $evenement = $this->evenement();
        $avecUnEnregistrement = in_array($capacite, ['update', 'delete', 'forceDelete', 'restore', 'replicate'], true);

        $autorise = $proprietaire->can($capacite, $avecUnEnregistrement ? $evenement : EnregistrementFiscal::class);

        $this->assertFalse($autorise);
    }
}
