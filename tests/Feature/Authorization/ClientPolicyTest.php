<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Vérifie que la policy ne se fie jamais à la seule permission Spatie :
 * même avec la permission accordée, un enregistrement d'un autre magasin
 * (ou consulté hors du contexte où la permission a été accordée) doit
 * rester refusé. C'est la défense en profondeur décrite dans
 * app/Policies/MagasinPolicy.php.
 */
class ClientPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, false],
            'vendeur' => [RoleMagasin::Vendeur, false],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    /**
     * Un par un ou en masse : seulement ceux qui ont la permission de
     * supprimer les clients -- pas le vendeur, qui en crée et en modifie.
     */
    #[DataProvider('roles')]
    public function test_supprimer_des_clients_selon_le_role(RoleMagasin $role, bool $peutSupprimer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $client = Client::factory()->create();

        $this->assertSame($peutSupprimer, $membre->can('deleteAny', Client::class));
        $this->assertSame($peutSupprimer, $membre->can('delete', $client));
    }

    public function test_la_permission_est_scopee_par_magasin_et_le_modele_doit_appartenir_au_contexte_actif(): void
    {
        $contexte = app(TenantContext::class);

        $magasinA = Magasin::factory()->create();
        $magasinB = Magasin::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user);

        // La permission n'est accordée à l'utilisateur QUE dans le magasin A.
        $contexte->set($magasinA);
        Permission::findOrCreate(PermissionRegistry::CLIENT_VIEW, 'web');
        $role = Role::findOrCreate('vendeur', 'web');
        $role->givePermissionTo(PermissionRegistry::CLIENT_VIEW);
        $user->assignRole($role);

        $clientA = Client::factory()->create();

        // Changement de magasin actif : clientB appartient à B, l'utilisateur
        // n'a aucun rôle/permission dans B.
        $contexte->set($magasinB);
        $clientB = Client::factory()->create();

        $this->assertFalse($user->can('view', $clientB), 'Aucune permission accordée dans le magasin B.');

        // Toujours dans le contexte B : même si on regarde clientA (du
        // magasin A), la policy doit refuser car le modèle n'appartient pas
        // au magasin ACTIF -- défense en profondeur indépendante de la
        // permission Spatie.
        $this->assertFalse($user->can('view', $clientA), "Le modèle n'appartient pas au magasin actif.");

        // Retour dans le contexte où la permission a été accordée ET où le
        // modèle appartient au magasin actif : autorisé.
        $contexte->set($magasinA);
        $this->assertTrue($user->can('view', $clientA));
    }
}
