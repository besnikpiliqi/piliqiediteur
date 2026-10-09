<?php

namespace Tests\Feature\Authorization;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Supprimer des fournisseurs, un par un ou en masse : seulement ceux qui
 * ont la permission de supprimer les fournisseurs.
 */
class FournisseurPolicyTest extends TestCase
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

    #[DataProvider('roles')]
    public function test_supprimer_des_fournisseurs_selon_le_role(RoleMagasin $role, bool $peutSupprimer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $fournisseur = Fournisseur::factory()->create();

        $this->assertSame($peutSupprimer, $membre->can('deleteAny', Fournisseur::class));
        $this->assertSame($peutSupprimer, $membre->can('delete', $fournisseur));
    }

    /**
     * Restaurer défait une suppression : les mêmes rôles que pour
     * supprimer.
     */
    #[DataProvider('roles')]
    public function test_restaurer_des_fournisseurs_selon_le_role(RoleMagasin $role, bool $peutRestaurer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $fournisseur = Fournisseur::factory()->create();
        $fournisseur->delete();

        $this->assertSame($peutRestaurer, $membre->can('restore', $fournisseur));
    }

    /**
     * Sa page de modification ne trouve plus un fournisseur supprimé : il
     * se restaure d'abord.
     */
    public function test_un_fournisseur_supprime_ne_se_modifie_pas_avant_sa_restauration(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $fournisseur = Fournisseur::factory()->create();
        $supprime = Fournisseur::factory()->create();
        $supprime->delete();

        $this->assertTrue($proprietaire->can('update', $fournisseur));
        $this->assertFalse($proprietaire->can('update', $supprime));
    }
}
