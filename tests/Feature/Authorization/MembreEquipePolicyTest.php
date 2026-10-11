<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modifier un membre, lui donner un nouveau mot de passe ou le retirer
 * demande « Équipe : modifier », et jamais sur sa propre ligne.
 */
class MembreEquipePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_avec_equipe_modifier_un_membre_modifie_et_retire_un_autre_membre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $gestionnaire = $this->gestionnaireDeLEquipe($magasin);
        $ligneDUnCollegue = $this->ligneDe($magasin, $this->membre($magasin, RoleMagasin::Vendeur));

        $this->assertTrue($gestionnaire->can('update', $ligneDUnCollegue));
        $this->assertTrue($gestionnaire->can('delete', $ligneDUnCollegue));
    }

    /**
     * Il s'y donnerait un autre rôle et son modèle d'autorisations, ou
     * retirerait par erreur son propre accès, sans retour possible : son
     * adresse e-mail ne sert plus jamais à un nouveau membre.
     */
    public function test_personne_ne_modifie_ni_ne_retire_sa_propre_ligne(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $gestionnaire = $this->gestionnaireDeLEquipe($magasin);
        $saLigne = $this->ligneDe($magasin, $gestionnaire);

        $this->assertFalse($gestionnaire->can('update', $saLigne));
        $this->assertFalse($gestionnaire->can('delete', $saLigne));
    }

    private function gestionnaireDeLEquipe(Magasin $magasin): User
    {
        return $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::EQUIPE_VIEW, PermissionRegistry::EQUIPE_MODIFIER]);
    }

    private function ligneDe(Magasin $magasin, User $utilisateur): MagasinUser
    {
        return $magasin->membres()->where('user_id', $utilisateur->getKey())->firstOrFail();
    }
}
