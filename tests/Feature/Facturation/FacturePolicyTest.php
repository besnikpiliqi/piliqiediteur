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
use App\Domain\Tenancy\Actions\ModifierMembreAction;
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $contexte = app(TenantContext::class);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $this->actingAs($vendeurA);
        $contexte->set($magasin);
        $factureDeA = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($vendeurB);
        $contexte->set($magasin);
        $factureDeB = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $contexte = app(TenantContext::class);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);
        $comptable = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $expert = $this->expertComptable($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $this->actingAs($vendeur);
        $contexte->set($magasin);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($comptable);
        $contexte->set($magasin);
        $this->assertTrue($comptable->can('view', $facture));
        $this->assertTrue($expert->can('view', $facture));
    }

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function rolesQuiEnvoientLesFacturesDeLEquipe(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), true],
            'vendeur' => [RoleMagasin::Vendeur, null, false],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    /**
     * Envoyer ou relancer une facture : seulement celles que l'on voit
     * (un vendeur, les siennes), et avec la permission d'envoyer.
     */
    #[DataProvider('rolesQuiEnvoientLesFacturesDeLEquipe')]
    public function test_envoyer_une_facture_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutEnvoyer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $facture = $this->emettreUneFactureEnTantQue($this->responsableDeTest($magasin), $magasin);

        $this->assertSame($peutEnvoyer, $membre->can('envoyer', $facture));
    }

    /**
     * L'expert-comptable voit les factures de toute l'équipe mais n'en
     * envoie ni n'en relance aucune, contrairement à l'ancien comptable de
     * magasin.
     */
    public function test_l_expert_comptable_n_envoie_aucune_facture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $expert = $this->expertComptable($magasin);
        $facture = $this->emettreUneFactureEnTantQue($this->responsableDeTest($magasin), $magasin);

        $this->assertTrue($expert->can('view', $facture));
        $this->assertFalse($expert->can('envoyer', $facture));
    }

    public function test_un_vendeur_envoie_ses_propres_factures(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Vendeur', 'email' => 'vendeur@example.test']);

        $this->assertTrue($vendeur->can('envoyer', $this->emettreUneFactureEnTantQue($vendeur, $magasin)));
    }

    public function test_personne_n_envoie_la_facture_d_un_autre_magasin(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $factureDeA = $this->emettreUneFactureEnTantQue($this->responsableDeTest($magasinA), $magasinA);
        $proprietaireB = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('envoyer', $factureDeA));
    }

    /**
     * B16 : un vendeur encaisse sa propre facture tant qu'elle attend un
     * règlement.
     */
    public function test_un_vendeur_encaisse_sa_propre_facture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur, $autorisations);
        $facture = $this->emettreUneFactureEnTantQue($collegue, $magasin);

        $this->assertSame($peutEncaisser, $membre->can('encaisser', $facture));
    }

    public function test_une_facture_payee_ne_s_encaisse_plus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $facture = $this->emettreUneFactureEnTantQue($vendeur, $magasin);
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => 12]]);

        $this->assertFalse($vendeur->can('encaisser', $facture->fresh()));
    }

    public function test_sans_l_autorisation_d_enregistrer_un_paiement_on_n_encaisse_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
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
     * Le membre a émis la sienne avec les cases de son rôle, puis le
     * propriétaire lui a coché celles qui sont vérifiées.
     *
     * @param  list<string>  $autorisations
     * @param  list<string>  $attendues
     */
    #[DataProvider('facturesVisiblesSelonLesAutorisations')]
    public function test_les_listes_de_factures_suivent_la_policy(array $autorisations, array $attendues): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur);
        $membre = $this->membre($magasin, RoleMagasin::Vendeur);
        $factures = [
            'la sienne' => $this->emettreUneFactureEnTantQue($membre, $magasin),
            'celle du collègue' => $this->emettreUneFactureEnTantQue($collegue, $magasin),
        ];
        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $membre, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => $autorisations]]);
        $membre->refresh();

        $listees = Facture::query()->visiblesPar($membre)->orderBy('id')->pluck('id')->all();
        $autorisees = array_keys(array_filter($factures, fn (Facture $facture): bool => $membre->can('view', $facture)));

        $this->assertSame(array_map(fn (string $facture): int => $factures[$facture]->id, $attendues), $listees);
        $this->assertSame($attendues, $autorisees);
    }

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function rolesQuiJustifientLesVentesDeLEquipe(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), false],
            'vendeur qui voit tout et note les preuves sans émettre' => [RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_JUSTIFIER], true],
            'vendeur qui voit tout et émet sans noter les preuves' => [RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE], false],
            'vendeur' => [RoleMagasin::Vendeur, null, false],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    /**
     * Noter une preuve d'exonération ou le bordereau de détaxe d'un ticket :
     * sur une vente que l'on voit (un vendeur, les siennes), avec
     * l'autorisation de noter les preuves d'exonération et les bordereaux
     * de détaxe (réponse (b) de l'éditeur à Q-V2), qu'on puisse émettre ou
     * non ; le comptable, qui voit tout sans cette autorisation, ne le peut
     * pas. La vente est émise par le responsable de test : le propriétaire
     * ne vend jamais.
     */
    #[DataProvider('rolesQuiJustifientLesVentesDeLEquipe')]
    public function test_justifier_une_vente_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutJustifier): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations);
        $facture = $this->emettreUneFactureEnTantQue($this->responsableDeTest($magasin), $magasin);

        $this->assertSame($peutJustifier, $membre->can('justifier', $facture));
    }

    public function test_un_vendeur_justifie_ses_propres_ventes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertTrue($vendeur->can('justifier', $this->emettreUneFactureEnTantQue($vendeur, $magasin)));
    }

    private function emettreUneFactureEnTantQue(User $auteur, Magasin $magasin): Facture
    {
        $this->actingAs($auteur);
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }
}
