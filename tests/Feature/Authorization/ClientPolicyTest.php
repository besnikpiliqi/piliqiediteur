<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * Un par un ou en masse : seulement ceux qui ont la permission
     * d'archiver les clients -- pas le vendeur, qui en crée et en modifie.
     */
    #[DataProvider('roles')]
    public function test_archiver_des_clients_selon_le_role(RoleMagasin $role, bool $peutArchiver): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $client = Client::factory()->create();

        $this->assertSame($peutArchiver, $membre->can('deleteAny', Client::class));
        $this->assertSame($peutArchiver, $membre->can('delete', $client));
    }

    /**
     * Restaurer défait l'archivage : les mêmes rôles que pour archiver.
     */
    #[DataProvider('roles')]
    public function test_restaurer_des_clients_selon_le_role(RoleMagasin $role, bool $peutRestaurer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $client = Client::factory()->trashed()->create();

        $this->assertSame($peutRestaurer, $membre->can('restore', $client));
    }

    /**
     * Sa page de modification ne trouve plus un client archivé : il se
     * restaure d'abord. Aucune fiche ne s'efface, même pour le
     * propriétaire.
     */
    public function test_un_client_archive_ne_se_modifie_pas_avant_sa_restauration_et_ne_s_efface_jamais(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $client = Client::factory()->create();
        $archive = Client::factory()->trashed()->create();

        $this->assertTrue($proprietaire->can('update', $client));
        $this->assertFalse($proprietaire->can('update', $archive));
        $this->assertFalse($proprietaire->can('forceDelete', $archive));
    }

    /**
     * Toutes les ventes anonymes du magasin le portent : même le
     * propriétaire ne l'archive pas.
     */
    public function test_personne_n_archive_le_client_comptoir(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $comptoir = Client::query()->where('est_comptoir', true)->sole();

        $this->assertFalse($proprietaire->can('delete', $comptoir));
        $this->assertTrue($proprietaire->can('update', $comptoir));
    }

    public function test_la_permission_est_scopee_par_magasin_et_le_modele_doit_appartenir_au_contexte_actif(): void
    {
        $contexte = app(TenantContext::class);

        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $magasinB = Magasin::factory()->create();

        // La permission n'est accordée à l'utilisateur QUE dans le magasin A.
        $user = $this->membre($magasinA, RoleMagasin::Vendeur, [PermissionRegistry::CLIENT_VIEW]);
        $this->actingAs($user);
        $contexte->set($magasinA);

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
