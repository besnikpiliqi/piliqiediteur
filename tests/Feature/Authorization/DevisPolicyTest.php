<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\ExpirerLesDevisAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\DevisResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Envoyer un devis (le numéroter, l'envoyer par e-mail) et le supprimer :
 * chacun selon sa permission, jamais pour un autre magasin.
 */
class DevisPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function roles(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), false],
            'vendeur' => [RoleMagasin::Vendeur, null, true],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_envoyer_un_devis_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutEnvoyer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $devis = $this->creerUnDevis();

        $this->assertSame($peutEnvoyer, $membre->can('envoyer', $devis));
    }

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function rolesQuiModifientLesDevis(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), false],
            'vendeur' => [RoleMagasin::Vendeur, null, true],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    /**
     * Supprimer un brouillon jamais envoyé, un par un ou en masse : ceux
     * qui modifient les devis, jamais l'observateur en lecture seule.
     */
    #[DataProvider('rolesQuiModifientLesDevis')]
    public function test_supprimer_un_brouillon_jamais_envoye_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutSupprimer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $devis = $this->creerUnDevis();

        $this->assertSame($peutSupprimer, $membre->can('deleteAny', Devis::class));
        $this->assertSame($peutSupprimer, $membre->can('delete', $devis));
    }

    /**
     * @return array<string, array{StatutDevis}>
     */
    public static function statutsDesDevisNumerotes(): array
    {
        return [
            'envoyé' => [StatutDevis::Envoye],
            'repassé en brouillon' => [StatutDevis::Brouillon],
            'accepté' => [StatutDevis::Accepte],
            'refusé' => [StatutDevis::Refuse],
            'expiré' => [StatutDevis::Expire],
        ];
    }

    /**
     * Seul un brouillon jamais envoyé se supprime (B14) : un devis qui a
     * reçu son numéro reste, quel que soit son statut, même pour le
     * propriétaire, et même repassé en brouillon après une modification.
     */
    #[DataProvider('statutsDesDevisNumerotes')]
    public function test_un_devis_numerote_ne_se_supprime_plus(StatutDevis $statut): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $envoye = app(EmettreDevisAction::class)->handle($this->creerUnDevis(['date_validite' => '2026-10-08']));

        $devis = match ($statut) {
            StatutDevis::Envoye => $envoye,
            StatutDevis::Brouillon => app(ModifierDevisAction::class)->handle($envoye, $envoye->client, [['produit_id' => $envoye->lignes->sole()->produit_id, 'quantite' => 2]]),
            StatutDevis::Accepte => app(AccepterDevisAction::class)->handle($envoye),
            StatutDevis::Refuse => app(RefuserDevisAction::class)->handle($envoye),
            StatutDevis::Expire => $this->expirer($envoye),
        };

        $this->assertSame([$statut, 'DEV-MABOUT-2026-000001'], [$devis->statut, $devis->numero]);
        $this->assertFalse($proprietaire->can('delete', $devis));
        $this->assertThrows(fn () => $devis->delete(), LogicException::class, 'Ce devis ne peut pas être supprimé : seul un brouillon jamais envoyé se supprime, un devis numéroté reste.');
        $this->assertNotSoftDeleted($devis);
    }

    /**
     * Chargé brouillon (lignes cochées d'une suppression en masse, autre
     * onglet), puis numéroté par une autre requête avant d'être supprimé :
     * la suppression relit le devis en base et le refuse, et la série
     * reste sans trou (B14).
     */
    public function test_un_devis_numerote_apres_son_chargement_ne_se_supprime_pas(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $chargeBrouillon = $this->creerUnDevis();
        app(EmettreDevisAction::class)->handle($chargeBrouillon);

        $this->assertThrows(fn () => $chargeBrouillon->delete(), LogicException::class, 'Ce devis ne peut pas être supprimé : seul un brouillon jamais envoyé se supprime, un devis numéroté reste.');

        $this->assertNotSoftDeleted($chargeBrouillon);
        $this->assertSame('DEV-MABOUT-2026-000001', $chargeBrouillon->fresh()->numero);
    }

    /**
     * La date de validité est le dernier jour pour accepter (B15) : le
     * devis valable jusqu'au 08/10 s'accepte encore ce jour-là, plus le
     * lendemain, même avant son passage à « Expiré » dans la nuit.
     */
    public function test_accepter_un_devis_n_est_plus_permis_une_fois_sa_validite_depassee(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $devis = app(EmettreDevisAction::class)->handle($this->creerUnDevis(['date_validite' => '2026-10-08']));

        $this->travelTo('2026-10-08 23:59:00');
        $leDernierJour = $proprietaire->can('accepter', $devis);
        $this->travelTo('2026-10-09 00:01:00');
        $leLendemain = $proprietaire->can('accepter', $devis);

        $this->assertSame([true, false], [$leDernierJour, $leLendemain]);
    }

    /**
     * La facture créée depuis un devis garde son devis d'origine : même le
     * propriétaire ne le supprime plus.
     */
    public function test_un_devis_converti_en_facture_ne_se_supprime_plus(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $devis = $this->creerUnDevis();
        app(AccepterDevisAction::class)->handle(app(EmettreDevisAction::class)->handle($devis));
        app(CreerFactureDepuisDevisAction::class)->handle($devis->fresh());

        $this->assertFalse($proprietaire->can('delete', $devis->fresh()));
    }

    /**
     * Un devis supprimé ne revient pas et ne s'efface pas de la base, même
     * pour le propriétaire. Vérifié par ce que Filament consulte : le Gate
     * de Laravel refuse déjà une capacité absente de la policy, alors que
     * Filament l'autorise (helper Filament\authorize).
     */
    public function test_personne_ne_restaure_ni_n_efface_definitivement_un_devis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        $devis = $this->creerUnDevis();

        $this->assertFalse(DevisResource::canRestore($devis));
        $this->assertFalse(DevisResource::canForceDelete($devis));
    }

    public function test_personne_ne_supprime_le_devis_d_un_autre_magasin(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $devisDeA = $this->creerUnDevis();
        $proprietaireB = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('delete', $devisDeA));
    }

    public function test_personne_n_envoie_le_devis_d_un_autre_magasin(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $devisDeA = $this->creerUnDevis();
        $proprietaireB = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

        $this->assertFalse($proprietaireB->can('envoyer', $devisDeA));
    }

    /**
     * Le devis valable jusqu'au 08/10 expire au passage de la nuit suivante
     * (voir ExpirerLesDevisAction).
     */
    private function expirer(Devis $devis): Devis
    {
        $this->travelTo('2026-10-09 00:30:00');
        app(ExpirerLesDevisAction::class)->handle($devis->magasin);

        return $devis->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function creerUnDevis(array $attributs = []): Devis
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        return app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], $attributs);
    }
}
