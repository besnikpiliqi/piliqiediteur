<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Throwable;

/**
 * Un responsable a toutes les autorisations de son magasin, sans case,
 * comme le propriétaire dans ce seul magasin ; il n'a aucun droit de la
 * société, ni aucun droit dans un autre magasin dont il n'est pas
 * responsable.
 */
class DroitsDuResponsableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les paramètres du magasin, la clôture de la journée, la fermeture de
     * la caisse d'un vendeur, les achats et le stock : tout, sans case.
     */
    public function test_un_responsable_a_tous_les_droits_de_son_magasin_sans_case(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin);

        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $responsable->autorisationsDans($magasin));
        foreach ([PermissionRegistry::PARAMETRES_GERER, PermissionRegistry::RELANCES_REGLER, PermissionRegistry::PRIX_VENTE_REGLER, PermissionRegistry::CLOTURE_CAISSE_GERER, PermissionRegistry::FACTURE_ACHAT_VALIDER, PermissionRegistry::PRODUIT_AJUSTER_STOCK, PermissionRegistry::AVOIR_CREER, PermissionRegistry::PAIEMENT_CONTRE_PASSER] as $autorisation) {
            $this->assertTrue($responsable->can($autorisation), $autorisation);
        }
        $this->assertTrue($responsable->can('cloturer', ClotureCaisse::class));
        $this->assertTrue($responsable->can('fermerLaCaisseDe', [ClotureCaissePersonnelle::class, $vendeur]));
    }

    /**
     * L'espace d'administration de la société lui répond 403, et aucune
     * action de la société ne passe, même appelée directement : ni
     * l'identité, ni la fiscalité, ni créer ou modifier un magasin, ni les
     * administrateurs, l'expert-comptable ou un autre responsable.
     */
    public function test_un_responsable_n_a_aucun_droit_de_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->actingAs($responsable)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))->assertForbidden();
        foreach (AutorisationsSociete::toutes() as $autorisation) {
            $this->assertFalse($responsable->can($autorisation), $autorisation);
        }
        $this->assertSame([], $responsable->autorisationsDeSociete());

        $gestes = [
            'identité' => fn () => app(ModifierIdentiteSocieteAction::class)->handle($responsable, $societe, ['raison_sociale' => 'Prise']),
            'fiscalité' => fn () => app(ModifierParametresFiscauxSocieteAction::class)->handle($responsable, $societe, ['mois_fin_exercice' => 6]),
            'créer un magasin' => fn () => app(CreerEtablissementAction::class)->handle($responsable, $societe, ['nom' => 'Pris', 'nic' => MagasinFactory::nicLibre($societe)]),
            'modifier son magasin' => fn () => app(ModifierEtablissementAction::class)->handle($responsable, $magasin, ['nom' => 'Pris']),
            'créer un administrateur' => fn () => app(CreerAdministrateurAction::class)->handle($responsable, $societe, ['name' => 'Admin'], null, [], []),
            'nommer administrateur' => fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($responsable, $vendeur, null, [], []),
            'créer l\'expert-comptable' => fn () => app(CreerExpertComptableAction::class)->handle($responsable, $societe, ['name' => 'Cabinet']),
            'nommer un responsable' => fn () => app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Chef'], RoleMagasin::Responsable),
        ];

        foreach ($gestes as $geste => $appel) {
            $this->assertThrows($appel, Throwable::class, null);
            $this->assertNotSame('Prise', $societe->fresh()->raison_sociale, $geste);
        }
        foreach (['créer un administrateur', 'nommer administrateur', 'créer l\'expert-comptable', 'nommer un responsable'] as $geste) {
            $this->assertThrows($gestes[$geste], AuthorizationException::class);
        }

        $this->assertSame('Ma Boutique', $magasin->fresh()->nom);
        $this->assertSame(1, $societe->magasinsReels()->count());
        $this->assertSame(12, (int) $societe->fresh()->mois_fin_exercice);
        $this->assertFalse(User::query()->where('societe_id', $societe->id)->whereIn('name', ['Admin', 'Cabinet', 'Chef'])->exists());
        $this->assertTrue($vendeur->refresh()->estMembre());
    }

    public function test_un_responsable_n_a_aucun_droit_dans_un_autre_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin);

        $this->assertSame([], $responsable->autorisationsDans($autre));
        $this->assertFalse($responsable->peutOuvrir($autre));
        $this->assertFalse($responsable->canAccessTenant($autre));
        app(TenantContext::class)->set($autre);
        foreach (PermissionRegistry::toutes() as $autorisation) {
            $this->assertFalse($responsable->can($autorisation), $autorisation);
        }
        $this->actingAs($responsable)->get("/app/{$autre->uuid}")->assertNotFound();
    }

    public function test_deux_responsables_du_meme_magasin_ont_les_memes_droits(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->responsable($magasin);
        $second = $this->responsable($magasin);

        $this->assertEqualsCanonicalizing($premier->autorisationsDans($magasin), $second->autorisationsDans($magasin));
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $second->autorisationsDans($magasin));
    }

    /**
     * Une personne responsable de deux magasins y a un compte dans chacun,
     * avec tous les droits de ce magasin et aucun ailleurs.
     */
    public function test_une_personne_responsable_de_deux_magasins_a_tous_les_droits_dans_les_deux(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $troisieme = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Troisième']);
        $comptes = [[$magasin, $this->responsable($magasin)], [$autre, $this->responsable($autre)]];

        foreach ($comptes as [$sonMagasin, $responsable]) {
            /** @var Magasin $sonMagasin */
            /** @var User $responsable */
            $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $responsable->autorisationsDans($sonMagasin), $sonMagasin->nom);
            $this->assertTrue($responsable->estResponsableDe($sonMagasin));

            foreach ([$magasin, $autre, $troisieme] as $ailleurs) {
                if (! $ailleurs->is($sonMagasin)) {
                    $this->assertSame([], $responsable->autorisationsDans($ailleurs), $ailleurs->nom);
                }
            }
        }
    }
}
