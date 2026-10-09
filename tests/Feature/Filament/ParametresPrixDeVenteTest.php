<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\ParametresPrixDeVente;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ParametresPrixDeVenteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le calcul est désactivé au départ, mais le magasin voit d'emblée un
     * vrai barème d'exemple, grisé tant qu'il ne l'a pas activé.
     */
    public function test_chaque_magasin_voit_le_bareme_d_exemple_desactive(): void
    {
        $magasin = $this->connecterLeProprietaire();

        $this->assertFalse($magasin->prix_vente_automatique);
        $this->assertNull($magasin->fresh()->baremePrixDeVenteActif());
        $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->fresh()->bareme_prix_vente);

        Livewire::test(ParametresPrixDeVente::class)
            ->assertFormSet(['actif' => false, 'benefice_au_dela' => 60.0, 'arrondi_x9' => false])
            ->assertCount('data.paliers', 7)
            ->assertFormFieldIsDisabled('paliers')
            ->assertFormFieldIsDisabled('benefice_au_dela')
            ->assertSee('Voici un exemple de barème')
            ->fillForm(['actif' => true])
            ->assertFormFieldIsEnabled('benefice_au_dela');
    }

    public function test_le_proprietaire_active_et_regle_son_bareme(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'benefice_au_dela' => 80, 'arrondi_x9' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $bareme = $magasin->fresh()->baremePrixDeVenteActif();
        $this->assertSame(BaremePrixDeVente::parDefaut()->paliers, $bareme->paliers);
        $this->assertSame([80.0, true], [$bareme->beneficeAuDela, $bareme->arrondiX9]);
    }

    /**
     * Désactiver le calcul ne perd pas le barème : le magasin le retrouve
     * tel qu'il l'avait laissé en le réactivant.
     */
    public function test_desactiver_le_calcul_garde_le_bareme_pour_plus_tard(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true])
            ->set('data.paliers', [['jusqu_a' => '10', 'benefice' => '20']])
            ->call('save');
        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $magasin->refresh();
        $this->assertFalse($magasin->prix_vente_automatique);
        $this->assertNull($magasin->baremePrixDeVenteActif());

        Livewire::test(ParametresPrixDeVente::class)
            ->assertFormSet(['actif' => false])
            ->fillForm(['actif' => true])
            ->assertCount('data.paliers', 1)
            ->call('save');

        $this->assertSame([['jusqu_a' => 10.0, 'benefice' => 20.0]], $magasin->fresh()->baremePrixDeVenteActif()->paliers);
    }

    /**
     * Le barème du logiciel n'est qu'un point de départ : le magasin le
     * remplace par ses propres paliers, autant qu'il en veut, et peut se
     * passer de bénéfice au-delà du dernier.
     */
    public function test_le_magasin_remplace_l_exemple_par_ses_propres_paliers(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'benefice_au_dela' => null])
            ->set('data.paliers', [
                ['jusqu_a' => '50', 'benefice' => '25'],
                ['jusqu_a' => '5', 'benefice' => '12'],
                ['jusqu_a' => '200', 'benefice' => '40'],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $bareme = $magasin->fresh()->baremePrixDeVenteActif();
        $this->assertSame([
            ['jusqu_a' => 5.0, 'benefice' => 12.0],
            ['jusqu_a' => 50.0, 'benefice' => 25.0],
            ['jusqu_a' => 200.0, 'benefice' => 40.0],
        ], $bareme->paliers);
        $this->assertNull($bareme->beneficeAuDela);

        Livewire::test(ParametresPrixDeVente::class)
            ->assertCount('data.paliers', 3)
            ->fillForm(['essai_prix_achat_ht' => '250'])
            ->assertSee('Aucun bénéfice prévu à ce prix d\'achat');
    }

    public function test_le_bareme_s_essaie_avant_d_etre_enregistre(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi_x9' => true, 'essai_prix_achat_ht' => '0.47', 'essai_taux_tva' => '20'])
            ->assertSee('9,00 € TTC (prix cible 12,56 €)')
            ->assertSee('bénéfice réel 7,03 € HT (visé 10,00 €)');
    }

    public function test_deux_paliers_au_meme_prix_dachat_sont_refuses(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true])
            ->set('data.paliers', [['jusqu_a' => '5', 'benefice' => '10'], ['jusqu_a' => '5', 'benefice' => '20']])
            ->call('save')
            ->assertHasFormErrors(['paliers.0.jusqu_a', 'paliers.1.jusqu_a']);

        $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->fresh()->bareme_prix_vente);
    }

    public function test_un_bareme_sans_palier_ni_benefice_est_refuse(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'benefice_au_dela' => null])
            ->set('data.paliers', [])
            ->call('save')
            ->assertHasFormErrors(['benefice_au_dela' => 'required']);

        $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->fresh()->bareme_prix_vente);
    }

    /**
     * Désactiver remet le barème enregistré : une saisie laissée en cours,
     * même invalide, n'empêche pas de désactiver et ne reste pas affichée
     * comme si elle valait.
     */
    public function test_desactiver_remet_le_bareme_enregistre_meme_apres_une_saisie_invalide(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'benefice_au_dela' => null])
            ->set('data.paliers', [])
            ->fillForm(['actif' => false])
            ->assertFormSet(['benefice_au_dela' => 60.0])
            ->assertCount('data.paliers', 7)
            ->call('save')
            ->assertHasNoFormErrors();

        $magasin->refresh();
        $this->assertFalse($magasin->prix_vente_automatique);
        $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->bareme_prix_vente);
    }

    /**
     * Désactiver le calcul remet le barème enregistré : les erreurs de la
     * saisie abandonnée disparaissent avec elle.
     */
    public function test_desactiver_le_calcul_efface_les_erreurs_de_la_saisie_abandonnee(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'benefice_au_dela' => null])
            ->set('data.paliers', [])
            ->call('save')
            ->assertHasFormErrors(['benefice_au_dela' => 'required'])
            ->fillForm(['actif' => false])
            ->assertHasNoFormErrors();
    }

    /**
     * Une TVA vidée n'est pas un taux de 0 % : l'essai n'affiche rien tant
     * qu'elle manque (0,47 € d'achat donneraient 10,47 € au lieu de
     * 12,56 € TTC), comme une facture d'achat ne propose rien.
     */
    public function test_l_essai_du_bareme_ne_calcule_rien_tant_que_la_tva_est_vide(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['essai_prix_achat_ht' => '0.47', 'essai_taux_tva' => ''])
            ->assertDontSee('10,47 €')
            ->assertDontSee('bénéfice réel')
            ->fillForm(['essai_taux_tva' => '20'])
            ->assertSee('12,56 € TTC');
    }

    public function test_l_essai_du_bareme_part_d_une_tva_de_20_pourcent(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->assertFormSet(['essai_taux_tva' => 20])
            ->fillForm(['essai_prix_achat_ht' => '0.47'])
            ->assertSee('12,56 € TTC');
    }

    public function test_un_vendeur_sans_la_permission_ne_peut_pas_acceder_a_la_page(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(ParametresPrixDeVente::canAccess());
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }
}
