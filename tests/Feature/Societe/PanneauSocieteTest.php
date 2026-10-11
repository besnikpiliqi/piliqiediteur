<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\RessourceDuPanneauSociete;
use App\Models\User;
use App\Policies\SocietePolicy;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use LogicException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Le panneau de l'administration d'une société (voir SocietePanelProvider),
 * réservé à son propriétaire, en mode d'autorisation strict : chaque page
 * décide elle-même qui l'ouvre, chaque ressource hérite de
 * RessourceDuPanneauSociete et passe par une policy, et les champs de
 * choix n'y proposent des magasins ou des comptes que par une relation de
 * la société affichée. Rien de tout cela ne touche le panneau des
 * magasins.
 */
class PanneauSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque page déclare sa propre règle d'accès (Filament ouvre sinon une
     * page à tous) et ne s'ouvre qu'au propriétaire actif de la société
     * affichée : ni à un membre d'un de ses magasins, ni au propriétaire
     * d'une autre société, ni à un compte suspendu, ni sans connexion.
     * Chaque ressource hérite de la base stricte, et le modèle qu'elle
     * gère a une policy explicite : sans elle, la Gate refuserait tout.
     */
    public function test_chaque_ressource_et_page_du_panneau_societe_a_une_policy_complete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $administrateurDuMagasin = $this->membre($magasin, RoleMagasin::Administrateur);
        $proprietaireDUneAutre = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $suspendu = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Boutique suspendue']));
        $suspendu->forceFill(['statut' => StatutCompte::Suspendu])->save();
        $panneau = Filament::getPanel('societe');
        $pages = $panneau->getPages();

        $this->assertNotEmpty($pages);
        $this->assertInstanceOf(SocietePolicy::class, Gate::getPolicyFor(Societe::class));

        foreach ($pages as $page) {
            /** @var class-string<Page> $page */
            $this->assertSame($page, (new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName(), $page);
            $this->assertSame(
                ['proprietaire' => true, 'vendeur' => false, 'administrateur_du_magasin' => false, 'autre_societe' => false, 'sans_connexion' => false],
                [
                    'proprietaire' => $this->ouvre($page, $proprietaire, $magasin->societe),
                    'vendeur' => $this->ouvre($page, $vendeur, $magasin->societe),
                    'administrateur_du_magasin' => $this->ouvre($page, $administrateurDuMagasin, $magasin->societe),
                    'autre_societe' => $this->ouvre($page, $proprietaireDUneAutre, $magasin->societe),
                    'sans_connexion' => $this->ouvre($page, null, $magasin->societe),
                ],
                $page,
            );
            $this->assertFalse($this->ouvre($page, $suspendu, $suspendu->societe), $page);
        }

        foreach ($panneau->getResources() as $ressource) {
            /** @var class-string<resource> $ressource */
            $this->assertTrue(is_subclass_of($ressource, RessourceDuPanneauSociete::class), $ressource);
            $this->assertFalse($ressource::shouldCheckPolicyExistence(), $ressource);
            $this->assertNotNull(Gate::getPolicyFor($ressource::getModel()), $ressource);
        }

        $this->assertFalse(RessourceDuPanneauSociete::shouldCheckPolicyExistence());
    }

    /**
     * Le mode strict est redéclaré sur la base des ressources de la
     * société : il ne change rien aux ressources du panneau des magasins,
     * qui contrôlent toujours l'existence de leur policy.
     */
    public function test_les_ressources_du_panneau_magasin_gardent_le_controle_d_existence_des_policies(): void
    {
        $ressources = Filament::getPanel('app')->getResources();

        $this->assertNotEmpty($ressources);

        foreach ($ressources as $ressource) {
            /** @var class-string<resource> $ressource */
            $this->assertFalse(is_subclass_of($ressource, RessourceDuPanneauSociete::class), $ressource);
            $this->assertTrue($ressource::shouldCheckPolicyExistence(), $ressource);
        }

        $this->assertTrue(Resource::shouldCheckPolicyExistence());
    }

    /**
     * Le garde des champs de choix (voir
     * AppServiceProvider::garderLesChoixDuPanneauSociete()) ne vaut que
     * dans le panneau société : il y refuse des magasins ou des comptes
     * proposés sans passer par la société affichée, et laisse intacts les
     * champs du panneau des magasins.
     */
    public function test_les_options_du_panneau_magasin_ne_sont_pas_touchees_par_les_gardes_du_panneau_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autreSociete = $this->ouvrirUneSociete(['nom' => 'Autre Boutique'])->societe;
        $contexte = app(TenantContext::class);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin, isQuiet: true);
        $contexte->set($magasin);

        $this->assertSame([], Select::make('vendeur')->model($magasin)->relationship('utilisateurs', 'name')->getExtraAttributes());
        $this->assertSame([], CheckboxList::make('vendeurs')->model($magasin)->relationship('utilisateurs', 'name')->getExtraAttributes());

        $contexte->clear();
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($magasin->societe, isQuiet: true);
        $contexte->poserSociete($magasin->societe);

        $this->assertSame([], Select::make('magasin')->model($magasin->societe)->relationship('magasins', 'nom')->getExtraAttributes());
        $this->assertSame([], Select::make('membre')->model($magasin->societe)->relationship('membres', 'name')->getExtraAttributes());
        $this->assertThrows(
            fn () => Select::make('vendeur')->model($magasin)->relationship('utilisateurs', 'name')->getExtraAttributes(),
            LogicException::class,
            'Le champ « vendeur » de l\'espace société propose des magasins ou des comptes sans passer par une relation de la société.',
        );
        $this->assertThrows(
            fn () => CheckboxList::make('vendeurs')->model($magasin)->relationship('utilisateurs', 'name')->getExtraAttributes(),
            LogicException::class,
        );
        $this->assertThrows(
            fn () => Select::make('magasin')->model($autreSociete)->relationship('magasins', 'nom')->getExtraAttributes(),
            LogicException::class,
        );
    }

    /**
     * La page s'ouvre-t-elle à ce compte, dans l'administration de cette
     * société ?
     *
     * @param  class-string<Page>  $page
     */
    private function ouvre(string $page, ?User $compte, Societe $societe): bool
    {
        auth()->logout();

        if ($compte !== null) {
            $this->actingAs($compte);
        }

        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe, isQuiet: true);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($societe);

        return $page::canAccess();
    }
}
