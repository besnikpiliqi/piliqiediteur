<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Archiver des fournisseurs, un par un ou en masse, et les restaurer :
 * seulement ceux qui ont la permission d'archiver les fournisseurs.
 */
class FournisseurPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function roles(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), false],
            'vendeur' => [RoleMagasin::Vendeur, null, false],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_archiver_des_fournisseurs_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutArchiver): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $fournisseur = Fournisseur::factory()->create();

        $this->assertSame($peutArchiver, $membre->can('deleteAny', Fournisseur::class));
        $this->assertSame($peutArchiver, $membre->can('delete', $fournisseur));
    }

    /**
     * Restaurer défait l'archivage : les mêmes rôles que pour archiver.
     */
    #[DataProvider('roles')]
    public function test_restaurer_des_fournisseurs_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutRestaurer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $fournisseur = Fournisseur::factory()->trashed()->create();

        $this->assertSame($peutRestaurer, $membre->can('restore', $fournisseur));
    }

    /**
     * Sa page de modification ne trouve plus un fournisseur archivé : il
     * se restaure d'abord. Aucune fiche ne s'efface, même pour le
     * propriétaire.
     */
    public function test_un_fournisseur_archive_ne_se_modifie_pas_avant_sa_restauration_et_ne_s_efface_jamais(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $fournisseur = Fournisseur::factory()->create();
        $archive = Fournisseur::factory()->trashed()->create();

        $this->assertTrue($proprietaire->can('update', $fournisseur));
        $this->assertFalse($proprietaire->can('update', $archive));
        $this->assertFalse($proprietaire->can('forceDelete', $archive));
    }
}
