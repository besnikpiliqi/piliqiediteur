<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\IdentiteMagasin;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IdentiteMagasinTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_enregistre_l_identite_legale_et_les_coordonnees_du_magasin(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->assertFormSet(['nom' => 'Ma Boutique', 'code' => 'MABOUT', 'pays' => 'FR'])
            ->fillForm($this->identiteComplete())
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Identité du magasin enregistrée');

        $this->assertSame($this->identiteComplete(), $magasin->fresh()->only(array_keys($this->identiteComplete())));
    }

    /**
     * @return array<string, array{RoleMagasin}>
     */
    public static function rolesSansAccesALIdentite(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur],
            'vendeur' => [RoleMagasin::Vendeur],
        ];
    }

    /**
     * L'identité imprimée sur tous les documents engage le magasin : elle
     * se règle avec « Paramètres du magasin », qu'aucun modèle de rôle ne
     * coche d'office.
     */
    #[DataProvider('rolesSansAccesALIdentite')]
    public function test_un_membre_sans_les_parametres_du_magasin_n_accede_pas_a_l_identite(RoleMagasin $role): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@ma-boutique.fr', 'password' => 'mot-de-passe-sur'], $role);

        $this->actingAs($membre)
            ->get(IdentiteMagasin::getUrl(tenant: $magasin))
            ->assertForbidden();
    }

    /**
     * Le propriétaire coche « Paramètres du magasin » pour son
     * administrateur : celui-ci règle alors l'identité.
     */
    public function test_un_administrateur_a_qui_le_proprietaire_coche_les_parametres_accede_a_l_identite(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $administrateur = $this->membre($magasin, RoleMagasin::Administrateur, [
            ...RoleMagasin::Administrateur->permissionsParDefaut(),
            PermissionRegistry::PARAMETRES_GERER,
        ]);

        $this->actingAs($administrateur)
            ->get(IdentiteMagasin::getUrl(tenant: $magasin))
            ->assertSee('Identité légale');
    }

    public function test_le_proprietaire_ouvre_la_page_de_l_identite(): void
    {
        $magasin = $this->connecterLeProprietaire();

        $this->get(IdentiteMagasin::getUrl(tenant: $magasin))
            ->assertOk()
            ->assertSee('Identité légale');
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function saisiesInvalides(): array
    {
        return [
            'SIREN de 8 chiffres' => [['siren' => '12345678'], 'siren', 'Le SIREN compte 9 chiffres, sans espace.'],
            'SIRET de 13 chiffres' => [['siren' => '123456789', 'siret' => '1234567890001'], 'siret', 'Le SIRET compte 14 chiffres, sans espace.'],
            'SIRET d\'un autre SIREN' => [['siren' => '123456789', 'siret' => '98765432100012'], 'siret', 'Le SIRET commence par les 9 chiffres du SIREN.'],
            'SIRET sans SIREN' => [['siren' => '', 'siret' => '12345678900012'], 'siren', 'Indiquez le SIREN, par lequel commence le SIRET.'],
            'n° de TVA sans code pays' => [['numero_tva' => '12 123456789'], 'numero_tva', 'Le n° de TVA commence par les deux lettres du pays (FR...), suivies de 2 à 13 chiffres ou lettres ; les espaces, points et tirets ne comptent pas.'],
            'pays hors de France' => [['pays' => 'BE'], 'pays', 'Le logiciel ne gère pour l\'instant que les magasins établis en France.'],
            'code postal en Guyane' => [['code_postal' => '97300'], 'code_postal', 'Le logiciel ne gère pas encore les magasins situés en Guyane, à Mayotte ou dans une collectivité d\'outre-mer.'],
            'code postal à Saint-Martin' => [['code_postal' => '97150'], 'code_postal', 'Le logiciel ne gère pas encore les magasins situés en Guyane, à Mayotte ou dans une collectivité d\'outre-mer.'],
            'capital négatif' => [['capital_social' => '-5'], 'capital_social', 'Le capital social ne peut pas être négatif.'],
            'capital au millième' => [['capital_social' => '10000.555'], 'capital_social', 'Le capital social se saisit au centime près.'],
            'immatriculation de 101 caractères' => [['immatriculation' => str_repeat('R', 101)], 'immatriculation', 'Le champ immatriculation ne doit pas dépasser 100 caractères.'],
            'e-mail sans domaine' => [['email' => 'contact@'], 'email', 'Cette adresse e-mail n\'est pas valide.'],
            'e-mail de 151 caractères' => [['email' => 'contact@'.str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 12).'.fr'], 'email', 'Le champ e-mail ne doit pas dépasser 150 caractères.'],
        ];
    }

    /**
     * @param  array<string, string>  $saisie
     */
    #[DataProvider('saisiesInvalides')]
    public function test_une_saisie_invalide_est_refusee_sans_rien_enregistrer(array $saisie, string $champ, string $message): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm($saisie)
            ->call('save')
            ->assertHasFormErrors([$champ])
            ->assertSee($message);

        $this->assertNull($magasin->fresh()->siret);
        $this->assertNull($magasin->fresh()->code_postal);
        $this->assertSame('FR', $magasin->fresh()->pays);
    }

    /**
     * Le capital social, facultatif, s'enregistre au centime ; vidé, il ne
     * s'imprime plus (H2).
     */
    public function test_le_capital_social_s_enregistre_puis_se_vide(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)->fillForm(['capital_social' => '10000'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('10000.00', $magasin->fresh()->capital_social);

        Livewire::test(IdentiteMagasin::class)
            ->assertFormSet(['capital_social' => '10000.00'])
            ->fillForm(['capital_social' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($magasin->fresh()->capital_social);
    }

    /**
     * Tant qu'il manque une mention de l'identité légale, un encadré en
     * tête de la page dit lesquelles empêchent de facturer un professionnel
     * (B11, K7) ; il disparaît une fois l'identité complète.
     */
    public function test_l_encadre_liste_les_mentions_qui_manquent_pour_facturer_un_professionnel(): void
    {
        $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->assertSee('Identité incomplète pour facturer un professionnel')
            ->assertSee('ces mentions manquent : raison sociale, forme juridique, SIREN, SIRET, immatriculation, adresse, code postal, ville, n° de TVA intracommunautaire.')
            ->fillForm([...$this->identiteComplete(), 'siren' => '', 'siret' => ''])
            ->call('save')
            ->assertSee('ces mentions manquent : SIREN, SIRET.')
            ->fillForm($this->identiteComplete())
            ->call('save')
            ->assertDontSee('Identité incomplète pour facturer un professionnel');
    }

    /**
     * Le code postal donne le territoire de TVA du magasin : à la
     * Martinique, une ligne de service propose 8,5 % (B2).
     */
    public function test_un_magasin_de_la_martinique_propose_8_5_pour_cent_sur_un_service(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $service = Service::factory()->create(['nom' => 'Pose écran']);

        Livewire::test(IdentiteMagasin::class)
            ->fillForm([...$this->identiteComplete(), 'code_postal' => '97200', 'ville' => 'Fort-de-France'])
            ->call('save')
            ->assertHasNoFormErrors();
        $component = Livewire::test(CreateFacture::class)
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$service->id]]);

        $this->assertSame('97200', $magasin->fresh()->code_postal);
        $this->assertSame('8.5', collect($component->get('data.lignes'))->firstWhere('service_id', $service->id)['taux_tva']);
    }

    /**
     * Un établissement n'appartient qu'à un seul magasin du logiciel, même
     * supprimé ; le magasin garde en revanche son propre SIRET d'un
     * enregistrement à l'autre.
     */
    public function test_le_siret_d_un_autre_magasin_meme_supprime_est_refuse(): void
    {
        $autreMagasin = Magasin::factory()->create(['siren' => '123456789', 'siret' => '12345678900012']);
        $autreMagasin->delete();
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['siren' => '123456789', 'siret' => '12345678900012'])
            ->call('save')
            ->assertHasFormErrors(['siret'])
            ->assertSee('Ce SIRET est déjà celui d\'un autre magasin.');

        $this->assertNull($magasin->fresh()->siret);
        $this->assertSame('12345678900012', Magasin::withTrashed()->find($autreMagasin->id)->siret);
    }

    public function test_le_magasin_reenregistre_son_propre_siret(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $magasin->update(['siren' => '123456789', 'siret' => '12345678900012']);

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['raison_sociale' => 'Ma Boutique SARL'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Ma Boutique SARL', $magasin->fresh()->raison_sociale);
        $this->assertSame('12345678900012', $magasin->fresh()->siret);
    }

    /**
     * Un champ vidé ne garde pas une chaîne vide : deux magasins sans SIRET
     * ne se heurtent pas à l'unicité du SIRET.
     */
    public function test_un_champ_vide_est_enregistre_sans_valeur(): void
    {
        Magasin::factory()->create(['siren' => null, 'siret' => null]);
        $magasin = $this->connecterLeProprietaire();
        $magasin->update(['siren' => '123456789', 'siret' => '12345678900012', 'telephone' => '04 91 00 00 00']);

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['siren' => '', 'siret' => '', 'telephone' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $magasin->refresh();
        $this->assertSame([null, null, null], [$magasin->siren, $magasin->siret, $magasin->telephone]);
    }

    /**
     * Le code saisi en minuscules s'enregistre en majuscules, et le
     * formulaire montre ensuite le code enregistré.
     */
    public function test_le_code_saisi_en_minuscules_s_enregistre_en_majuscules(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['code' => 'lyon1'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['code' => 'LYON1']);

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Un magasin français qui tape « fr » n'est pas refusé comme s'il était
     * établi ailleurs : le pays s'enregistre en majuscules, et le
     * formulaire montre ensuite le pays enregistré.
     */
    public function test_le_pays_saisi_en_minuscules_s_enregistre_en_majuscules(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['pays' => 'fr', 'code_postal' => '13002'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['pays' => 'FR']);

        $this->assertSame(['FR', '13002'], [$magasin->fresh()->pays, $magasin->fresh()->code_postal]);
    }

    /**
     * Même règle que pour un client ou un fournisseur : saisi avec ses
     * séparateurs, le n° de TVA s'enregistre sans eux, en majuscules, et
     * le formulaire montre ensuite le n° enregistré.
     */
    public function test_le_numero_de_tva_s_enregistre_sans_separateurs(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['numero_tva' => 'fr 32 123.456.789'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['numero_tva' => 'FR32123456789']);

        $this->assertSame('FR32123456789', $magasin->fresh()->numero_tva);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function codesRefuses(): array
    {
        return [
            'code d\'une lettre' => ['L', 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.'],
            'code avec un tiret' => ['LYON-1', 'Le code compte 2 à 10 lettres ou chiffres, sans espace ni tiret.'],
            'code d\'un autre magasin' => ['LYON1', 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.'],
            'code d\'un magasin supprimé' => ['ANCIEN', 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.'],
        ];
    }

    /**
     * Un code n'appartient qu'à un seul magasin du logiciel, même supprimé.
     */
    #[DataProvider('codesRefuses')]
    public function test_un_code_refuse_n_est_pas_enregistre(string $code, string $message): void
    {
        Magasin::factory()->create(['code' => 'LYON1']);
        Magasin::factory()->create(['code' => 'ANCIEN'])->delete();
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['code' => $code])
            ->call('save')
            ->assertHasFormErrors(['code'])
            ->assertSee($message);

        $this->assertSame('MABOUT', $magasin->fresh()->code);
    }

    /**
     * Dès le premier document numéroté, le code figure dans les numéros :
     * le champ se désactive, et le modèle refuse tout changement.
     */
    public function test_le_code_ne_change_plus_apres_un_premier_devis_numerote(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $this->numeroterUnPremierDevis();

        Livewire::test(IdentiteMagasin::class)
            ->assertFormFieldIsDisabled('code')
            ->assertSee('Il figure dans les numéros de vos documents : il ne change plus.');

        $this->assertThrows(fn () => $magasin->fresh()->update(['code' => 'AUTRE']), LogicException::class, 'Le code du magasin figure déjà dans les numéros de ses documents : il ne change plus.');
        $this->assertSame('MABOUT', $magasin->fresh()->code);
    }

    /**
     * Une référence de produit (PRD-000001) ne porte pas le code : le
     * premier produit ne le rend pas définitif.
     */
    public function test_un_premier_produit_ne_rend_pas_le_code_definitif(): void
    {
        $magasin = $this->connecterLeProprietaire();
        Produit::factory()->create(['reference' => null]);

        Livewire::test(IdentiteMagasin::class)
            ->assertFormFieldIsEnabled('code')
            ->fillForm(['code' => 'LYON1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Un premier document numéroté pendant que la page est ouverte : le
     * code saisi n'est pas enregistré, le reste de l'identité l'est, et le
     * formulaire montre le code enregistré, désormais définitif.
     */
    public function test_un_code_devenu_definitif_pendant_la_saisie_n_est_pas_enregistre(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(IdentiteMagasin::class)->assertFormFieldIsEnabled('code');
        $this->numeroterUnPremierDevis();

        $page->fillForm(['code' => 'AUTRE', 'raison_sociale' => 'Ma Boutique SARL'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['code' => 'MABOUT'])
            ->assertFormFieldIsDisabled('code');

        $this->assertSame(['MABOUT', 'Ma Boutique SARL'], [$magasin->fresh()->code, $magasin->fresh()->raison_sociale]);
    }

    /**
     * Un magasin qui n'a encore rien vendu ne numérote aucun document la
     * nuit, ni clôture de caisse ni déclaration d'e-reporting : son code
     * reste modifiable jusqu'à son premier document.
     */
    public function test_un_magasin_sans_vente_garde_son_code_modifiable_apres_les_commandes_de_la_nuit(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->connecterLeProprietaire();
        $magasin->update(['regime_tva' => RegimeTva::ReelSimplifie]);
        $this->travelTo('2026-10-10 01:00:00');
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->artisan('app:soumettre-ereporting-periodique')->assertSuccessful();

        Livewire::test(IdentiteMagasin::class)
            ->assertFormFieldIsEnabled('code')
            ->fillForm(['code' => 'LYON1'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('LYON1', $magasin->fresh()->code);
    }

    /**
     * Seuls les documents du magasin rendent son code définitif : le
     * premier document d'un autre magasin ne le verrouille pas.
     */
    public function test_le_premier_document_d_un_autre_magasin_ne_rend_pas_le_code_definitif(): void
    {
        $autreMagasin = app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $magasin = $this->connecterLeProprietaire();
        app(TenantContext::class)->runAs($autreMagasin, fn () => $this->numeroterUnPremierDevis());

        Livewire::test(IdentiteMagasin::class)->assertFormFieldIsEnabled('code');
        $magasin->fresh()->update(['code' => 'LYON3']);

        $this->assertSame('LYON3', $magasin->fresh()->code);
        $this->assertTrue($autreMagasin->fresh()->codeEstDefinitif());
    }

    /**
     * @return array<string, array{array<string, string>, string, string}>
     */
    public static function valeursPrisesPendantLEnregistrement(): array
    {
        return [
            'code' => [['code' => 'LYON1'], 'code', 'Ce code est déjà utilisé : choisissez-en un autre, par exemple LYON2.'],
            'SIRET' => [['siren' => '123456789', 'siret' => '12345678900012'], 'siret', 'Ce SIRET est déjà celui d\'un autre magasin.'],
        ];
    }

    /**
     * Un code ou un SIRET, libre à la validation, pris par un autre magasin
     * juste avant l'enregistrement : l'écran le refuse comme la validation,
     * sans erreur du serveur, et rien n'est enregistré.
     *
     * @param  array<string, string>  $saisie
     */
    #[DataProvider('valeursPrisesPendantLEnregistrement')]
    public function test_une_valeur_prise_pendant_l_enregistrement_est_refusee_comme_par_la_validation(array $saisie, string $champ, string $message): void
    {
        $magasin = $this->connecterLeProprietaire();
        $concurrentEnCours = false;
        Magasin::updating(function () use ($saisie, &$concurrentEnCours): void {
            if (! $concurrentEnCours) {
                $concurrentEnCours = true;
                Magasin::factory()->create($saisie);
            }
        });

        Livewire::test(IdentiteMagasin::class)
            ->fillForm([...$saisie, 'raison_sociale' => 'Ma Boutique SARL'])
            ->call('save')
            ->assertHasFormErrors([$champ])
            ->assertSee($message);

        $this->assertSame(['MABOUT', null, null], [$magasin->fresh()->code, $magasin->fresh()->siret, $magasin->fresh()->raison_sociale]);
    }

    /**
     * L'identité d'un magasin ne touche jamais celle d'un autre.
     */
    public function test_l_identite_reste_celle_du_magasin_actif(): void
    {
        $autreMagasin = app(CreerMagasinAction::class)->handle(['nom' => 'Autre boutique'], User::factory()->create());
        $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)->fillForm($this->identiteComplete())->call('save');

        $autreMagasin->refresh();
        $this->assertSame('Autre boutique', $autreMagasin->nom);
        $this->assertNull($autreMagasin->raison_sociale);
        $this->assertNull($autreMagasin->siret);
    }

    /**
     * @return array<string, string>
     */
    private function identiteComplete(): array
    {
        return [
            'nom' => 'Boutique du Port',
            'code' => 'PORT1',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'siren' => '123456789',
            'siret' => '12345678900012',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR32123456789',
            'adresse' => '12 quai des Pêcheurs',
            'complement_adresse' => 'Bâtiment B',
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'pays' => 'FR',
            'email' => 'contact@boutique-du-port.fr',
            'telephone' => '04 91 00 00 00',
        ];
    }

    /**
     * Un SIREN que portent déjà d'autres magasins ne s'enregistre qu'avec
     * les réglages communs du magasin principal de leur société (J2, K5).
     * Le magasin qui le saisit n'en est pas encore : chaque champ commun
     * en écart le signale et le SIREN nomme ce qui diffère, sans aucune
     * valeur du principal. Rien n'est enregistré.
     */
    public function test_un_siren_deja_porte_ne_s_enregistre_qu_avec_les_reglages_du_principal_de_la_societe_sans_les_reveler(): void
    {
        $this->principalDeLaSociete(['immatriculation' => 'RCS Villefranche-sur-Saône']);
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(IdentiteMagasin::class)
            ->fillForm([...$this->identiteComplete(), 'siren' => '732829320', 'siret' => '73282932000082', 'numero_tva' => 'FR44732829320', 'forme_juridique' => 'SARL'])
            ->call('save')
            ->assertHasFormErrors(['raison_sociale', 'capital_social', 'immatriculation', 'siren'])
            ->assertHasNoFormErrors(['forme_juridique', 'numero_tva'])
            ->assertSee('Réglage commun aux magasins de cette société, fixé par son magasin principal : le sien est différent.')
            ->assertSee('Avant d\'indiquer ce SIREN, prenez les réglages communs du magasin principal de cette société : régime de TVA (Paramètres fiscaux), raison sociale (Identité du magasin), capital social (Identité du magasin), immatriculation (Identité du magasin).')
            ->assertDontSee('Dupont SARL')
            ->assertDontSee('10 000,00')
            ->assertDontSee('Villefranche-sur-Saône')
            ->assertDontSee('Réel normal');

        $this->assertSame([null, 'Ma Boutique'], [$magasin->fresh()->siren, $magasin->fresh()->nom]);
    }

    /**
     * Un magasin qui prend le SIREN d'une société n'a que dix refus par
     * heure : le onzième essai est refusé, même avec tous les réglages du
     * principal, pour que le contrôle ne serve pas à deviner ceux d'une
     * autre société. Une heure plus tard, il rejoint la société.
     */
    public function test_le_onzieme_essai_dans_l_heure_de_prendre_le_siren_d_une_societe_est_refuse(): void
    {
        $this->principalDeLaSociete();
        $magasin = $this->connecterLeProprietaire();
        app(TenantContext::class)->runAs($magasin, fn () => $magasin->update(['regime_tva' => RegimeTva::ReelNormalMensuel]));
        $page = Livewire::test(IdentiteMagasin::class);
        $avecLeSirenDeLaSociete = [...$this->identiteComplete(), 'siren' => '732829320', 'siret' => '73282932000082'];
        $avecLesReglagesDuPrincipal = [...$avecLeSirenDeLaSociete, 'raison_sociale' => 'Dupont SARL', 'capital_social' => '10000', 'immatriculation' => 'RCS Lyon', 'numero_tva' => 'FR44732829320'];

        for ($essai = 1; $essai <= 10; $essai++) {
            $page->fillForm($avecLeSirenDeLaSociete)
                ->call('save')
                ->assertSee('Avant d\'indiquer ce SIREN, prenez les réglages communs du magasin principal de cette société : n° de TVA intracommunautaire (Identité du magasin), raison sociale (Identité du magasin), capital social (Identité du magasin), immatriculation (Identité du magasin).');
        }

        $page->fillForm($avecLesReglagesDuPrincipal)
            ->call('save')
            ->assertHasFormErrors(['siren'])
            ->assertSee('Trop d\'essais : réessayez dans une heure.');
        $this->assertNull($magasin->fresh()->siren);

        $this->travel(1)->hour();
        $page->fillForm($avecLesReglagesDuPrincipal)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('732829320', $magasin->fresh()->siren);
    }

    /**
     * Tant que le magasin principal n'a pas choisi son régime de TVA, un
     * magasin qui a déjà le sien rejoint la société sans avoir à le vider,
     * ce que Paramètres fiscaux ne permet pas.
     */
    public function test_un_magasin_rejoint_une_societe_dont_le_principal_n_a_pas_encore_choisi_son_regime(): void
    {
        $this->principalDeLaSociete(['regime_tva' => null]);
        $magasin = $this->connecterLeProprietaire();
        app(TenantContext::class)->runAs($magasin, fn () => $magasin->update(['regime_tva' => RegimeTva::ReelNormalMensuel]));

        Livewire::test(IdentiteMagasin::class)
            ->fillForm([...$this->identiteComplete(), 'siren' => '732829320', 'siret' => '73282932000082', 'raison_sociale' => 'Dupont SARL', 'forme_juridique' => 'SARL', 'capital_social' => '10000', 'immatriculation' => 'RCS Lyon', 'numero_tva' => 'FR44732829320'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['732829320', RegimeTva::ReelNormalMensuel], [$magasin->fresh()->siren, $magasin->fresh()->regime_tva]);
    }

    /**
     * Les valeurs du principal se reprennent sans souci de la casse, des
     * espaces ou des séparateurs du n° de TVA.
     */
    public function test_un_siren_deja_porte_s_enregistre_avec_les_reglages_du_principal_de_la_societe(): void
    {
        $this->principalDeLaSociete();
        $magasin = $this->connecterLeProprietaire();
        app(TenantContext::class)->runAs($magasin, fn () => $magasin->update(['regime_tva' => RegimeTva::ReelNormalMensuel]));

        Livewire::test(IdentiteMagasin::class)
            ->fillForm([
                ...$this->identiteComplete(),
                'siren' => '732829320',
                'siret' => '73282932000082',
                'raison_sociale' => 'dupont sarl',
                'forme_juridique' => 'SARL',
                'capital_social' => '10000',
                'immatriculation' => 'RCS  Lyon',
                'numero_tva' => 'FR 44 732 829 320',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['732829320', 'dupont sarl', '10000.00'], [$magasin->fresh()->siren, $magasin->fresh()->raison_sociale, $magasin->fresh()->capital_social]);
    }

    /**
     * Un magasin de la société ne renseigne pas un réglage commun que le
     * principal n'a pas : la page lui dit de le laisser vide.
     */
    public function test_un_reglage_commun_que_le_principal_n_a_pas_se_laisse_vide(): void
    {
        $principal = $this->principalDeLaSociete(['capital_social' => null]);
        $magasin = $this->connecterUnMagasinDeLaSociete($principal);

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['capital_social' => '5000'])
            ->call('save')
            ->assertHasFormErrors(['capital_social'])
            ->assertSee('Réglage commun à tous les magasins de votre société (SIREN 732829320), fixé par son magasin principal, qui ne l\'a pas renseigné : laissez-le vide.');

        $this->assertNull($magasin->fresh()->capital_social);
    }

    /**
     * Le principal a changé de régime de TVA et de raison sociale : la
     * page dit au magasin laissé derrière lui ce qu'il doit aligner, et
     * pourquoi il n'émet plus rien.
     */
    public function test_l_encadre_dit_les_reglages_a_aligner_sur_le_principal(): void
    {
        $principal = $this->principalDeLaSociete();
        $this->connecterUnMagasinDeLaSociete($principal);
        app(TenantContext::class)->runAs($principal, fn () => $principal->update(['regime_tva' => RegimeTva::ReelNormalTrimestriel, 'raison_sociale' => 'Dupont Frères']));

        Livewire::test(IdentiteMagasin::class)
            ->assertSee('Réglages à aligner sur le magasin principal de votre société')
            ->assertSee('Ce magasin n\'émet ni facture ni ticket tant que ses réglages communs ne sont pas ceux du magasin principal de votre société (SIREN 732829320) ; à prendre : régime de TVA « Réel normal (déclaration trimestrielle) » (Paramètres fiscaux), raison sociale « Dupont Frères » (Identité du magasin).');
    }

    /**
     * Laissé derrière par son principal, le magasin n'enregistre son
     * identité qu'avec la raison sociale du principal ; son régime de TVA,
     * qui ne se règle pas sur cette page, n'empêche pas de l'enregistrer.
     */
    public function test_un_magasin_laisse_derriere_enregistre_son_identite_avec_les_valeurs_du_principal(): void
    {
        $principal = $this->principalDeLaSociete();
        $magasin = $this->connecterUnMagasinDeLaSociete($principal);
        app(TenantContext::class)->runAs($principal, fn () => $principal->update(['regime_tva' => RegimeTva::ReelNormalTrimestriel, 'raison_sociale' => 'Dupont Frères']));

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['telephone' => '04 72 00 00 00'])
            ->call('save')
            ->assertHasFormErrors(['raison_sociale'])
            ->assertHasNoFormErrors(['siren'])
            ->fillForm(['raison_sociale' => 'Dupont Frères'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['Dupont Frères', '04 72 00 00 00', RegimeTva::ReelNormalMensuel], [$magasin->fresh()->raison_sociale, $magasin->fresh()->telephone, $magasin->fresh()->regime_tva]);
    }

    /**
     * Laissé derrière par son principal, le magasin n'efface pas son SIREN
     * : le SIREN dit ce qu'il doit d'abord prendre, et rien n'est
     * enregistré.
     */
    public function test_un_magasin_laisse_derriere_n_efface_pas_son_siren(): void
    {
        $principal = $this->principalDeLaSociete();
        $magasin = $this->connecterUnMagasinDeLaSociete($principal);
        app(TenantContext::class)->runAs($principal, fn () => $principal->update(['regime_tva' => RegimeTva::ReelNormalTrimestriel]));

        Livewire::test(IdentiteMagasin::class)
            ->fillForm(['siren' => null, 'siret' => null])
            ->call('save')
            ->assertHasFormErrors(['siren'])
            ->assertSee('Ce magasin ne quitte pas votre société (SIREN 732829320) tant qu\'il n\'a pas enregistré les réglages communs de son magasin principal ; à prendre d\'abord : régime de TVA « Réel normal (déclaration trimestrielle) » (Paramètres fiscaux).');

        $this->assertSame(['732829320', '73282932000082'], [$magasin->fresh()->siren, $magasin->fresh()->siret]);
    }

    /**
     * Un doublon qui n'est ni celui du code ni celui du SIRET n'est
     * attribué à aucun champ : l'écran ne répond pas à tort « Ce code est
     * déjà utilisé », et rien n'est enregistré.
     */
    public function test_un_autre_doublon_n_est_pas_attribue_au_code(): void
    {
        $autreMagasin = Magasin::factory()->create();
        $magasin = $this->connecterLeProprietaire();
        Magasin::updating(fn (Magasin $enregistre) => $enregistre->forceFill(['uuid' => $autreMagasin->uuid]));

        $this->assertThrows(
            fn () => Livewire::test(IdentiteMagasin::class)->fillForm(['telephone' => '04 72 00 00 00'])->call('save'),
            UniqueConstraintViolationException::class,
        );

        $this->assertNull($magasin->fresh()->telephone);
    }

    /**
     * Le magasin principal de la société SIREN 732829320, réel normal
     * mensuel.
     *
     * @param  array<string, mixed>  $reglages
     */
    private function principalDeLaSociete(array $reglages = []): Magasin
    {
        return Magasin::factory()->create([
            'siren' => '732829320',
            'siret' => '73282932000074',
            'raison_sociale' => 'Dupont SARL',
            'forme_juridique' => 'SARL',
            'capital_social' => 10000,
            'immatriculation' => 'RCS Lyon',
            'numero_tva' => 'FR44732829320',
            'regime_tva' => RegimeTva::ReelNormalMensuel,
            ...$reglages,
        ]);
    }

    /**
     * Un autre magasin de la société du principal, avec ses réglages
     * communs, dont le propriétaire est connecté.
     */
    private function connecterUnMagasinDeLaSociete(Magasin $principal): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle([
            'nom' => 'Ma Boutique',
            'siren' => '732829320',
            'siret' => '73282932000082',
            ...$principal->only(['regime_tva', 'option_debits_tva', 'numero_tva', 'raison_sociale', 'forme_juridique', 'capital_social', 'immatriculation']),
        ], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    private function numeroterUnPremierDevis(): void
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        app(EmettreDevisAction::class)->handle($devis);
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
