<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierEtablissementAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
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
     * la caisse d'un vendeur, les achats et le stock : tout, sans case,
     * sauf le téléchargement des documents par période, que seul le
     * propriétaire lui coche (décision de l'éditeur du 03/11/2026, E3).
     */
    public function test_un_responsable_a_tous_les_droits_de_son_magasin_sans_case(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(TenantContext::class)->set($magasin);

        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), [PermissionRegistry::DOCUMENTS_TELECHARGER])), $responsable->autorisationsDans($magasin));
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
     * administrateurs ou un autre responsable, ni le cabinet lié : il ne le
     * désigne pas, ne met pas fin au lien et n'a aucun geste sur un compte
     * du cabinet, d'une autre société.
     */
    public function test_un_responsable_n_a_aucun_droit_de_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);
        $lien = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->id)->where('etat', EtatDuLien::Actif->value)->sole();
        $autreCabinet = $this->ouvrirUnCabinet();

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
            'nommer un responsable' => fn () => app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Chef'], RoleMagasin::Responsable),
            'désigner un cabinet' => fn () => app(DesignerUnCabinetAction::class)->handle($responsable, $societe, $autreCabinet),
            'mettre fin au lien' => fn () => app(MettreFinAuLienAction::class)->handle($responsable, $lien),
            'suspendre un compte du cabinet' => fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $collaborateur),
            'redonner l\'accès à un compte du cabinet' => fn () => app(RedonnerLAccesAction::class)->handle($responsable, $collaborateur, false),
        ];

        foreach ($gestes as $geste => $appel) {
            $this->assertThrows($appel, Throwable::class, null);
            $this->assertNotSame('Prise', $societe->fresh()->raison_sociale, $geste);
        }
        foreach (['créer un administrateur', 'nommer administrateur', 'nommer un responsable', 'désigner un cabinet', 'mettre fin au lien', 'suspendre un compte du cabinet', 'redonner l\'accès à un compte du cabinet'] as $geste) {
            $this->assertThrows($gestes[$geste], AuthorizationException::class);
        }

        $this->assertSame('Ma Boutique', $magasin->fresh()->nom);
        $this->assertSame(1, $societe->magasinsReels()->count());
        $this->assertSame(12, (int) $societe->fresh()->mois_fin_exercice);
        $this->assertFalse(User::query()->where('societe_id', $societe->id)->whereIn('name', ['Admin', 'Chef'])->exists());
        $this->assertTrue($vendeur->refresh()->estMembre());
        $this->assertSame(EtatDuLien::Actif, $lien->fresh()->etat);
        $this->assertTrue($collaborateur->refresh()->estActif());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('cabinet_id', $autreCabinet->id)->count());
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
        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), [PermissionRegistry::DOCUMENTS_TELECHARGER])), $second->autorisationsDans($magasin));
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
            $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), [PermissionRegistry::DOCUMENTS_TELECHARGER])), $responsable->autorisationsDans($sonMagasin), $sonMagasin->nom);
            $this->assertTrue($responsable->estResponsableDe($sonMagasin));

            foreach ([$magasin, $autre, $troisieme] as $ailleurs) {
                if (! $ailleurs->is($sonMagasin)) {
                    $this->assertSame([], $responsable->autorisationsDans($ailleurs), $ailleurs->nom);
                }
            }
        }
    }
}
