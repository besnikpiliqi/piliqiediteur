<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confidentialité entre vendeurs (B16) : sans FACTURE_VIEW_TOUTES, un
 * vendeur ne voit, n'envoie et n'encaisse que les factures qu'il a
 * lui-même créées (voir app/Policies/FacturePolicy.php).
 */
class FacturePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_factures(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $contexte = app(TenantContext::class);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20]);

        $this->actingAs($vendeurA);
        $contexte->set($magasin);
        $factureDeA = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($vendeurB);
        $contexte->set($magasin);
        $factureDeB = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->assertTrue($vendeurB->can('view', $factureDeB));
        $this->assertFalse($vendeurB->can('view', $factureDeA), "Un vendeur ne doit pas voir les factures d'un collègue.");

        $this->actingAs($vendeurA);
        $contexte->set($magasin);
        $this->assertTrue($vendeurA->can('view', $factureDeA));
        $this->assertFalse($vendeurA->can('view', $factureDeB));
    }

    public function test_avec_la_permission_vue_totale_un_role_voit_toutes_les_factures(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $contexte = app(TenantContext::class);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $comptable = $this->membre($magasin, RoleMagasin::Comptable, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20]);

        $this->actingAs($vendeur);
        $contexte->set($magasin);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($comptable);
        $contexte->set($magasin);
        $this->assertTrue($comptable->can('view', $facture));
    }

    /**
     * @return array<string, array{RoleMagasin, bool}>
     */
    public static function rolesQuiEnvoientLesFacturesDeLEquipe(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, true],
            'vendeur' => [RoleMagasin::Vendeur, false],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    /**
     * Envoyer ou relancer une facture : seulement celles que l'on voit
     * (un vendeur, les siennes), et avec la permission d'envoyer.
     */
    #[DataProvider('rolesQuiEnvoientLesFacturesDeLEquipe')]
    public function test_envoyer_une_facture_du_proprietaire_selon_le_role(RoleMagasin $role, bool $peutEnvoyer): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $membre = app(CreerMembreMagasinAction::class)->handle($proprietaire, $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $facture = $this->emettreUneFactureEnTantQue($proprietaire, $magasin);

        $this->assertSame($peutEnvoyer, $membre->can('envoyer', $facture));
    }

    public function test_un_vendeur_envoie_ses_propres_factures(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Vendeur', 'email' => 'vendeur@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);

        $this->assertTrue($vendeur->can('envoyer', $this->emettreUneFactureEnTantQue($vendeur, $magasin)));
    }

    public function test_personne_n_envoie_la_facture_d_un_autre_magasin(): void
    {
        $proprietaireA = User::factory()->create();
        $magasinA = app(CreerMagasinAction::class)->handle(['nom' => 'Boutique A'], $proprietaireA);
        $factureDeA = $this->emettreUneFactureEnTantQue($proprietaireA, $magasinA);
        $proprietaireB = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('envoyer', $factureDeA));
    }

    /**
     * B16 : un vendeur encaisse sa propre facture tant qu'elle attend un
     * règlement.
     */
    public function test_un_vendeur_encaisse_sa_propre_facture(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $facture = $this->emettreUneFactureEnTantQue($vendeur, $magasin);

        $this->assertTrue($vendeur->can('encaisser', $facture));
    }

    /**
     * @return array<string, array{list<string>, bool}>
     */
    public static function autorisationsDevantLaFactureDUnCollegue(): array
    {
        return [
            'sans la vue de toute l\'équipe' => [[PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PAIEMENT_CREER], false],
            'avec la vue de toute l\'équipe' => [[PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::PAIEMENT_CREER], true],
        ];
    }

    /**
     * @param  list<string>  $autorisations
     */
    #[DataProvider('autorisationsDevantLaFactureDUnCollegue')]
    public function test_encaisser_la_facture_d_un_collegue_exige_la_vue_de_toute_l_equipe(array $autorisations, bool $peutEncaisser): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $facture = $this->emettreUneFactureEnTantQue($collegue, $magasin);

        $this->assertSame($peutEncaisser, $membre->can('encaisser', $facture));
    }

    public function test_une_facture_payee_ne_s_encaisse_plus(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $facture = $this->emettreUneFactureEnTantQue($vendeur, $magasin);
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => 12]]);

        $this->assertFalse($vendeur->can('encaisser', $facture->fresh()));
    }

    public function test_sans_l_autorisation_d_enregistrer_un_paiement_on_n_encaisse_pas(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE]);
        $facture = $this->emettreUneFactureEnTantQue($vendeur, $magasin);

        $this->assertFalse($vendeur->can('encaisser', $facture));
    }

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function facturesVisiblesSelonLesAutorisations(): array
    {
        return [
            'sans voir les factures' => [[PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE, PermissionRegistry::PAIEMENT_CREER], []],
            'voir les siennes' => [[PermissionRegistry::FACTURE_VIEW], ['la sienne']],
            'voir celles de toute l\'équipe' => [[PermissionRegistry::FACTURE_VIEW_TOUTES], ['la sienne', 'celle du collègue']],
        ];
    }

    /**
     * Les listes de factures (Facture::scopeVisiblesPar()) montrent
     * exactement celles que FacturePolicy::view() laisse voir : une facture
     * proposée dans un formulaire n'est jamais refusée à l'enregistrement.
     *
     * @param  list<string>  $autorisations
     * @param  list<string>  $attendues
     */
    #[DataProvider('facturesVisiblesSelonLesAutorisations')]
    public function test_les_listes_de_factures_suivent_la_policy(array $autorisations, array $attendues): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $factures = [
            'la sienne' => $this->emettreUneFactureEnTantQue($membre, $magasin),
            'celle du collègue' => $this->emettreUneFactureEnTantQue($collegue, $magasin),
        ];

        $listees = Facture::query()->visiblesPar($membre)->orderBy('id')->pluck('id')->all();
        $autorisees = array_keys(array_filter($factures, fn (Facture $facture): bool => $membre->can('view', $facture)));

        $this->assertSame(array_map(fn (string $facture): int => $factures[$facture]->id, $attendues), $listees);
        $this->assertSame($attendues, $autorisees);
    }

    private function emettreUneFactureEnTantQue(User $auteur, Magasin $magasin): Facture
    {
        $this->actingAs($auteur);
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }
}
