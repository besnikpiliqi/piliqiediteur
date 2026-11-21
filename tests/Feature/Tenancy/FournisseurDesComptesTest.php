<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Pages\Auth\Connexion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le compte que désigne une saisie, sur ses deux chemins (voir
 * FournisseurDesComptes) : l'adresse de connexion du propriétaire, qui
 * s'enregistre en minuscules, sans espaces autour, et se cherche ainsi
 * parmi les seuls propriétaires, si bien que la casse tapée n'empêche
 * jamais d'entrer ; l'identifiant de tout compte, écrit avec ou sans
 * espaces, tirets ou points.
 */
class FournisseurDesComptesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_l_adresse_d_un_compte_s_enregistre_en_minuscules_sans_espaces(): void
    {
        $compte = User::factory()->create(['email' => '  Jeanne.Martin@MaBoutique.FR ']);

        $this->assertSame('jeanne.martin@maboutique.fr', $compte->fresh()->email);
    }

    public function test_la_connexion_reussit_quelle_que_soit_la_casse_de_l_adresse(): void
    {
        $proprietaire = User::factory()->create(['email' => 'jeanne@maboutique.fr', 'password' => 'mot-de-passe-de-jeanne']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(Connexion::class)
            ->fillForm(['login' => ' Jeanne@MaBoutique.FR ', 'password' => 'mot-de-passe-de-jeanne'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($proprietaire);
    }

    public function test_un_mot_de_passe_faux_reste_refuse_quelle_que_soit_la_casse_de_l_adresse(): void
    {
        $proprietaire = User::factory()->create(['email' => 'jeanne@maboutique.fr', 'password' => 'mot-de-passe-de-jeanne']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        Livewire::test(Connexion::class)
            ->fillForm(['login' => 'Jeanne@MaBoutique.FR', 'password' => 'mauvais-mot-de-passe'])
            ->call('authenticate')
            ->assertHasErrors(['data.login' => Connexion::MESSAGE_ECHEC]);

        $this->assertGuest();
    }

    /**
     * Les deux chemins : une adresse ne désigne que le propriétaire dont
     * c'est l'adresse de connexion, jamais l'adresse de contact d'un autre
     * compte ; un identifiant désigne son compte, propriétaire compris,
     * quelle que soit la façon de l'écrire ; une saisie d'une autre forme
     * ne désigne rien.
     */
    public function test_une_adresse_ne_designe_qu_un_proprietaire_et_un_identifiant_designe_tout_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], User::factory()->create(['email' => 'jeanne@maboutique.fr']));
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'paul@maboutique.fr']);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $fournisseur = auth()->getProvider();
        $designe = fn (array $saisie): ?int => $fournisseur->retrieveByCredentials([...$saisie, 'password' => 'peu-importe'])?->getAuthIdentifier();

        $this->assertSame($proprietaire->id, $designe(['login' => 'Jeanne@MaBoutique.fr']));
        $this->assertSame($proprietaire->id, $designe(['email' => 'jeanne@maboutique.fr']));
        $this->assertSame($proprietaire->id, $designe(['login' => (string) $proprietaire->identifiantDeConnexion()]));
        foreach ([$identifiant, substr($identifiant, 0, 4).' '.substr($identifiant, 4), substr($identifiant, 0, 4).'-'.substr($identifiant, 4), implode('.', str_split($identifiant, 2))] as $ecriture) {
            $this->assertSame($vendeur->id, $designe(['login' => $ecriture]), $ecriture);
        }
        $this->assertNull($designe(['login' => 'paul@maboutique.fr']));
        $this->assertNull($designe(['email' => 'paul@maboutique.fr']));
        $this->assertNull($designe(['login' => '01234567']));
        $this->assertNull($designe(['login' => 'Paul']));
    }
}
