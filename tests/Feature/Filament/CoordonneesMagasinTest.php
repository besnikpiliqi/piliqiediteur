<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\CoordonneesMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les coordonnées du magasin et ses références d'acheteur, dans son
 * panneau (voir CoordonneesMagasin) : réglées avec « Paramètres du
 * magasin », que le propriétaire et les responsables ont toujours et
 * qu'ils cochent pour qui ils veulent ; son nom, son adresse et son SIRET
 * s'y lisent sans se modifier, et rien de la société n'y change.
 */
class CoordonneesMagasinTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_responsable_regle_l_e_mail_et_le_telephone_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe->fresh();
        $this->dansLeMagasin($magasin, $this->responsable($magasin));

        Livewire::test(CoordonneesMagasin::class)
            ->assertSeeText(['Ma Boutique', (string) $magasin->siret])
            ->assertFormFieldDoesNotExist('nom')
            ->assertFormFieldDoesNotExist('siret')
            ->fillForm(['email' => 'lyon@boutique.test', 'telephone' => '04 72 00 00 00', 'references_acheteur' => ['CLI-4521', ' CLI-4521 ', 'ACH-9']])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Coordonnées du magasin enregistrées')
            ->assertFormSet(['references_acheteur' => ['CLI-4521', 'ACH-9']]);

        $regle = $magasin->fresh();
        $this->assertSame(['lyon@boutique.test', '04 72 00 00 00', ['CLI-4521', 'ACH-9'], 'Ma Boutique'], [$regle->email, $regle->telephone, $regle->references_acheteur, $regle->nom]);
        $this->assertSame(['lyon@boutique.test', '04 72 00 00 00'], [Emetteur::duMagasin($regle)->email, Emetteur::duMagasin($regle)->telephone]);
        $this->assertSame($societe->getAttributes(), $societe->fresh()->getAttributes());
    }

    public function test_une_reference_d_acheteur_trop_longue_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        Livewire::test(CoordonneesMagasin::class)
            ->fillForm(['references_acheteur' => [str_repeat('A', 101)]])
            ->call('save')
            ->assertHasFormErrors(['references_acheteur.0']);

        $this->assertNull($magasin->fresh()->references_acheteur);
    }

    public function test_un_vendeur_n_y_accede_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->membre($magasin, RoleMagasin::Vendeur));

        Livewire::test(CoordonneesMagasin::class)->assertForbidden();

        $this->get(CoordonneesMagasin::getUrl(tenant: $magasin))->assertForbidden();
    }

    public function test_un_vendeur_a_qui_le_responsable_coche_les_parametres_y_accede(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->choisirSonMotDePasse(app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Paul Martin'], RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PARAMETRES_GERER])));
        $this->dansLeMagasin($magasin, $vendeur);

        Livewire::test(CoordonneesMagasin::class)
            ->fillForm(['telephone' => '04 72 11 11 11'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('04 72 11 11 11', $magasin->fresh()->telephone);
    }

    /**
     * La page ne lit et n'écrit que le magasin actif : celles d'un autre
     * magasin de la même société, ouvert ensuite, sont les siennes.
     */
    public function test_les_coordonnees_restent_celles_du_magasin_actif(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon', 'email' => 'lyon@boutique.test']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne', 'email' => 'villeurbanne@boutique.test']);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLeMagasin($lyon, $proprietaire);

        Livewire::test(CoordonneesMagasin::class)
            ->fillForm(['telephone' => '04 72 00 00 00', 'references_acheteur' => ['CLI-LYON']])
            ->call('save');

        $this->dansLeMagasin($villeurbanne, $proprietaire);
        Livewire::test(CoordonneesMagasin::class)
            ->assertFormSet(['email' => 'villeurbanne@boutique.test', 'telephone' => null, 'references_acheteur' => []])
            ->assertSeeText('Villeurbanne')
            ->assertDontSeeText('CLI-LYON');
        $this->assertSame(['04 72 00 00 00', ['CLI-LYON']], [$lyon->fresh()->telephone, $lyon->fresh()->references_acheteur]);
        $this->assertSame([null, null], [$villeurbanne->fresh()->telephone, $villeurbanne->fresh()->references_acheteur]);
    }

    /**
     * L'expert-comptable ne règle rien ; un magasin fermé, en lecture
     * seule, ne règle plus ses coordonnées, pas même son propriétaire.
     */
    public function test_ni_l_expert_comptable_ni_un_magasin_ferme_ne_reglent_les_coordonnees(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->expertComptable($magasin));
        Livewire::test(CoordonneesMagasin::class)->assertForbidden();

        $magasin->forceFill(['statut' => StatutMagasin::Ferme])->save();
        $this->dansLeMagasin($magasin->fresh(), $this->proprietaireDe($magasin));

        Livewire::test(CoordonneesMagasin::class)->assertForbidden();
    }

    /**
     * La page, ouverte avant qu'une autre personne n'ajoute une référence
     * d'acheteur, enregistre seulement le téléphone qu'on y corrige : la
     * référence reste ; corriger aussi les références que l'autre personne
     * a changées entre-temps est refusé, et rien ne s'écrit.
     */
    public function test_une_page_ouverte_avant_la_correction_d_une_autre_personne_ne_l_efface_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        $page = Livewire::test(CoordonneesMagasin::class);
        $autrePage = Livewire::test(CoordonneesMagasin::class);

        $magasin->fresh()->update(['references_acheteur' => ['CLI-4521']]);
        $page->fillForm(['telephone' => '04 72 00 00 00'])->call('save')->assertNotified('Coordonnées du magasin enregistrées');
        $autrePage->fillForm(['references_acheteur' => ['ACH-9']])->call('save')->assertNotified(ModifierLesParametresDuMagasinAction::REFUS_PERIMES);

        $this->assertSame(['04 72 00 00 00', ['CLI-4521']], [$magasin->fresh()->telephone, $magasin->fresh()->references_acheteur]);
    }
}
