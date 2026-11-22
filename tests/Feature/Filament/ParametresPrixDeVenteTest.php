<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Catalogue\Services\ExemplesDArrondi;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Support\FicheLue;
use App\Filament\App\Pages\ParametresPrixDeVente;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\ExemplesDArrondiTest;

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
            ->assertFormSet(['actif' => false, 'benefice_au_dela' => 60.0, 'arrondi' => false, 'formule' => 'x9'])
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
            ->fillForm(['actif' => true, 'benefice_au_dela' => 80, 'arrondi' => true, 'formule' => 'euro'])
            ->call('save')
            ->assertHasNoFormErrors();

        $bareme = $magasin->fresh()->baremePrixDeVenteActif();
        $this->assertSame(BaremePrixDeVente::parDefaut()->paliers, $bareme->paliers);
        $this->assertSame([8000, ArrondiDuPrixDeVente::EuroLePlusProche], [$bareme->beneficeAuDela, $bareme->arrondi]);
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

        $this->assertSame([['jusqu_a' => 1000, 'benefice' => 2000]], $magasin->fresh()->baremePrixDeVenteActif()->paliers);
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
            ['jusqu_a' => 500, 'benefice' => 1200],
            ['jusqu_a' => 5000, 'benefice' => 2500],
            ['jusqu_a' => 20000, 'benefice' => 4000],
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

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => 'x9', 'essai_prix_achat_ht' => '0.47', 'essai_taux_tva' => '20'])
            ->assertSee(['Prix de vente TTC avant arrondi', 'Prix de vente TTC arrondi'])
            ->assertSee('Bénéfice réel 7,03 € HT (visé 10,00 €).')
            ->assertSee('(0,47 € + 10,00 €) × (1 + 20 %) = 12,56 €');

        $this->assertSame(['12,56 €', '9,00 €'], [self::prixDeLEssai($page, 'avant'), self::prixDeLEssai($page, 'apres')]);
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

    /**
     * Un montant du barème s'écrit au centime : un seuil, un bénéfice ou
     * un bénéfice au-delà à trois décimales est refusé sous son champ,
     * jamais arrondi en silence, et rien ne s'enregistre ; tant qu'il est
     * dans la page, l'essai ne propose aucun prix (1 € d'achat sous le
     * seuil de 2,555 € ne vaut pas (1 + 1) x 1,2 = 2,40 € TTC), puis le
     * propose une fois le seuil écrit au centime.
     */
    public function test_un_montant_du_bareme_a_trois_decimales_est_refuse_et_l_essai_ne_propose_aucun_prix(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'benefice_au_dela' => '60.001', 'essai_prix_achat_ht' => '1', 'essai_taux_tva' => '20'])
            ->set('data.paliers', [['jusqu_a' => '2.555', 'benefice' => '1'], ['jusqu_a' => '50', 'benefice' => '0.125']]);

        $page->assertDontSee('Bénéfice réel')
            ->call('save')
            ->assertHasFormErrors([
                'paliers.0.jusqu_a' => SaisieDecimale::REFUS_MONTANT_TROP_PRECIS,
                'paliers.1.benefice' => SaisieDecimale::REFUS_MONTANT_TROP_PRECIS,
                'benefice_au_dela' => SaisieDecimale::REFUS_MONTANT_TROP_PRECIS,
            ]);
        $page->set('data.paliers', [['jusqu_a' => '2.55', 'benefice' => '1'], ['jusqu_a' => '50', 'benefice' => '0.12']])
            ->set('data.benefice_au_dela', '60')
            ->assertSee('(1,00 € + 1,00 €) × (1 + 20 %) = 2,40 €');

        $magasin->refresh();
        $this->assertFalse($magasin->prix_vente_automatique);
        $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->bareme_prix_vente);
    }

    /**
     * Le plus petit prix d'achat d'un palier, 0,01 €, s'enregistre quel que
     * soit le réglage de PHP qui écrit les nombres à virgule flottante
     * (precision) : la borne du champ s'écrit en texte, jamais
     * « 0.0100000000000000002 ».
     */
    public function test_un_palier_a_un_centime_s_enregistre_quel_que_soit_le_reglage_de_php(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $precision = ini_get('precision');

        try {
            ini_set('precision', '18');

            Livewire::test(ParametresPrixDeVente::class)
                ->fillForm(['actif' => true, 'benefice_au_dela' => '60'])
                ->set('data.paliers', [['jusqu_a' => '0.01', 'benefice' => '1']])
                ->call('save')
                ->assertHasNoFormErrors();
        } finally {
            ini_set('precision', (string) $precision);
        }

        $this->assertSame([['jusqu_a' => 1, 'benefice' => 100]], $magasin->fresh()->baremePrixDeVenteActif()->paliers);
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

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['essai_prix_achat_ht' => '0.47', 'essai_taux_tva' => ''])
            ->assertDontSee('10,47 €')
            ->assertDontSee('Bénéfice réel');
        $this->assertSame('—', self::prixDeLEssai($page, 'apres'));

        $page->fillForm(['essai_taux_tva' => '20'])->assertSee('Prix de vente TTC (sans arrondi)');
        $this->assertSame('12,56 €', self::prixDeLEssai($page, 'apres'));
    }

    public function test_l_essai_du_bareme_part_d_une_tva_de_20_pourcent(): void
    {
        $this->connecterLeProprietaire();

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->assertFormSet(['essai_taux_tva' => 20])
            ->fillForm(['essai_prix_achat_ht' => '0.47'])
            ->assertSee('Prix de vente TTC (sans arrondi)');

        $this->assertSame('12,56 €', self::prixDeLEssai($page, 'apres'));
    }

    public function test_un_vendeur_sans_la_permission_ne_peut_pas_acceder_a_la_page(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(ParametresPrixDeVente::canAccess());
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
     * La page, ouverte quand le calcul était désactivé ; une autre
     * personne l'active ; la page est enregistrée sans rien y changer.
     * Rien ne s'écrit, et la page ne dit pas « désactivé » alors que le
     * calcul reste actif : elle dit que rien n'a été modifié et le montre
     * actif.
     */
    public function test_une_page_qui_n_a_rien_corrige_n_annonce_pas_l_etat_qu_elle_montrait(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresPrixDeVente::class)->assertFormSet(['actif' => false]);

        Magasin::query()->findOrFail($magasin->id)->update(['prix_vente_automatique' => true]);
        $page->call('save')
            ->assertNotified(ParametresPrixDeVente::RIEN_N_EST_MODIFIE)
            ->assertFormSet(['actif' => true]);

        $this->assertTrue($magasin->fresh()->prix_vente_automatique);
    }

    /**
     * La page, ouverte avant qu'une autre personne n'enregistre son propre
     * barème, active le calcul sans toucher au barème : celui de l'autre
     * personne reste, et la page le dit au lieu d'annoncer le barème
     * qu'elle montrait ; corriger aussi le barème qu'elle a changé
     * entre-temps est refusé, et rien ne s'écrit.
     */
    public function test_une_page_ouverte_avant_la_correction_d_une_autre_personne_ne_l_efface_pas(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresPrixDeVente::class);
        $autrePage = Livewire::test(ParametresPrixDeVente::class);
        $bareme = new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 500]], 2000, ArrondiDuPrixDeVente::Aucun);

        Magasin::query()->findOrFail($magasin->id)->update(['bareme_prix_vente' => $bareme]);
        $page->fillForm(['actif' => true])->call('save')->assertNotified(ParametresPrixDeVente::AUTRES_PARAMETRES_CHANGES)->assertFormSet(['benefice_au_dela' => 20.0]);
        $autrePage->fillForm(['actif' => true, 'benefice_au_dela' => 80])->call('save')->assertNotified(ModifierLesParametresDuMagasinAction::REFUS_PERIMES);

        $this->assertTrue($magasin->fresh()->prix_vente_automatique);
        $this->assertEquals($bareme, $magasin->fresh()->bareme_prix_vente);
    }

    /**
     * Les lignes du tableau d'exemples que la page montre, en texte.
     *
     * @param  Testable<ParametresPrixDeVente>  $page
     * @return list<array{string, string, string}>
     */
    private static function lignesDuTableau(Testable $page): array
    {
        preg_match_all('/<tr[^>]*data-arrondi="ligne"[^>]*>(.*?)<\/tr>/s', $page->html(), $lignes);

        return array_map(function (string $ligne): array {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/s', $ligne, $cellules);

            return array_map(trim(...), $cellules[1]);
        }, $lignes[1]);
    }

    /**
     * Le prix que l'essai montre sous cette étiquette (data-arrondi).
     *
     * @param  Testable<ParametresPrixDeVente>  $page
     */
    private static function prixDeLEssai(Testable $page, string $quel): ?string
    {
        return preg_match('/data-arrondi="'.$quel.'">([^<]*)</', $page->html(), $prix) === 1 ? trim($prix[1]) : null;
    }

    /**
     * Le choix de la formule n'existe que case cochée ; décochée, la page ne
     * montre ni formule ni exemples, et la formule choisie est gardée pour
     * le jour où la case est recochée.
     */
    public function test_le_choix_de_la_formule_ne_se_montre_que_la_case_cochee(): void
    {
        $this->connecterLeProprietaire();

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->assertFormSet(['arrondi' => false])
            ->assertDontSee('Formule d\'arrondi')
            ->assertDontSee('Exemples de la formule choisie')
            ->assertSee('Case décochée : aucun arrondi')
            ->fillForm(['actif' => true, 'arrondi' => true])
            ->assertSee('Formule d\'arrondi')
            ->assertSee(['Un prix en 9', 'L\'euro le plus proche', 'Le multiple de 5 € le plus proche'])
            ->assertSee(array_values(ExemplesDArrondi::phrases()))
            ->assertSee('Exemples de la formule choisie')
            ->assertDontSee('Case décochée');

        $page->fillForm(['formule' => 'cinq'])
            ->fillForm(['arrondi' => false])
            ->assertDontSee('Formule d\'arrondi')
            ->assertSee('Case décochée : aucun arrondi')
            ->assertFormSet(['formule' => 'cinq'])
            ->fillForm(['arrondi' => true])
            ->assertFormSet(['formule' => 'cinq']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formules(): array
    {
        return ['un prix en 9' => ['x9'], 'l\'euro le plus proche' => ['euro'], 'le multiple de 5 €' => ['cinq']];
    }

    #[DataProvider('formules')]
    public function test_chaque_formule_s_enregistre_et_se_relit(string $formule): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => $formule])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Barème de prix de vente enregistré');

        $this->assertSame(ArrondiDuPrixDeVente::from($formule), $magasin->fresh()->baremePrixDeVenteActif()->arrondi);

        Livewire::test(ParametresPrixDeVente::class)->assertFormSet(['actif' => true, 'arrondi' => true, 'formule' => $formule]);
    }

    public function test_la_case_decochee_enregistre_aucun_arrondi_et_garde_le_reste(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => 'euro'])
            ->call('save');
        Livewire::test(ParametresPrixDeVente::class)
            ->assertFormSet(['arrondi' => true, 'formule' => 'euro'])
            ->fillForm(['arrondi' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $bareme = $magasin->fresh()->baremePrixDeVenteActif();
        $this->assertSame(ArrondiDuPrixDeVente::Aucun, $bareme->arrondi);
        $this->assertSame(BaremePrixDeVente::parDefaut()->paliers, $bareme->paliers);
        $this->assertSame(6000, $bareme->beneficeAuDela);

        Livewire::test(ParametresPrixDeVente::class)->assertFormSet(['arrondi' => false, 'formule' => 'x9']);
    }

    /**
     * Une formule qui n'existe pas, ou « aucun » avec la case cochée, est
     * refusée sous le champ et rien ne s'écrit ; case décochée, le champ
     * masqué n'est pas lu, même forgé.
     */
    public function test_une_formule_inconnue_est_refusee(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresPrixDeVente::class)->fillForm(['actif' => true, 'arrondi' => true]);

        foreach (['dix', 'aucun'] as $formule) {
            $page->set('data.formule', $formule)->call('save')->assertHasFormErrors(['formule']);
            $this->assertFalse($magasin->fresh()->prix_vente_automatique);
            $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->fresh()->bareme_prix_vente);
        }

        $page->set('data.formule', 'dix')->fillForm(['arrondi' => false])->call('save')->assertHasNoFormErrors();

        $this->assertTrue($magasin->fresh()->prix_vente_automatique);
        $this->assertSame(ArrondiDuPrixDeVente::Aucun, $magasin->fresh()->bareme_prix_vente->arrondi);
    }

    /**
     * @return array<string, array{string, string, list<array{string, string, string}>}>
     */
    public static function formulesEtLeurEssai(): array
    {
        $tableaux = ExemplesDArrondiTest::tableaux();

        return [
            'un prix en 9' => ['x9', '9,00 €', $tableaux['un prix en 9'][1]],
            'l\'euro le plus proche' => ['euro', '13,00 €', $tableaux['l\'euro le plus proche'][1]],
            'le multiple de 5 €' => ['cinq', '15,00 €', $tableaux['le multiple de 5 €'][1]],
        ];
    }

    /**
     * @param  list<array{string, string, string}>  $tableau
     */
    #[DataProvider('formulesEtLeurEssai')]
    public function test_l_essai_montre_le_prix_avant_et_apres_l_arrondi_et_le_tableau_d_exemples(string $formule, string $apres, array $tableau): void
    {
        $this->connecterLeProprietaire();

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => $formule, 'essai_prix_achat_ht' => '0.47', 'essai_taux_tva' => '20'])
            ->assertSee('Exemples de la formule choisie')
            ->assertSee(ExemplesDArrondi::legende(ArrondiDuPrixDeVente::from($formule)))
            ->assertDontSee('Prix de vente TTC (sans arrondi)');

        $this->assertSame(['12,56 €', $apres], [self::prixDeLEssai($page, 'avant'), self::prixDeLEssai($page, 'apres')]);
        $this->assertSame($tableau, self::lignesDuTableau($page));

        $page->fillForm(['arrondi' => false])
            ->assertSee('Prix de vente TTC (sans arrondi)')
            ->assertDontSee('Prix de vente TTC avant arrondi');
        $this->assertSame(['12,56 €', []], [self::prixDeLEssai($page, 'apres'), self::lignesDuTableau($page)]);
    }

    /**
     * Case cochée, une saisie illisible (un seuil à trois décimales) ne fait
     * pas oublier la case : l'essai garde « avant arrondi », « arrondi » et
     * le tableau d'exemples de la formule choisie, et dit de saisir un
     * barème lisible, sans jamais écrire « Case décochée ».
     */
    public function test_case_cochee_une_saisie_illisible_garde_les_exemples_de_la_formule(): void
    {
        $this->connecterLeProprietaire();

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => 'euro', 'essai_prix_achat_ht' => '1', 'essai_taux_tva' => '20'])
            ->set('data.paliers', [['jusqu_a' => '2.555', 'benefice' => '1']])
            ->assertSee('Exemples de la formule choisie')
            ->assertSee('Prix de vente TTC avant arrondi')
            ->assertSee('Prix de vente TTC arrondi')
            ->assertSee('Saisissez un prix d\'achat HT')
            ->assertDontSee('Case décochée')
            ->assertDontSee('Prix de vente TTC (sans arrondi)');

        $this->assertSame(['—', '—'], [self::prixDeLEssai($page, 'avant'), self::prixDeLEssai($page, 'apres')]);
        $this->assertSame(ExemplesDArrondiTest::tableaux()['l\'euro le plus proche'][1], self::lignesDuTableau($page));
    }

    /**
     * Le milieu se juge sur le prix exact : (4,7467 € + 4 €) x 1,2 =
     * 10,49604 €, qui s'écrit 10,50 € au centime, devient 10 € à l'euro
     * près, et la page le dit.
     */
    public function test_le_prix_exact_se_juge_avant_le_prix_au_centime_dans_l_essai(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $magasin->update(['prix_vente_automatique' => true, 'bareme_prix_vente' => new BaremePrixDeVente([['jusqu_a' => 1000, 'benefice' => 400]], null, ArrondiDuPrixDeVente::EuroLePlusProche)]);

        $page = Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['essai_prix_achat_ht' => '4.7467', 'essai_taux_tva' => '20'])
            ->assertSee('Le prix exact est 10,49604 € ; l\'arrondi se fait sur ce prix exact, pas sur 10,50 €.');

        $this->assertSame(['10,50 €', '10,00 €'], [self::prixDeLEssai($page, 'avant'), self::prixDeLEssai($page, 'apres')]);
    }

    /**
     * Changer la formule passe par le journal d'audit de la société, dans la
     * chaîne du magasin, avec les anciennes et les nouvelles valeurs des
     * seuls paramètres corrigés ; enregistrer sans rien changer n'inscrit
     * rien.
     */
    public function test_changer_la_formule_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => 'euro'])
            ->call('save')
            ->assertHasNoFormErrors();

        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::ParametresDuMagasinModifies);
        $this->assertSame($magasin->id, $entree->magasin_id);
        $this->assertSame(['bareme_prix_vente', 'prix_vente_automatique'], collect(array_keys($entree->nouvelles_valeurs))->sort()->values()->all());
        $this->assertSame(['aucun', false], [$entree->anciennes_valeurs['bareme_prix_vente']['arrondi'], $entree->anciennes_valeurs['prix_vente_automatique']]);
        $this->assertSame(['euro', true], [$entree->nouvelles_valeurs['bareme_prix_vente']['arrondi'], $entree->nouvelles_valeurs['prix_vente_automatique']]);

        Livewire::test(ParametresPrixDeVente::class)->call('save')->assertNotified('Barème de prix de vente enregistré');
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::ParametresDuMagasinModifies));

        Livewire::test(ParametresPrixDeVente::class)->fillForm(['formule' => 'cinq'])->call('save');
        [, $deuxieme] = $this->entreesDAudit($magasin->societe, ActionAuditee::ParametresDuMagasinModifies);
        $this->assertSame(['bareme_prix_vente'], array_keys($deuxieme->nouvelles_valeurs));
        $this->assertSame(['euro', 'cinq'], [$deuxieme->anciennes_valeurs['bareme_prix_vente']['arrondi'], $deuxieme->nouvelles_valeurs['bareme_prix_vente']['arrondi']]);
    }

    public function test_une_personne_sans_l_autorisation_ne_change_pas_la_formule(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $this->dansLeMagasin($magasin, $vendeur);

        $this->assertThrows(
            fn () => app(ModifierLesParametresDuMagasinAction::class)->handle($vendeur, PermissionRegistry::PRIX_VENTE_REGLER, FicheLue::de($magasin->fresh())->valeurs(), [
                'prix_vente_automatique' => true,
                'bareme_prix_vente' => new BaremePrixDeVente(BaremePrixDeVente::parDefaut()->paliers, 6000, ArrondiDuPrixDeVente::EuroLePlusProche),
            ]),
            AuthorizationException::class,
        );

        $this->assertFalse($magasin->fresh()->prix_vente_automatique);
        $this->assertEquals(BaremePrixDeVente::parDefaut(), $magasin->fresh()->bareme_prix_vente);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::ParametresDuMagasinModifies));
    }

    /**
     * Isolation : la formule est un réglage du magasin, jamais de la
     * société ; celle d'un magasin ne change ni la page, ni le barème, ni le
     * journal d'un autre.
     */
    public function test_la_formule_d_un_magasin_ne_change_pas_celle_d_un_autre(): void
    {
        $premier = $this->connecterLeProprietaire();

        Livewire::test(ParametresPrixDeVente::class)
            ->fillForm(['actif' => true, 'arrondi' => true, 'formule' => 'cinq'])
            ->call('save')
            ->assertHasNoFormErrors();

        $second = $this->ouvrirUnAutreMagasin($premier);
        $this->dansLeMagasin($second, $this->proprietaireDe($premier));

        Livewire::test(ParametresPrixDeVente::class)->assertFormSet(['actif' => false, 'arrondi' => false, 'formule' => 'x9']);
        $this->assertSame(ArrondiDuPrixDeVente::Aucun, $second->fresh()->bareme_prix_vente->arrondi);
        $this->assertSame(ArrondiDuPrixDeVente::MultipleDeCinq, $premier->fresh()->bareme_prix_vente->arrondi);
        $this->assertSame([$premier->id], array_map(fn ($entree): int => $entree->magasin_id, $this->entreesDAudit($premier->societe, ActionAuditee::ParametresDuMagasinModifies)));
    }

    /**
     * La page, ouverte avant qu'une autre personne ne choisisse sa formule,
     * ne l'efface pas en choisissant la sienne : refusé, et rien ne
     * s'écrit.
     */
    public function test_une_page_ouverte_avant_la_formule_d_une_autre_personne_ne_l_efface_pas(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $page = Livewire::test(ParametresPrixDeVente::class);
        $bareme = new BaremePrixDeVente(BaremePrixDeVente::parDefaut()->paliers, 6000, ArrondiDuPrixDeVente::EuroLePlusProche);

        Magasin::query()->findOrFail($magasin->id)->update(['bareme_prix_vente' => $bareme]);
        $page->fillForm(['actif' => true, 'arrondi' => true, 'formule' => 'cinq'])->call('save')->assertNotified(ModifierLesParametresDuMagasinAction::REFUS_PERIMES);

        $this->assertFalse($magasin->fresh()->prix_vente_automatique);
        $this->assertEquals($bareme, $magasin->fresh()->bareme_prix_vente);
    }
}
