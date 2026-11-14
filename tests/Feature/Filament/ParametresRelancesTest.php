<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Pages\ParametresRelances;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ParametresRelancesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Désactivées au départ, avec l'exemple du logiciel visible et grisé
     * tant que le magasin ne les a pas activées.
     */
    public function test_chaque_magasin_voit_les_delais_d_exemple_desactives(): void
    {
        $magasin = $this->connecterLeProprietaire();

        $this->assertFalse($magasin->fresh()->relances_automatiques);
        $this->assertSame([7, 15, 30], $magasin->fresh()->delais_relance);

        Livewire::test(ParametresRelances::class)
            ->assertFormSet(['actif' => false])
            ->assertCount('data.delais', 3)
            ->assertFormFieldIsDisabled('delais')
            ->assertSee('Voici un exemple')
            ->fillForm(['actif' => true])
            ->assertFormFieldIsEnabled('delais');
    }

    /**
     * Le magasin modifie, supprime et ajoute ses délais : ils sont gardés
     * dans l'ordre, la relance n°1 au plus court.
     */
    public function test_le_proprietaire_active_et_regle_ses_delais(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresRelances::class)
            ->fillForm(['actif' => true])
            ->set('data.delais', $this->delais('45', '10'))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Relances automatiques enregistrées');

        $magasin->refresh();
        $this->assertTrue($magasin->relances_automatiques);
        $this->assertSame([10, 45], $magasin->delais_relance);
    }

    /**
     * Désactiver ne perd pas les délais : le magasin les retrouve en
     * réactivant ; une saisie en cours, même invalide, n'empêche pas de
     * désactiver.
     */
    public function test_desactiver_garde_les_delais_enregistres(): void
    {
        $magasin = $this->connecterLeProprietaire();
        Livewire::test(ParametresRelances::class)->fillForm(['actif' => true])->set('data.delais', $this->delais('5'))->call('save');

        Livewire::test(ParametresRelances::class)
            ->set('data.delais', [])
            ->fillForm(['actif' => false])
            ->assertCount('data.delais', 1)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Relances automatiques désactivées');

        $magasin->refresh();
        $this->assertFalse($magasin->relances_automatiques);
        $this->assertSame([5], $magasin->delais_relance);
    }

    /**
     * @return array<string, array{list<string>, string}> les délais saisis, et le message attendu
     */
    public static function delaisInvalides(): array
    {
        return [
            'zéro jour' => [['0'], 'Au moins un jour après l\'échéance.'],
            'plus d\'un an' => [['400'], 'Au plus 365 jours après l\'échéance.'],
            'deux fois le même' => [['7', '7'], 'Ce délai est déjà prévu.'],
            'pas un nombre entier' => [['7,5'], 'Un nombre entier de jours.'],
        ];
    }

    /**
     * @param  list<string>  $delais
     */
    #[DataProvider('delaisInvalides')]
    public function test_un_delai_invalide_est_refuse(array $delais, string $message): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresRelances::class)
            ->fillForm(['actif' => true])
            ->set('data.delais', $this->delais(...$delais))
            ->call('save')
            ->assertHasFormErrors(['delais.0.jours' => $message]);

        $this->assertFalse($magasin->fresh()->relances_automatiques);
    }

    /**
     * Le magasin lui-même garde ses délais en ordre, sans doublon, et
     * refuse un délai de moins d'un jour d'où qu'il vienne -- pas seulement
     * depuis cette page.
     */
    public function test_le_magasin_range_ses_delais_et_refuse_un_delai_de_moins_d_un_jour(): void
    {
        $magasin = Magasin::factory()->create();

        $magasin->update(['delais_relance' => ['30', 7, 15, 7]]);
        $this->assertSame([7, 15, 30], $magasin->fresh()->delais_relance);

        $this->assertThrows(
            fn () => $magasin->update(['delais_relance' => [7, 0]]),
            InvalidArgumentException::class,
            'Une relance part au moins un jour après l\'échéance.',
        );
        $this->assertSame([7, 15, 30], $magasin->fresh()->delais_relance);
    }

    public function test_activer_les_relances_sans_aucun_delai_est_refuse(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresRelances::class)
            ->fillForm(['actif' => true])
            ->set('data.delais', [])
            ->call('save')
            ->assertHasFormErrors(['delais' => 'min'])
            ->assertSee('Indiquez au moins un délai de relance.');

        $this->assertFalse($magasin->fresh()->relances_automatiques);
    }

    /**
     * La page complète, avec la cloche des notifications du panneau.
     */
    public function test_le_proprietaire_ouvre_la_page_des_relances(): void
    {
        $magasin = $this->connecterLeProprietaire();

        $this->get(ParametresRelances::getUrl(tenant: $magasin))
            ->assertOk()
            ->assertSee('Relances des factures impayées')
            ->assertSeeLivewire(DatabaseNotifications::class);
    }

    /**
     * Les relances écrivent aux clients au nom du magasin : leur réglage
     * n'est coché d'office que pour l'administrateur, jamais pour un
     * vendeur.
     */
    public function test_un_vendeur_n_accede_pas_aux_relances(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr']);

        $this->actingAs($vendeur)
            ->get(ParametresRelances::getUrl(tenant: $magasin))
            ->assertForbidden();
    }

    /**
     * Les réglages d'un magasin ne touchent jamais ceux d'un autre.
     */
    public function test_les_reglages_restent_ceux_du_magasin_actif(): void
    {
        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresRelances::class)->fillForm(['actif' => true])->set('data.delais', $this->delais('3'))->call('save');

        $this->assertSame([3], $magasin->fresh()->delais_relance);
        $this->assertSame([7, 15, 30], $autreMagasin->fresh()->delais_relance);
        $this->assertFalse($autreMagasin->fresh()->relances_automatiques);
    }

    /**
     * La page, ouverte quand les relances étaient désactivées ; une autre
     * personne les active ; la page est enregistrée sans rien y changer.
     * Rien n'est corrigé, rien ne s'écrit, et la page ne dit pas
     * « désactivées » alors que les relances partiront : elle dit que
     * rien n'a été modifié et montre les relances actives.
     */
    public function test_une_page_qui_n_a_rien_corrige_n_annonce_pas_l_etat_qu_elle_montrait(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresRelances::class)->assertFormSet(['actif' => false]);

        Magasin::query()->findOrFail($magasin->id)->update(['relances_automatiques' => true]);
        $page->call('save')
            ->assertNotified(ParametresRelances::RIEN_N_EST_MODIFIE)
            ->assertFormSet(['actif' => true]);

        $this->assertTrue($magasin->fresh()->relances_automatiques);
    }

    /**
     * La page des relances ne garde que les paramètres qu'elle règle : un
     * vendeur à qui l'on n'a coché que « Régler les relances » n'y lit ni
     * le barème de prix de vente ni les coordonnées du magasin, que
     * règlent d'autres autorisations, pas même dans la page que reçoit son
     * navigateur.
     */
    public function test_la_page_ne_garde_que_les_parametres_des_relances(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::RELANCES_REGLER]));

        $page = Livewire::test(ParametresRelances::class);

        $this->assertSame(['relances_automatiques', 'delais_relance'], array_keys($page->get('parametresLus')));
        $page->assertDontSee('bareme_prix_vente')->assertDontSee('benefice_au_dela');
    }

    /**
     * Les délais tels que les tient le formulaire : un élément du
     * répéteur par délai.
     *
     * @return list<array{jours: string}>
     */
    private function delais(string ...$jours): array
    {
        return array_map(fn (string $delai): array => ['jours' => $delai], $jours);
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * La page, ouverte avant qu'une autre personne ne change les délais de
     * relance, active les relances sans toucher aux délais : ceux de
     * l'autre personne restent, et la page le dit au lieu d'annoncer les
     * délais qu'elle montrait ; corriger aussi les délais qu'elle a
     * changés entre-temps est refusé, et rien ne s'écrit.
     */
    public function test_une_page_ouverte_avant_la_correction_d_une_autre_personne_ne_l_efface_pas(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresRelances::class);
        $autrePage = Livewire::test(ParametresRelances::class);

        Magasin::query()->findOrFail($magasin->id)->update(['delais_relance' => [5, 20]]);
        $page->fillForm(['actif' => true])->call('save')->assertNotified(ParametresRelances::AUTRES_PARAMETRES_CHANGES);
        $this->assertSame([['jours' => 5], ['jours' => 20]], array_values($page->get('data.delais')));
        $autrePage->fillForm(['actif' => true])->set('data.delais', $this->delais('45', '10'))->call('save')->assertNotified(ModifierLesParametresDuMagasinAction::REFUS_PERIMES);

        $magasin->refresh();
        $this->assertSame([true, [5, 20]], [$magasin->relances_automatiques, $magasin->delais_relance]);
    }
}
