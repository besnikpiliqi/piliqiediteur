<?php

namespace Tests\Feature\Societe;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Exceptions\IdentiteDeLaSocieteIncompleteException;
use App\Domain\Facturation\Rules\NumeroTvaAuFormat;
use App\Domain\Facturation\Services\Emetteur;
use App\Domain\Tenancy\Actions\CorrigerSirenAvantValidationAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Enums\FormeJuridique;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\IdentifiantsInsee;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'identité légale de la société (voir IdentiteSociete et
 * ModifierIdentiteSocieteAction), la seule que tous ses magasins
 * impriment : raison sociale, forme juridique, capital, immatriculation,
 * n° de TVA, siège.
 */
class IdentiteSocieteTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_enregistre_l_identite_legale_de_la_societe(): void
    {
        $societe = $this->ouvrirLAdministration();

        Livewire::test(IdentiteSociete::class)
            ->fillForm(self::identiteComplete())
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Identité de la société enregistrée');

        $this->assertSame([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'capital_social' => '10000.00',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République',
            'siege_code_postal' => '13001',
            'siege_ville' => 'Marseille',
        ], $societe->fresh()->only(['raison_sociale', 'forme_juridique', 'capital_social', 'immatriculation', 'numero_tva', 'siege_adresse', 'siege_code_postal', 'siege_ville']));
    }

    public function test_le_proprietaire_ouvre_la_page_de_l_identite(): void
    {
        $societe = $this->ouvrirLAdministration();

        $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $societe))
            ->assertOk()
            ->assertSee('Identité légale')
            ->assertSee($societe->siren);
    }

    /**
     * Le SIREN se lit dans l'identité de la société sans s'y saisir (il se
     * corrige à part, tant que la société n'est pas validée) ; un SIREN
     * forcé dans la requête est ignoré, et aucun écran du panneau des
     * magasins n'a plus d'identité ni de fiscalité.
     */
    public function test_le_siren_ne_se_saisit_dans_aucun_ecran_de_magasin(): void
    {
        $societe = $this->ouvrirLAdministration();
        $siren = $societe->siren;

        Livewire::test(IdentiteSociete::class)
            ->assertFormFieldDoesNotExist('siren')
            ->set('data.siren', '200000008')
            ->fillForm(self::identiteComplete())
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($siren, $societe->fresh()->siren);
        $this->assertSame([false, false], [Route::has('filament.app.pages.identite-magasin'), Route::has('filament.app.pages.parametres-fiscaux')]);
        $this->assertFalse(Schema::hasColumns('magasins', ['siren']));
    }

    /**
     * Une identité incomplète bloque les factures aux professionnels dans
     * chacun des magasins de la société, jamais les tickets. Le
     * propriétaire ferme sa caisse d'un magasin avant de vendre dans le
     * suivant.
     */
    public function test_une_identite_incomplete_bloque_les_factures_professionnelles_de_tous_les_magasins_mais_pas_les_tickets(): void
    {
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->reglerLaSociete($lyon, ['immatriculation' => null]);

        foreach ([$lyon, $villeurbanne] as $magasin) {
            app(TenantContext::class)->set($magasin->fresh());
            $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
            $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
            $ticket = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

            $this->assertThrows(
                fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $facture),
                IdentiteDeLaSocieteIncompleteException::class,
                'Pour émettre une facture à un professionnel, complétez d\'abord l\'identité de la société (Administration de la société > Identité) : immatriculation.',
            );
            $this->assertNotNull(app(EmettreFactureAction::class)->handle(self::auteur(), $ticket)->numero);
            app(FermerSaCaisseAction::class)->handle(self::auteur(), self::auteur());
        }
    }

    /**
     * @return array<string, array{0: FormeJuridique}>
     */
    public static function societesCommerciales(): array
    {
        return collect([FormeJuridique::Sarl, FormeJuridique::Eurl, FormeJuridique::Sas, FormeJuridique::Sasu, FormeJuridique::Sa, FormeJuridique::Sca, FormeJuridique::Se, FormeJuridique::Snc, FormeJuridique::Scs])
            ->mapWithKeys(fn (FormeJuridique $forme): array => [$forme->getLabel() => [$forme]])
            ->all();
    }

    /**
     * Toute société commerciale indique son capital sur ses factures (art.
     * R123-238 du Code de commerce) : sans lui, l'identité ne s'enregistre
     * pas, et la facture à un professionnel l'attend.
     */
    #[DataProvider('societesCommerciales')]
    public function test_le_capital_est_exige_pour_toute_societe_commerciale(FormeJuridique $forme): void
    {
        $societe = $this->ouvrirLAdministration();

        Livewire::test(IdentiteSociete::class)
            ->fillForm([...self::identiteComplete(), 'forme_juridique' => $forme->value, 'capital_social' => ''])
            ->call('save')
            ->assertHasFormErrors(['capital_social'])
            ->assertSee(ModifierIdentiteSocieteAction::MESSAGE_CAPITAL_EXIGE);

        $this->assertNotSame('Martin et Fils', $societe->fresh()->raison_sociale);
        $this->assertContains('capital social', $societe->fresh()->forceFill(['forme_juridique' => $forme, 'capital_social' => null])->mentionsLegalesManquantes());
    }

    /**
     * Une variante (SELARL, SA coopérative) prend la forme de sa famille
     * de catégories INSEE, qui exige le capital, et garde son libellé
     * exact.
     */
    public function test_une_selarl_et_une_sa_cooperative_exigent_leur_capital(): void
    {
        $societe = $this->ouvrirLAdministration();
        $selarl = FormeJuridique::depuisCategorieInsee('5485');
        $saCooperative = FormeJuridique::depuisCategorieInsee('5551');

        foreach ([[$selarl, 'SELARL'], [$saCooperative, 'SA coopérative']] as [$forme, $libelle]) {
            Livewire::test(IdentiteSociete::class)
                ->fillForm([...self::identiteComplete(), 'forme_juridique' => $forme->value, 'forme_juridique_autre' => $libelle, 'capital_social' => ''])
                ->call('save')
                ->assertHasFormErrors(['capital_social'])
                ->assertSee(ModifierIdentiteSocieteAction::MESSAGE_CAPITAL_EXIGE);
        }

        Livewire::test(IdentiteSociete::class)
            ->fillForm([...self::identiteComplete(), 'forme_juridique' => $selarl->value, 'forme_juridique_autre' => 'SELARL'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([FormeJuridique::Sarl, FormeJuridique::Sa], [$selarl, $saCooperative]);
        $this->assertSame('SELARL', $societe->fresh()->formeImprimee());
    }

    /**
     * Une autre forme dit si elle est une société commerciale : son capital
     * n'est exigé qu'alors.
     */
    public function test_une_forme_autre_exige_de_dire_si_la_societe_est_commerciale(): void
    {
        $societe = $this->ouvrirLAdministration();
        $autre = [...self::identiteComplete(), 'forme_juridique' => FormeJuridique::Autre->value, 'forme_juridique_autre' => 'Société civile', 'capital_social' => ''];

        Livewire::test(IdentiteSociete::class)
            ->fillForm($autre)
            ->call('save')
            ->assertHasFormErrors(['societe_commerciale'])
            ->assertSee(ModifierIdentiteSocieteAction::MESSAGE_SOCIETE_COMMERCIALE);
        Livewire::test(IdentiteSociete::class)
            ->fillForm([...$autre, 'societe_commerciale' => true])
            ->call('save')
            ->assertHasFormErrors(['capital_social'])
            ->assertSee(ModifierIdentiteSocieteAction::MESSAGE_CAPITAL_EXIGE);
        Livewire::test(IdentiteSociete::class)
            ->fillForm([...$autre, 'societe_commerciale' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([FormeJuridique::Autre, false, null], [$societe->fresh()->forme_juridique, $societe->fresh()->societe_commerciale, $societe->fresh()->capital_social]);
        $this->assertNotContains('capital social', $societe->fresh()->mentionsLegalesManquantes());
    }

    /**
     * Un entrepreneur individuel n'a pas de capital : un capital saisi est
     * refusé, et choisir cette forme efface celui d'avant.
     */
    public function test_un_entrepreneur_individuel_n_a_pas_de_capital(): void
    {
        $societe = $this->ouvrirLAdministration();
        $proprietaire = $societe->proprietaire;

        $this->assertThrows(
            fn () => app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe, ['forme_juridique' => FormeJuridique::EntrepreneurIndividuel->value, 'capital_social' => '1000']),
            fn (ValidationException $refus): bool => $refus->errors() === ['capital_social' => [ModifierIdentiteSocieteAction::MESSAGE_CAPITAL_D_UN_ENTREPRENEUR]],
        );
        app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe, [...self::identiteComplete(), 'raison_sociale' => 'Jean Martin']);
        app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe->fresh(), ['forme_juridique' => FormeJuridique::EntrepreneurIndividuel->value, 'capital_social' => '']);

        $this->assertSame([FormeJuridique::EntrepreneurIndividuel, null, false], [$societe->fresh()->forme_juridique, $societe->fresh()->capital_social, $societe->fresh()->capital_variable]);
        $this->assertSame([], $societe->fresh()->mentionsLegalesManquantes());
    }

    /**
     * À l'écran, le capital d'un entrepreneur individuel est masqué : le
     * choisir efface le capital enregistré pour la forme d'avant, au lieu
     * de refuser un champ que la personne ne voit plus.
     */
    public function test_choisir_entrepreneur_individuel_a_l_ecran_efface_le_capital_d_avant(): void
    {
        $societe = $this->ouvrirLAdministration();
        Livewire::test(IdentiteSociete::class)->fillForm(self::identiteComplete())->call('save')->assertHasNoFormErrors();

        Livewire::test(IdentiteSociete::class)
            ->fillForm(['raison_sociale' => 'Jean Martin', 'forme_juridique' => FormeJuridique::EntrepreneurIndividuel->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([FormeJuridique::EntrepreneurIndividuel, null, false], [$societe->fresh()->forme_juridique, $societe->fresh()->capital_social, $societe->fresh()->capital_variable]);
    }

    /**
     * Le SIREN corrigé avant la validation : l'identité que le répertoire
     * donnait de l'entreprise de l'ancien SIREN fait place à celle du
     * nouveau (dénomination, catégorie juridique, forme, siège, n° de TVA
     * proposé) ; le capital, qu'un entrepreneur individuel n'a pas,
     * s'efface ; l'immatriculation saisie reste. La correction s'inscrit
     * au journal d'audit avec ces changements, son auteur pour auteur.
     */
    public function test_la_correction_du_siren_reprend_l_identite_que_le_repertoire_donne_du_nouveau_siren(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        app(TenantContext::class)->clear();
        app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe, ['capital_social' => '1000', 'immatriculation' => 'RM 13']);
        $sirenEi = FauxRepertoireSirene::SIREN_ENTREPRENEUR_INDIVIDUEL;

        app(CorrigerSirenAvantValidationAction::class)->handle($proprietaire, $societe->fresh(), $sirenEi, [$magasin->id => substr(IdentifiantsInsee::avecSaCle($sirenEi.'0001'), 9)]);

        $corrigee = $societe->fresh();
        $this->assertSame(
            ['JEANNE DUPONT', '1000', FormeJuridique::EntrepreneurIndividuel, null, IdentifiantsInsee::numeroTvaPropose($sirenEi), 'RM 13', '1 RUE DE LA REPUBLIQUE'],
            [$corrigee->raison_sociale, $corrigee->categorie_juridique_insee, $corrigee->forme_juridique, $corrigee->capital_social, $corrigee->numero_tva, $corrigee->immatriculation, $corrigee->siege_adresse],
        );
        $entree = app(TenantContext::class)->runAsSociete($societe, fn (): EntreeAudit => EntreeAudit::query()->where('action', 'siren_corrige_avant_validation')->sole());
        $this->assertSame(['membre', $proprietaire->id], [$entree->origine, (int) $entree->auteur_id]);
        $this->assertSame(
            ['capital_social' => null, 'categorie_juridique_insee' => '1000', 'forme_juridique' => 'entrepreneur_individuel', 'numero_tva' => IdentifiantsInsee::numeroTvaPropose($sirenEi), 'raison_sociale' => 'JEANNE DUPONT'],
            array_intersect_key($entree->nouvelles_valeurs, array_flip(['raison_sociale', 'categorie_juridique_insee', 'forme_juridique', 'capital_social', 'numero_tva'])),
        );
        $this->assertSame(['capital_social' => '1000.00', 'categorie_juridique_insee' => '5710', 'siren' => $societe->siren], array_intersect_key($entree->anciennes_valeurs, array_flip(['siren', 'categorie_juridique_insee', 'capital_social'])));
    }

    /**
     * Le SIREN corrigé pendant une panne de l'INSEE : l'identité de
     * l'entreprise de l'ancien SIREN ne reste pas ; la reprise du contrôle
     * la complète ensuite depuis le nouveau SIREN.
     */
    public function test_une_correction_pendant_une_panne_vide_l_identite_de_l_ancien_siren_que_la_reprise_complete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $societe = $magasin->societe;
        $repertoire = app(RepertoireSirene::class);
        $repertoire->rendreInjoignable();
        app(TenantContext::class)->clear();

        app(CorrigerSirenAvantValidationAction::class)->handle($this->proprietaireDe($magasin), $societe, '200000008', [$magasin->id => '00017']);

        $this->assertSame([null, null, null, null], [$societe->fresh()->raison_sociale, $societe->fresh()->categorie_juridique_insee, $societe->fresh()->forme_juridique, $societe->fresh()->siege_adresse]);
        $repertoire->rendreInjoignable(false);

        $this->artisan('app:reverifier-sirene')->assertSuccessful();

        $this->assertSame(
            ['SOCIETE FICTIVE 200000008', '5710', FormeJuridique::Sas, '1 RUE DE LA REPUBLIQUE'],
            [$societe->fresh()->raison_sociale, $societe->fresh()->categorie_juridique_insee, $societe->fresh()->forme_juridique, $societe->fresh()->siege_adresse],
        );
    }

    /**
     * @return array<string, array{0: string, 1: FormeJuridique}>
     */
    public static function categoriesInsee(): array
    {
        return [
            'entrepreneur individuel (1000)' => ['1000', FormeJuridique::EntrepreneurIndividuel],
            'SNC (5202)' => ['5202', FormeJuridique::Snc],
            'SCS (5306)' => ['5306', FormeJuridique::Scs],
            'SCA (5308)' => ['5308', FormeJuridique::Sca],
            'EURL (5498)' => ['5498', FormeJuridique::Eurl],
            'SARL (5499)' => ['5499', FormeJuridique::Sarl],
            'SELARL (5485)' => ['5485', FormeJuridique::Sarl],
            'SA à conseil d\'administration (5599)' => ['5599', FormeJuridique::Sa],
            'SA à directoire (5699)' => ['5699', FormeJuridique::Sa],
            'SASU (5720)' => ['5720', FormeJuridique::Sasu],
            'SAS (5710)' => ['5710', FormeJuridique::Sas],
            'SELAS (5785)' => ['5785', FormeJuridique::Sas],
            'SE (5800)' => ['5800', FormeJuridique::Se],
            'association (9220)' => ['9220', FormeJuridique::Autre],
            'société civile (6540)' => ['6540', FormeJuridique::Autre],
        ];
    }

    /**
     * La forme proposée à l'inscription vient de la famille de la
     * catégorie juridique que donne le répertoire Sirene, non de son code
     * exact ; tout autre code donne une autre forme, dont la société dit
     * si elle est commerciale.
     */
    #[DataProvider('categoriesInsee')]
    public function test_la_forme_juridique_est_preremplie_depuis_la_famille_de_la_categorie_insee(string $categorie, FormeJuridique $forme): void
    {
        $this->assertSame($forme, FormeJuridique::depuisCategorieInsee($categorie));
    }

    /**
     * Même règle que pour un client ou un fournisseur : saisi avec ses
     * séparateurs, le n° de TVA s'enregistre sans eux, en majuscules, et
     * le formulaire montre ensuite le n° enregistré.
     */
    public function test_le_numero_de_tva_s_enregistre_sans_separateurs(): void
    {
        $societe = $this->ouvrirLAdministration();

        Livewire::test(IdentiteSociete::class)
            ->fillForm([...self::identiteComplete(), 'numero_tva' => 'fr 56 121.212.120'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertFormSet(['numero_tva' => 'FR56121212120']);

        $this->assertSame('FR56121212120', $societe->fresh()->numero_tva);
    }

    /**
     * Pour une forme qui ne l'exige pas, le capital social s'enregistre au
     * centime, puis se vide (H2).
     */
    public function test_le_capital_social_s_enregistre_puis_se_vide(): void
    {
        $societe = $this->ouvrirLAdministration();
        $civile = [...self::identiteComplete(), 'forme_juridique' => FormeJuridique::Autre->value, 'forme_juridique_autre' => 'Société civile', 'societe_commerciale' => false];

        Livewire::test(IdentiteSociete::class)->fillForm([...$civile, 'capital_social' => '10000'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('10000.00', $societe->fresh()->capital_social);

        Livewire::test(IdentiteSociete::class)
            ->assertFormSet(['capital_social' => '10000.00'])
            ->fillForm(['capital_social' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertNull($societe->fresh()->capital_social);
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function saisiesInvalides(): array
    {
        return [
            'n° de TVA sans code pays' => [['numero_tva' => '12 123456789'], 'numero_tva', NumeroTvaAuFormat::MESSAGE],
            'capital négatif' => [['capital_social' => '-5'], 'capital_social', 'Le capital social ne peut pas être négatif.'],
            'capital au millième' => [['capital_social' => '10000.555'], 'capital_social', 'Le capital social se saisit au centime près.'],
            'immatriculation de 101 caractères' => [['immatriculation' => str_repeat('R', 101)], 'immatriculation', 'Le champ immatriculation ne doit pas dépasser 100 caractères.'],
            'mention EI pour une SARL' => [['raison_sociale' => 'Martin EI'], 'raison_sociale', ModifierIdentiteSocieteAction::MESSAGE_MENTION_EI],
            'mention (EI) pour une SARL' => [['raison_sociale' => 'Martin (EI)'], 'raison_sociale', ModifierIdentiteSocieteAction::MESSAGE_MENTION_EI],
        ];
    }

    /**
     * @param  array<string, string>  $saisie
     */
    #[DataProvider('saisiesInvalides')]
    public function test_une_saisie_invalide_est_refusee_sans_rien_enregistrer(array $saisie, string $champ, string $message): void
    {
        $societe = $this->ouvrirLAdministration();
        $avant = $societe->fresh()->only(ModifierIdentiteSocieteAction::CHAMPS);

        Livewire::test(IdentiteSociete::class)
            ->fillForm([...self::identiteComplete(), ...$saisie])
            ->call('save')
            ->assertHasFormErrors([$champ])
            ->assertSee($message);

        $this->assertEquals($avant, $societe->fresh()->only(ModifierIdentiteSocieteAction::CHAMPS));
    }

    /**
     * Tant qu'il manque une mention de l'identité légale, un encadré en
     * tête de la page dit lesquelles empêchent de facturer un
     * professionnel, dans tous les magasins (B11, K7) ; il disparaît une
     * fois l'identité complète.
     */
    public function test_l_encadre_liste_les_mentions_qui_manquent_pour_facturer_un_professionnel(): void
    {
        $this->ouvrirLAdministration();

        Livewire::test(IdentiteSociete::class)
            ->assertSee('Identité incomplète pour facturer un professionnel')
            ->assertSee('dans aucun de vos magasins, tant que ces mentions manquent : capital social, immatriculation.')
            ->fillForm([...self::identiteComplete(), 'immatriculation' => ''])
            ->call('save')
            ->assertSee('ces mentions manquent : immatriculation.')
            ->fillForm(self::identiteComplete())
            ->call('save')
            ->assertDontSee('Identité incomplète pour facturer un professionnel');
    }

    /**
     * Un champ vidé ne garde pas une chaîne vide : il s'enregistre sans
     * valeur.
     */
    public function test_un_champ_vide_est_enregistre_sans_valeur(): void
    {
        $societe = $this->ouvrirLAdministration();
        app(ModifierIdentiteSocieteAction::class)->handle($societe->proprietaire, $societe, [...self::identiteComplete(), 'siege_complement_adresse' => 'Bâtiment B']);

        Livewire::test(IdentiteSociete::class)
            ->fillForm(['siege_complement_adresse' => '', 'immatriculation' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([null, null], [$societe->fresh()->siege_complement_adresse, $societe->fresh()->immatriculation]);
    }

    /**
     * La raison sociale rappelle la dénomination que donne le répertoire
     * Sirene quand elle en diffère.
     */
    public function test_l_ecart_avec_la_denomination_du_repertoire_est_signale(): void
    {
        $societe = $this->ouvrirLAdministration();
        $controle = app(ControleSirene::class);
        $controle->noterUnite($societe, $controle->unite($societe->siren));

        Livewire::test(IdentiteSociete::class)
            ->assertDontSee('Le répertoire Sirene de l\'INSEE indique')
            ->fillForm(self::identiteComplete())
            ->call('save')
            ->assertSee("Le répertoire Sirene de l'INSEE indique « SOCIETE FICTIVE {$societe->siren} ».");
    }

    /**
     * L'identité et la fiscalité sont celles de la société, une seule
     * fois : aucun magasin ne les porte ni n'en décide, et chacun imprime
     * la même identité légale, avec son propre SIRET et sa propre adresse.
     */
    public function test_aucun_magasin_n_a_de_privilege_sur_les_reglages(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->ouvrirLAdministration($lyon->societe);

        Livewire::test(IdentiteSociete::class)->fillForm(self::identiteComplete())->call('save')->assertHasNoFormErrors();

        $emetteurs = collect([$lyon, $villeurbanne])->map(fn (Magasin $magasin): Emetteur => Emetteur::duMagasin($magasin->fresh()));
        $this->assertSame(
            [['Martin et Fils', 'SARL', 1000000, 'RCS Marseille', 'FR56121212120'], ['Martin et Fils', 'SARL', 1000000, 'RCS Marseille', 'FR56121212120']],
            $emetteurs->map(fn (Emetteur $emetteur): array => [$emetteur->raisonSociale, $emetteur->formeJuridique, $emetteur->capitalSocial, $emetteur->immatriculation, $emetteur->numeroTva])->all(),
        );
        $this->assertSame([$lyon->siret, $villeurbanne->siret], $emetteurs->map(fn (Emetteur $emetteur): string => $emetteur->siret)->all());
        $this->assertFalse(Schema::hasColumns('magasins', ['raison_sociale']));
        $this->assertFalse(Schema::hasColumns('magasins', ['regime_tva']));
        $this->assertSame([], preg_grep('/principal/', Schema::getColumnListing('magasins')));
    }

    /**
     * L'administration d'une société qui vient de s'inscrire (identité
     * préremplie, sans capital ni immatriculation), ouverte par son
     * propriétaire ; ou celle de la société donnée.
     */
    private function ouvrirLAdministration(?Societe $societe = null): Societe
    {
        $societe ??= $this->ouvrirUneSociete(['nom' => 'Ma Boutique'])->societe;
        app(TenantContext::class)->clear();
        $this->actingAs($societe->proprietaire()->firstOrFail());
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe);
        app(TenantContext::class)->poserSociete($societe);

        return $societe;
    }

    /**
     * @return array<string, mixed>
     */
    private static function identiteComplete(): array
    {
        return [
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl->value,
            'forme_juridique_autre' => '',
            'capital_social' => '10000',
            'immatriculation' => 'RCS Marseille',
            'numero_tva' => 'FR56121212120',
            'siege_adresse' => '1 rue de la République',
            'siege_code_postal' => '13001',
            'siege_ville' => 'Marseille',
        ];
    }
}
