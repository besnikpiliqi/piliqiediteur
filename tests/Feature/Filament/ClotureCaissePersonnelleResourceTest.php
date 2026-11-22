<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Widgets\CaissesOuvertesDeLEquipeApercu;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\App\Resources\ClotureCaisseResource\Widgets\CaissesPersonnellesOuvertesApercu;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClotureCaissePersonnelleResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_sans_permission_de_cloture_peut_quand_meme_fermer_sa_caisse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul@example.test',
        ]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse($vendeur->can(PermissionRegistry::CLOTURE_CAISSE_GERER), 'Prérequis du test : le vendeur ne doit pas avoir la permission de clôturer la journée.');

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id])
            ->call('recevoirLesProduitsChoisis', [['produit_id' => $produit->id, 'quantite' => 1]])->call('create');
        $facture = Facture::query()->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->callAction('fermer_ma_caisse');

        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $vendeur->id)->firstOrFail();
        $this->assertSame(1, $releve->nombre_ventes);
        $this->assertSame('60.00', $releve->total_ttc);
    }

    public function test_un_vendeur_ne_voit_que_ses_propres_releves(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Paul', 'email' => 'paul2@example.test',
        ]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, null, [
            'name' => 'Jean', 'email' => 'jean@example.test',
        ]);

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        $this->vendreAuComptoir(Client::factory()->create(), 12);
        Livewire::test(ListClotureCaissePersonnelles::class)->callAction('fermer_ma_caisse');

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        $this->vendreAuComptoir(Client::factory()->create(), 18);
        Livewire::test(ListClotureCaissePersonnelles::class)->callAction('fermer_ma_caisse');

        $releveA = ClotureCaissePersonnelle::where('vendeur_id', $vendeurA->id)->firstOrFail();
        $releveB = ClotureCaissePersonnelle::where('vendeur_id', $vendeurB->id)->firstOrFail();

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertCanSeeTableRecords([$releveB])
            ->assertCanNotSeeTableRecords([$releveA]);

        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertCanSeeTableRecords([$releveA, $releveB]);
    }

    /**
     * B18 : la fenêtre « Fermer ma caisse » récapitule les quatre rubriques
     * avant de fermer, et la liste les garde, chacune à part. Martin a
     * 30,00 € de crédit (un ticket de 30,00 € payé puis repris par un
     * avoir, avant une première fermeture) ; le vendeur lui vend 60,00 €,
     * réglés par ce crédit et 30,00 € en espèces, et vend 45,00 € à Durand,
     * qui ne paie pas encore. Le vendeur est autorisé à faire des avoirs.
     */
    public function test_le_releve_montre_le_vendu_l_encaisse_par_mode_le_credit_d_avoir_et_le_reste_du(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::AVOIR_CREER]);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $martin = Client::factory()->create(['nom' => 'Martin']);
        $ticketRepris = $this->vendreAuComptoir($martin, 30);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $martin, [['ligne_facture_id' => $ticketRepris->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
        $this->vendreAuComptoir($martin, 60);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 45, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(['nom' => 'Durand']), [['produit_id' => $produit->id, 'quantite' => 1]]));

        $page = Livewire::test(ListClotureCaissePersonnelles::class)->mountAction('fermer_ma_caisse');

        $page->assertSee('TTC vendu : 105,00 € (2 ventes)')
            ->assertSeeHtml('Encaissé : <span class="piliqi-encaisse">')
            ->assertSeeHtmlInOrder(['<abbr class="piliqi-encaisse__mode" title="Espèces">ESP</abbr>', '<span class="piliqi-encaisse__montant">30,00 €</span>'])
            ->assertDontSeeHtml('piliqi-encaisse__total')
            ->assertSee('Crédit d\'avoir utilisé : 30,00 €')
            ->assertSee('Reste dû : 45,00 €');
        $page->callMountedAction();
        $releve = ClotureCaissePersonnelle::query()->latest('id')->firstOrFail();
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertTableColumnStateSet('total_ttc', '105.00', $releve->getKey())
            ->assertTableColumnStateSet('encaisse', ClotureCaissePersonnelleResource::encaisseParMode($releve), $releve->getKey())
            ->assertSeeHtmlInOrder(['<abbr class="piliqi-encaisse__mode" title="Espèces">ESP</abbr>', '<span class="piliqi-encaisse__montant">30,00 €</span>'])
            ->assertDontSeeHtml('piliqi-encaisse__total')
            ->assertDontSee('Par mode de paiement')
            ->assertTableColumnStateSet('credit_avoir', '30.00', $releve->getKey())
            ->assertTableColumnStateSet('reste_du', '45.00', $releve->getKey());
    }

    /**
     * La colonne « Encaissé » de « Ma caisse » et de la fenêtre « Fermer ma
     * caisse » (demandes de l'éditeur du 15/11/2026, choix A) : une vente
     * réglée par 30,00 € par carte puis 18,00 € en espèces s'écrit une
     * ligne par mode, en abrégé, dans l'ordre fixe (ESP avant CB), puis
     * « Total 48,00 € » ; aucune légende sous le tableau.
     */
    public function test_l_encaisse_en_deux_modes_s_ecrit_une_ligne_par_mode_dans_l_ordre_fixe_puis_le_total(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 48, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EnregistrerVenteCaisseAction::class)->handle($vendeur, Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $produit->id, 'quantite' => 1]], new ReglementComptant([
            new PartDeReglement(ModePaiement::CarteBancaire, 3000),
            new PartDeReglement(ModePaiement::Especes, 1800),
        ]));
        $enOrdre = [
            '<abbr class="piliqi-encaisse__mode" title="Espèces">ESP</abbr>',
            '<span class="piliqi-encaisse__montant">18,00 €</span>',
            '<abbr class="piliqi-encaisse__mode" title="Carte bancaire">CB</abbr>',
            '<span class="piliqi-encaisse__montant">30,00 €</span>',
            '<span class="piliqi-encaisse__mode piliqi-encaisse__total">Total</span>',
            '<span class="piliqi-encaisse__montant piliqi-encaisse__total">48,00 €</span>',
        ];

        Livewire::test(ListClotureCaissePersonnelles::class)->mountAction('fermer_ma_caisse')
            ->assertSeeHtmlInOrder($enOrdre)
            ->callMountedAction();

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertSeeHtmlInOrder($enOrdre)
            ->assertDontSee('Carte bancaire :')
            ->assertDontSee('CB : carte bancaire');
    }

    /**
     * Le menu et le titre de la page disent « Ma caisse » à qui tient une
     * caisse dans le magasin sans voir les relevés de toute l'équipe, « Les
     * caisses » à tous les autres : le propriétaire, un responsable, un
     * vendeur qui voit les relevés de toute l'équipe, un administrateur sans
     * vente et un compte du cabinet lié, qui les lit avec
     * cloture_caisse.view_toutes.
     */
    public function test_le_menu_s_appelle_les_caisses_sauf_pour_qui_ne_voit_que_sa_caisse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $comptes = [
            'Ma caisse' => [$this->membre($magasin, RoleMagasin::Vendeur)],
            'Les caisses' => [
                $this->proprietaireDe($magasin),
                $this->responsableDeTest($magasin),
                $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES]),
                $this->administrateur($magasin),
                $this->collaborateurDuCabinetLie($magasin),
            ],
        ];

        foreach ($comptes as $libelle => $liste) {
            foreach ($liste as $compte) {
                $this->dansLeMagasin($magasin, $compte);

                $this->assertSame($libelle, ClotureCaissePersonnelleResource::getNavigationLabel(), $compte->name);
                $this->assertSame($libelle, Livewire::test(ListClotureCaissePersonnelles::class)->instance()->getTitle(), $compte->name);
                $this->get(ClotureCaissePersonnelleResource::getUrl('index', tenant: $magasin))->assertOk()->assertSeeText($libelle);
            }
        }
    }

    /**
     * Un administrateur sans vente n'a pas de caisse : pas de « Fermer ma
     * caisse » ; autorisé à clôturer la journée, il ferme celle d'un
     * vendeur, sinon il n'en a pas le bouton non plus.
     */
    public function test_un_administrateur_sans_vente_n_a_pas_fermer_ma_caisse_mais_ferme_celle_d_un_vendeur_s_il_peut_cloturer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $this->dansLeMagasin($magasin, $vendeur);
        $this->vendreAuComptoir(Client::factory()->create(), 12);
        $administrateur = $this->administrateur($magasin);
        $sansCloture = $this->administrateur($magasin, null, [], array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [PermissionRegistry::CLOTURE_CAISSE_GERER])));

        $this->dansLeMagasin($magasin, $sansCloture);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionHidden('fermer_ma_caisse')
            ->assertActionHidden('fermer_la_caisse_d_un_vendeur');
        $this->dansLeMagasin($magasin, $administrateur);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionHidden('fermer_ma_caisse')
            ->callAction('fermer_la_caisse_d_un_vendeur', ['vendeur_id' => $vendeur->id])
            ->assertNotified('Caisse de Paul fermée');

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([(int) $vendeur->id, (int) $administrateur->id, ModeFermetureCaisse::Responsable, 1], [(int) $releve->vendeur_id, (int) $releve->fermee_par, $releve->mode_fermeture, $releve->nombre_ventes]);
        $this->assertFalse($administrateur->tientUneCaisseDans($magasin));
    }

    /**
     * Les caisses encore ouvertes s'affichent en tête de la page des
     * caisses quand elle s'appelle « Les caisses » (le propriétaire, un
     * compte du cabinet lié, un administrateur sans vente qui ne fait que
     * fermer la caisse d'un vendeur), jamais quand elle s'appelle « Ma
     * caisse », même pour un vendeur autorisé à clôturer la journée. Sur
     * la liste des clôtures, elles restent réservées à qui peut clôturer
     * la journée : un compte du cabinet lié ne les y voit pas.
     */
    public function test_les_caisses_ouvertes_s_affichent_en_tete_de_la_page_des_caisses(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $this->dansLeMagasin($magasin, $vendeur);
        $this->vendreAuComptoir(Client::factory()->create(), 12);
        $vendeurQuiCloture = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::CLOTURE_CAISSE_VIEW, PermissionRegistry::CLOTURE_CAISSE_GERER]);
        $administrateurQuiFerme = $this->administrateur($magasin, null, [], array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES])));
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);

        foreach ([$this->proprietaireDe($magasin), $collaborateur, $administrateurQuiFerme] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            $this->get(ClotureCaissePersonnelleResource::getUrl('index', tenant: $magasin))->assertOk()->assertSeeText('Les caisses')->assertSeeLivewire(CaissesOuvertesDeLEquipeApercu::class);
            Livewire::test(CaissesOuvertesDeLEquipeApercu::class)->assertSee('Fermées automatiquement à la clôture de la journée : Paul');
        }
        foreach ([$vendeur, $vendeurQuiCloture] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            $this->get(ClotureCaissePersonnelleResource::getUrl('index', tenant: $magasin))->assertOk()->assertSeeText('Ma caisse')->assertDontSeeLivewire(CaissesOuvertesDeLEquipeApercu::class);
            $this->assertFalse(CaissesOuvertesDeLEquipeApercu::canView(), $compte->name);
        }
        $this->dansLeMagasin($magasin, $collaborateur);
        $this->get(ClotureCaisseResource::getUrl('index', tenant: $magasin))->assertOk()->assertDontSeeLivewire(CaissesPersonnellesOuvertesApercu::class);
        $this->dansLeMagasin($magasin, $vendeurQuiCloture);
        $this->get(ClotureCaisseResource::getUrl('index', tenant: $magasin))->assertOk()->assertSeeLivewire(CaissesPersonnellesOuvertesApercu::class);
    }

    /**
     * Tant que la caisse n'a ni vente, ni encaissement, ni contre-passation
     * à compter, « Fermer ma caisse » se montre grisé (aria-disabled et
     * aria-description, sans l'attribut disabled, qui empêcherait
     * l'info-bulle de s'ouvrir) avec
     * l'info-bulle « Aucune vente ni encaissement à fermer. » ; un clic ne
     * ferme rien, ouvre aucune fenêtre et rappelle le refus ; une vente
     * rouvre la caisse (bouton normal, fenêtre de confirmation), sa
     * fermeture la grise de nouveau (décision de l'éditeur du 18/11/2026).
     */
    public function test_fermer_ma_caisse_est_grise_avec_son_infobulle_tant_que_rien_n_est_a_fermer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $this->dansLeMagasin($magasin, $vendeur);
        $phrase = 'Aucune vente ni encaissement à fermer.';

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->assertSeeHtml('aria-disabled="true"')
            ->assertSeeHtml('aria-description="'.$phrase.'"')
            ->assertSeeHtml('piliqi-action-grisee')
            ->callAction('fermer_ma_caisse')
            ->assertNotified($phrase);
        $this->assertSame(0, ClotureCaissePersonnelle::query()->count());

        $this->vendreAuComptoir(Client::factory()->create(), 12);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertDontSeeHtml('aria-disabled="true"')
            ->assertDontSeeHtml('piliqi-action-grisee')
            ->callAction('fermer_ma_caisse')
            ->assertNotified();
        $this->assertSame(1, ClotureCaissePersonnelle::query()->count());

        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertSeeHtml('aria-description="'.$phrase.'"');
    }

    /**
     * « Fermer la caisse d'un vendeur » se grise de même, avec
     * « Aucune caisse de vendeur à fermer : aucune vente ni encaissement. »,
     * tant qu'aucun autre vendeur n'a de caisse ouverte ; un clic ne
     * ferme rien.
     */
    public function test_fermer_la_caisse_d_un_vendeur_est_grise_avec_son_infobulle_quand_aucune_caisse_n_est_ouverte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $phrase = 'Aucune caisse de vendeur à fermer : aucune vente ni encaissement.';
        $proprietaire = $this->proprietaireDe($magasin);

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_la_caisse_d_un_vendeur')
            ->assertSeeHtml('aria-disabled="true"')
            ->assertSeeHtml('aria-description="'.$phrase.'"')
            ->callAction('fermer_la_caisse_d_un_vendeur')
            ->assertNotified($phrase);
        $this->assertSame(0, ClotureCaissePersonnelle::query()->count());

        $this->dansLeMagasin($magasin, $vendeur);
        $this->vendreAuComptoir(Client::factory()->create(), 12);
        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertDontSeeHtml('piliqi-action-grisee')
            ->callAction('fermer_la_caisse_d_un_vendeur', ['vendeur_id' => $vendeur->id])
            ->assertNotified('Caisse de Paul fermée');
        $this->assertSame(1, ClotureCaissePersonnelle::query()->count());
    }

    /**
     * Une demande forgée (fermeture d'une caisse sans activité) reçoit le
     * même refus que le bouton grisé, de la part du domaine.
     */
    public function test_une_fermeture_forgee_sans_rien_a_fermer_est_refusee_avec_la_meme_phrase(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $this->dansLeMagasin($magasin, $vendeur);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Aucune vente ni encaissement à fermer.');
        app(FermerSaCaisseAction::class)->handle($vendeur, $vendeur);
    }

    private function vendreAuComptoir(Client $client, float $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
    }
}
