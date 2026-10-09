<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Actions\MettreAJourMembreMagasinAction;
use App\Domain\Tenancy\Actions\RetirerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMembreMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

/**
 * Le journal des événements techniques (JET), chaîne des événements du
 * registre fiscal de chaque magasin : ouverture et fin de session,
 * modification des droits, paramètres ayant un impact sur la conformité.
 * Les tests signent avec la paire éphémère que Tests\TestCase épingle.
 */
class JournalEvenementsTest extends TestCase
{
    use RefreshDatabase;

    private function magasinDe(User $proprietaire, string $nom = 'Ma Boutique'): Magasin
    {
        return app(CreerMagasinAction::class)->handle(['nom' => $nom], $proprietaire);
    }

    /**
     * Le journal des événements du magasin, dans l'ordre de sa chaîne : le
     * code de chaque événement.
     *
     * @return list<string>
     */
    private function codes(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->orderBy('sequence')
            ->pluck('type')
            ->all());
    }

    private function dernier(Magasin $magasin, CodeEvenement $code): EnregistrementFiscal
    {
        return app(TenantContext::class)->runAs($magasin, fn (): EnregistrementFiscal => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) $code->value)
            ->orderByDesc('sequence')
            ->firstOrFail());
    }

    /**
     * @return array<array-key, mixed>
     */
    private function details(Magasin $magasin, CodeEvenement $code): array
    {
        return $this->dernier($magasin, $code)->contenuSigne()['donnees']['details'];
    }

    /**
     * L'ancienne et la nouvelle valeur de chaque changement de paramètre
     * (JET 270) du journal du magasin, dans l'ordre de sa chaîne.
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function changementsDeParametre(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => [
                $evenement->contenuSigne()['donnees']['details']['ancienne_valeur'],
                $evenement->contenuSigne()['donnees']['details']['nouvelle_valeur'],
            ])
            ->all());
    }

    private function rattachement(Magasin $magasin, User $utilisateur): MagasinUser
    {
        return $magasin->membres()->where('user_id', $utilisateur->getKey())->firstOrFail();
    }

    /**
     * La clé privée de signature disparaît de l'environnement : le
     * registre déjà résolu, qui la garde en mémoire, est oublié.
     */
    private function retirerLaCleDeSignature(): void
    {
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
    }

    public function test_l_ouverture_de_session_s_inscrit_une_seule_fois_par_session_dans_le_magasin(): void
    {
        $proprietaire = User::factory()->create(['name' => 'Alice Martin']);
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire)
            ->withHeader('User-Agent', 'Navigateur de test')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);

        $this->get("/app/{$magasin->uuid}")->assertSuccessful();
        $this->get("/app/{$magasin->uuid}/clients")->assertSuccessful();

        $this->assertSame(['260', '80'], $this->codes($magasin));
        $contenu = $this->dernier($magasin, CodeEvenement::OuvertureDeSession)->contenuSigne();
        $this->assertSame(['id' => $proprietaire->id, 'nom' => 'Alice Martin'], $contenu['operateur']);
        $this->assertSame([
            'code' => 80,
            'libelle' => 'Ouverture de session dans le magasin',
            'details' => [],
            'ip' => '203.0.113.7',
            'agent_utilisateur' => 'Navigateur de test',
        ], $contenu['donnees']);
    }

    /**
     * Fermé par défaut : sans clé de signature, la session n'entre pas
     * dans le magasin, et rien n'est noté dans la session, si bien que la
     * requête suivante réessaie et inscrit l'ouverture une fois la clé
     * rétablie.
     */
    public function test_sans_cle_de_signature_la_session_n_entre_pas_dans_le_magasin(): void
    {
        Exceptions::fake();
        $cle = config('caisse_securisee.cle_privee');
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire);
        $this->retirerLaCleDeSignature();

        $reponse = $this->get("/app/{$magasin->uuid}");

        $reponse->assertServiceUnavailable();
        $this->assertSame([], $this->codes($magasin));
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);

        config(['caisse_securisee.cle_privee' => $cle]);
        $this->app->forgetInstance(RegistreFiscal::class);
        $this->get("/app/{$magasin->uuid}")->assertSuccessful();
        $this->assertSame(['260', '80'], $this->codes($magasin));
    }

    public function test_la_deconnexion_inscrit_la_fin_de_session_dans_le_magasin_ouvert(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Martin']);
        $magasin = $this->magasinDe($alice, 'Boutique A');
        $autreMagasin = $this->magasinDe(User::factory()->create(), 'Boutique B');
        $this->actingAs($alice);
        $this->get("/app/{$magasin->uuid}")->assertSuccessful();

        $this->post(route('filament.app.auth.logout'));

        $this->assertGuest();
        $this->assertSame(['260', '80', '40'], $this->codes($magasin));
        $this->assertSame([], $this->codes($autreMagasin));
        $this->assertSame(['id' => $alice->id, 'nom' => 'Alice Martin'], $this->dernier($magasin, CodeEvenement::FinDeSession)->contenuSigne()['operateur']);
    }

    /**
     * Retiré de l'équipe, il n'ouvre plus le panneau, dont la déconnexion
     * passe par la même porte : sa session ne se ferme que par une autre
     * voie, comme ici, et n'inscrit plus rien dans le magasin.
     */
    public function test_un_membre_retire_entre_temps_n_inscrit_plus_rien_dans_ce_magasin(): void
    {
        Route::middleware('web')->post('/test/fermer-la-session', function (): string {
            auth()->logout();

            return 'fermée';
        });
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        $this->get("/app/{$magasin->uuid}")->assertSuccessful();
        $magasin->utilisateurs()->detach($vendeur->getKey());

        $this->post('/test/fermer-la-session');

        $this->assertGuest();
        $this->assertSame(['260', '130', '80'], $this->codes($magasin));
    }

    public function test_la_fermeture_de_la_session_de_cet_appareil_inscrit_aussi_la_fin_de_session(): void
    {
        Route::middleware('web')->post('/test/fermer-la-session-de-cet-appareil', function (): string {
            auth()->logoutCurrentDevice();

            return 'fermée';
        });
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire);
        $this->get("/app/{$magasin->uuid}")->assertSuccessful();

        $this->post('/test/fermer-la-session-de-cet-appareil');

        $this->assertSame(['260', '80', '40'], $this->codes($magasin));
    }

    /**
     * Une déconnexion n'est jamais bloquée : le refus du registre est
     * signalé, et la session se ferme quand même.
     */
    public function test_sans_cle_de_signature_la_deconnexion_aboutit_quand_meme(): void
    {
        Exceptions::fake();
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire);
        $this->get("/app/{$magasin->uuid}")->assertSuccessful();
        $this->retirerLaCleDeSignature();

        $this->post(route('filament.app.auth.logout'));

        $this->assertGuest();
        $this->assertSame(['260', '80'], $this->codes($magasin));
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
    }

    public function test_une_deconnexion_sans_magasin_ouvert_n_inscrit_rien(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire);

        $this->post(route('filament.app.auth.logout'));

        $this->assertGuest();
        $this->assertSame([], $this->codes($magasin));
    }

    public function test_la_creation_d_un_membre_inscrit_ses_droits(): void
    {
        $proprietaire = User::factory()->create(['name' => 'Alice Martin']);
        $magasin = $this->magasinDe($proprietaire);
        $autreMagasin = $this->magasinDe(User::factory()->create(), 'Autre boutique');
        $this->actingAs($proprietaire);

        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul Vendeur', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);

        $this->assertSame(['260', '130'], $this->codes($magasin));
        $this->assertSame([], $this->codes($autreMagasin));
        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => 'Paul Vendeur'],
            'avant' => null,
            'apres' => ['role' => 'employee', 'statut' => 'active', 'permissions' => ['facture.create', 'facture.view']],
        ], $this->details($magasin, CodeEvenement::ModificationDesDroits));
        $this->assertSame(['id' => $proprietaire->id, 'nom' => 'Alice Martin'], $this->dernier($magasin, CodeEvenement::ModificationDesDroits)->contenuSigne()['operateur']);
    }

    /**
     * Sans liste, un changement de rôle donne le modèle du nouveau rôle :
     * les droits d'avant et d'après s'inscrivent ensemble.
     */
    public function test_un_changement_de_role_ou_de_statut_inscrit_les_droits_avant_et_apres(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        app(MettreAJourMembreMagasinAction::class)->handle($this->rattachement($magasin, $vendeur), RoleMagasin::Observateur, StatutMembreMagasin::Suspendu);

        $this->assertSame(['260', '130', '130'], $this->codes($magasin));
        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => 'Paul'],
            'avant' => ['role' => 'employee', 'statut' => 'active', 'permissions' => ['facture.view']],
            'apres' => ['role' => 'viewer', 'statut' => 'suspended', 'permissions' => [
                'avoir_fournisseur.view', 'client.view', 'devis.view', 'facture.view', 'facture_achat.view', 'fournisseur.view', 'produit.view',
            ]],
        ], $this->details($magasin, CodeEvenement::ModificationDesDroits));
    }

    public function test_une_mise_a_jour_qui_ne_change_rien_n_inscrit_rien(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        app(MettreAJourMembreMagasinAction::class)->handle($this->rattachement($magasin, $vendeur), RoleMagasin::Vendeur, StatutMembreMagasin::Actif);
        app(MettreAJourMembreMagasinAction::class)->handle($this->rattachement($magasin, $vendeur), RoleMagasin::Vendeur, StatutMembreMagasin::Actif, [PermissionRegistry::FACTURE_VIEW]);

        $this->assertSame(['260', '130'], $this->codes($magasin));
    }

    public function test_les_autorisations_cochees_s_inscrivent_avant_et_apres(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK]);

        app(MettreAJourMembreMagasinAction::class)->handle($this->rattachement($magasin, $vendeur), RoleMagasin::Vendeur, StatutMembreMagasin::Actif, [PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK, PermissionRegistry::AVOIR_VIEW]);

        $this->assertSame(['260', '130', '130'], $this->codes($magasin));
        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => 'Paul'],
            'avant' => ['role' => 'employee', 'statut' => 'active', 'permissions' => ['produit.voir_valeur_stock']],
            'apres' => ['role' => 'employee', 'statut' => 'active', 'permissions' => ['avoir.view', 'produit.voir_valeur_stock']],
        ], $this->details($magasin, CodeEvenement::ModificationDesDroits));
    }

    public function test_le_retrait_d_un_membre_inscrit_les_droits_qu_il_perd(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_VIEW]);

        app(RetirerMembreMagasinAction::class)->handle($this->rattachement($magasin, $vendeur));

        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => 'Paul'],
            'avant' => ['role' => 'employee', 'statut' => 'active', 'permissions' => ['avoir.view']],
            'apres' => null,
        ], $this->details($magasin, CodeEvenement::ModificationDesDroits));
    }

    public function test_sans_cle_de_signature_aucun_droit_n_est_modifie(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        $this->retirerLaCleDeSignature();

        $this->assertThrows(
            fn () => app(MettreAJourMembreMagasinAction::class)->handle($this->rattachement($magasin, $vendeur), RoleMagasin::Comptable, StatutMembreMagasin::Actif),
            ModuleFiscalNonConfigureException::class,
        );

        $this->assertSame(RoleMagasin::Vendeur, $this->rattachement($magasin, $vendeur)->role);
        $this->assertSame(['260', '130'], $this->codes($magasin));
    }

    public function test_un_changement_de_taux_de_tva_d_un_produit_inscrit_l_ancienne_et_la_nouvelle_valeur(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $produit = Produit::factory()->create(['reference' => 'TSH-01', 'nom' => 'T-shirt', 'prix_vente_ttc' => 12, 'taux_tva' => 5.5]);

        $produit->update(['taux_tva' => 20]);

        $this->assertSame(['260', '270'], $this->codes($magasin));
        $evenement = $this->dernier($magasin, CodeEvenement::ParametreDeConformite);
        $this->assertSame([
            'parametre' => 'taux_tva',
            'produit' => ['id' => $produit->id, 'reference' => 'TSH-01', 'nom' => 'T-shirt'],
            'ancienne_valeur' => '5.50',
            'nouvelle_valeur' => '20.00',
        ], $evenement->contenuSigne()['donnees']['details']);
        $this->assertSame(['type' => 'produit', 'id' => $produit->id], $evenement->contenuSigne()['document']);
    }

    public function test_modifier_un_produit_sans_changer_son_taux_n_inscrit_rien(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $produit = Produit::factory()->create(['nom' => 'T-shirt', 'taux_tva' => 20]);

        $produit->update(['nom' => 'T-shirt bleu', 'taux_tva' => '20.00']);

        $this->assertSame([], $this->codes($magasin));
    }

    public function test_sans_cle_de_signature_le_taux_de_tva_d_un_produit_ne_change_pas(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $produit = Produit::factory()->create(['taux_tva' => 5.5]);
        $this->retirerLaCleDeSignature();

        $this->assertThrows(fn () => $produit->update(['taux_tva' => 20]), ModuleFiscalNonConfigureException::class);

        $this->assertSame('5.50', $produit->fresh()->taux_tva);
        $this->assertSame([], $this->codes($magasin));
    }

    /**
     * Deux enregistrements qui se chevauchent : la fiche chargée avant que
     * l'autre ne change le taux en garde l'ancienne valeur en mémoire ; le
     * journal inscrit celle que la modification remplace en base.
     */
    public function test_l_ancienne_valeur_inscrite_est_celle_que_la_modification_remplace_en_base(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $chargeAvant = Produit::query()->findOrFail($produit->id);
        $produit->update(['taux_tva' => 5.5]);

        $chargeAvant->update(['taux_tva' => 10]);

        $this->assertSame([['20.00', '5.50'], ['5.50', '10.00']], $this->changementsDeParametre($magasin));
        $this->assertSame('10.00', $produit->fresh()->taux_tva);
    }

    public function test_remettre_la_valeur_deja_en_base_n_inscrit_rien(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $produit = Produit::factory()->create(['taux_tva' => 20]);
        $chargeAvant = Produit::query()->findOrFail($produit->id);
        $produit->update(['taux_tva' => 5.5]);

        $chargeAvant->update(['taux_tva' => 5.5]);

        $this->assertSame([['20.00', '5.50']], $this->changementsDeParametre($magasin));
    }

    /**
     * Une vente verrouille le registre en premier : une transaction déjà
     * commencée, qui a pu verrouiller autre chose, ne le verrouille pas
     * après coup pour inscrire un changement de taux, qui est refusé.
     */
    public function test_un_taux_modifie_dans_une_transaction_qui_n_a_pas_d_abord_verrouille_le_registre_est_refuse(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $produit = Produit::factory()->create(['taux_tva' => 5.5]);

        $this->assertThrows(
            fn () => DB::transaction(fn () => $produit->update(['taux_tva' => 20])),
            LogicException::class,
            'Le registre fiscal se verrouille en tête de transaction, avant tout autre verrou',
        );

        $this->assertSame('5.50', $produit->fresh()->taux_tva);
        $this->assertSame([], $this->codes($magasin));
    }

    /**
     * Le journal qui reçoit l'événement est celui du magasin actif : le
     * régime de TVA d'un magasin ne se modifie pas pendant qu'un autre est
     * actif, sans quoi son changement s'inscrirait chez l'autre.
     */
    public function test_un_parametre_d_un_magasin_ne_se_modifie_pas_depuis_un_autre_magasin_actif(): void
    {
        $magasin = $this->magasinDe(User::factory()->create(), 'Boutique A');
        $autreMagasin = $this->magasinDe(User::factory()->create(), 'Boutique B');

        $this->assertThrows(
            fn () => $magasin->update(['regime_tva' => RegimeTva::ReelNormalMensuel]),
            LogicException::class,
            'Un paramètre ayant un impact sur la conformité ne se modifie que dans son propre magasin',
        );

        $this->assertNull($magasin->fresh()->regime_tva);
        $this->assertSame([], $this->codes($magasin));
        $this->assertSame([], $this->codes($autreMagasin));
    }

    public function test_les_evenements_se_signent_et_se_chainent_les_uns_aux_autres(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire)->get("/app/{$magasin->uuid}")->assertSuccessful();
        app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Paul', 'email' => 'paul@example.test', 'password' => 'mot-de-passe-sur'], RoleMagasin::Vendeur);
        Produit::factory()->create(['taux_tva' => 5.5])->update(['taux_tva' => 20]);

        $evenements = app(TenantContext::class)->runAs($magasin, fn () => EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->get());

        $this->assertSame([1, 2, 3, 4], $evenements->pluck('sequence')->all());
        $this->assertSame(['260', '80', '130', '270'], $evenements->pluck('type')->all());
        $this->assertNull($evenements[0]->signature_precedente);
        $clePublique = Arr::first(config('caisse_securisee.cles_publiques'));
        foreach ($evenements as $rang => $evenement) {
            $this->assertSame(1, openssl_verify($evenement->contenu, base64_decode(strtr($evenement->signature, '-_', '+/')), $clePublique, OPENSSL_ALGO_SHA256));

            if ($rang > 0) {
                $this->assertSame($evenements[$rang - 1]->signature, $evenement->signature_precedente);
            }
        }
    }
}
