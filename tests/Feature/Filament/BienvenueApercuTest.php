<?php

namespace Tests\Feature\Filament;

use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Widgets\BienvenueApercu;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Point 5 de la série 8 : le tableau de bord du magasin dit, après
 * « Bonjour » et le nom de la personne, son rôle dans le magasin ouvert
 * (voir User::libelleDuRoleDans()).
 */
class BienvenueApercuTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_lit_proprietaire(): void
    {
        $magasin = $this->boutiqueLyon1(User::factory()->create(['name' => 'Jeanne Dupont']));
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour Jeanne Dupont', 'Propriétaire de Ma Boutique SAS', 'Déconnexion']);
    }

    public function test_un_administrateur_lit_administrateur(): void
    {
        $magasin = $this->boutiqueLyon1();
        $administrateur = $this->administrateur($magasin);
        $this->dansLeMagasin($magasin, $administrateur);

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour '.$administrateur->name, 'Administrateur'])
            ->assertDontSee('Propriétaire');
    }

    public function test_un_expert_comptable_lit_expert_comptable(): void
    {
        $magasin = $this->boutiqueLyon1();
        $expertComptable = $this->expertComptable($magasin);
        $this->dansLeMagasin($magasin, $expertComptable);

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour '.$expertComptable->name, 'Expert-comptable']);
    }

    public function test_un_responsable_lit_responsable_et_son_magasin(): void
    {
        $magasin = $this->boutiqueLyon1();
        $responsable = $this->membre($magasin, RoleMagasin::Responsable, identite: ['name' => 'Paul Martin']);
        $this->dansLeMagasin($magasin, $responsable);

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour Paul Martin', 'Responsable · Boutique Lyon 1']);
    }

    /**
     * La bienvenue est celle du tableau de bord du magasin : elle y remplace
     * celle de Filament, qui ne disait que le nom.
     */
    public function test_un_vendeur_lit_vendeur(): void
    {
        $magasin = $this->boutiqueLyon1();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, identite: ['name' => 'Paul Martin']);
        $this->dansLeMagasin($magasin, $vendeur);

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour Paul Martin', 'Vendeur · Boutique Lyon 1']);
        $this->get(Dashboard::getUrl(panel: 'app', tenant: $magasin))->assertSeeInOrder(['Bonjour Paul Martin', 'Vendeur · Boutique Lyon 1']);
    }

    public function test_un_observateur_lit_observateur(): void
    {
        $magasin = $this->boutiqueLyon1();
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, identite: ['name' => 'Paul Martin']);
        $this->dansLeMagasin($magasin, $observateur);

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour Paul Martin', 'Observateur · Boutique Lyon 1']);
    }

    public function test_le_magasin_de_demonstration_le_dit(): void
    {
        $societe = $this->inscrireUneSociete(nomDuProprietaire: 'Jeanne Dupont');
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        $this->dansLeMagasin($demonstration, $proprietaire);

        $bienvenue = Livewire::test(BienvenueApercu::class);

        $bienvenue->assertSeeInOrder(['Bonjour Jeanne Dupont', "Propriétaire de {$societe->raison_sociale} (démonstration)"]);
    }

    /**
     * Le nom de la personne et celui de la société s'impriment échappés :
     * aucune balise saisie n'est rendue.
     */
    public function test_le_nom_et_le_role_s_affichent_echappes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique Lyon 1', 'raison_sociale' => 'Dupont <b>et fils</b>'], User::factory()->create(['name' => 'Jeanne <script>alert(1)</script>']));
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $html = Livewire::test(BienvenueApercu::class)->html();

        $this->assertStringContainsString('Jeanne &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('Propriétaire de Dupont &lt;b&gt;et fils&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>et fils</b>', $html);
    }

    /**
     * La Boutique Lyon 1 de la société Ma Boutique SAS, dont le compte
     * donné, ou un nouveau, est le propriétaire.
     */
    private function boutiqueLyon1(?User $proprietaire = null): Magasin
    {
        return $this->ouvrirUneSociete(['nom' => 'Boutique Lyon 1', 'raison_sociale' => 'Ma Boutique SAS'], $proprietaire);
    }
}
