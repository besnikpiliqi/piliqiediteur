<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'équipe d'un magasin, dans son panneau (voir AffectationPolicy) : les
 * affectations de membre du magasin actif, que voient et gèrent son
 * propriétaire et ses responsables, jamais celles d'un autre magasin ; un
 * vendeur, même avec toutes les cases, un administrateur ou
 * l'expert-comptable n'en voient aucune. Personne ne modifie ni ne retire
 * sa propre affectation.
 */
class AffectationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seuls_le_proprietaire_et_un_responsable_du_magasin_actif_gerent_ses_affectations(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->affectationDe($magasin, $this->membre($magasin, RoleMagasin::Vendeur));
        $observateur = $this->affectationDe($magasin, $this->membre($magasin, RoleMagasin::Observateur));
        app(TenantContext::class)->set($magasin);

        foreach ([$proprietaire, $responsable] as $gestionnaire) {
            $this->assertTrue($gestionnaire->can('viewAny', Affectation::class), $gestionnaire->email);
            $this->assertTrue($gestionnaire->can('create', Affectation::class), $gestionnaire->email);

            foreach ([$vendeur, $observateur] as $affectation) {
                $this->assertTrue($gestionnaire->can('view', $affectation), $gestionnaire->email);
                $this->assertTrue($gestionnaire->can('update', $affectation), $gestionnaire->email);
                $this->assertTrue($gestionnaire->can('delete', $affectation), $gestionnaire->email);
                $this->assertFalse($gestionnaire->can('forceDelete', $affectation), $gestionnaire->email);
            }
        }

        $saPropreAffectation = $this->affectationDe($magasin, $responsable);
        $this->assertFalse($responsable->can('update', $saPropreAffectation));
        $this->assertFalse($responsable->can('delete', $saPropreAffectation));
        $this->assertTrue($proprietaire->can('update', $saPropreAffectation));
    }

    public function test_une_affectation_d_un_autre_magasin_n_est_jamais_accessible(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeurDeLAutre = $this->affectationDe($autre, $this->membre($autre, RoleMagasin::Vendeur));
        $proprietaireDUneAutreSociete = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre société']));
        app(TenantContext::class)->set($magasin);

        foreach ([$proprietaire, $responsable] as $compte) {
            $this->assertFalse($compte->can('view', $vendeurDeLAutre), $compte->email);
            $this->assertFalse($compte->can('update', $vendeurDeLAutre), $compte->email);
            $this->assertFalse($compte->can('delete', $vendeurDeLAutre), $compte->email);
        }

        app(TenantContext::class)->set($autre);
        $this->assertFalse($responsable->can('viewAny', Affectation::class));
        $this->assertFalse($responsable->can('view', $vendeurDeLAutre));
        $this->assertFalse($proprietaireDUneAutreSociete->can('view', $vendeurDeLAutre));
    }

    /**
     * Ni un vendeur qui a toutes les cases, ni un administrateur, même de
     * tous les magasins et autorisé à tout, ni l'expert-comptable ne voient
     * une affectation du magasin : la policy refuse, et l'adresse de la
     * fiche répond 403.
     */
    public function test_un_vendeur_ou_un_administrateur_ne_voit_aucune_affectation_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->affectationDe($magasin, $this->membre($magasin, RoleMagasin::Vendeur));
        $comptes = [
            $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes()),
            $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes()),
            $this->expertComptable($magasin),
        ];
        app(TenantContext::class)->set($magasin);

        foreach ($comptes as $compte) {
            $this->assertFalse($compte->can('viewAny', Affectation::class), $compte->email);
            $this->assertFalse($compte->can('view', $vendeur), $compte->email);
            $this->assertFalse($compte->can('create', Affectation::class), $compte->email);
            $this->flushSession();
            $this->actingAs($compte)->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $vendeur, 'tenant' => $magasin]))->assertForbidden();
            $this->actingAs($compte)->get(EquipeDuMagasinResource::getUrl('index', ['tenant' => $magasin]))->assertForbidden();
        }
    }

    private function affectationDe(Magasin $magasin, User $compte): Affectation
    {
        return Affectation::query()->where('magasin_id', $magasin->id)->where('user_id', $compte->id)->sole();
    }
}
