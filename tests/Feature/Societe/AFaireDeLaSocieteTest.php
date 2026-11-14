<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Actions\NoterLeVisaDuBordereauAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Règle 6 de l'« À faire » (décision de l'éditeur du 26/10/2026) : au
 * tableau de bord de l'espace d'administration, pour chaque magasin réel
 * de sa portée, des nombres par groupe, sans nom de client ni numéro de
 * document, chacun menant au tableau de bord du magasin (voir
 * ChosesAFaireDeLaSociete) ; une lecture hors du magasin courant, limitée
 * aux nombres, isolée entre magasins et entre sociétés.
 */
class AFaireDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Lyon a deux ventes à l'export sans preuve et un achat en brouillon
     * saisi sans la pièce du fournisseur, Villeurbanne rien : le
     * propriétaire lit « 2 preuves à fournir · 1 achat à valider · 1 pièce
     * du fournisseur manquante » sur la ligne de Lyon, qui mène à son tableau de
     * bord, et rien d'autre, ni le nom du client ni le numéro d'une
     * facture.
     */
    public function test_le_proprietaire_lit_les_nombres_de_chaque_magasin_sans_nom_ni_numero(): void
    {
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre', 'code' => 'LYON']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne', 'code' => 'VILLEU']);
        $export = $this->venteALExport($lyon);
        $this->venteALExport($lyon);
        $this->achatEnBrouillon($lyon);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $nombres = app(ChosesAFaireDeLaSociete::class)->parMagasin($proprietaire);

        $this->assertSame([
            $lyon->id => [
                ['groupe' => 'preuves à fournir', 'nombre' => 2, 'montant' => null],
                ['groupe' => 'achat à valider', 'nombre' => 1, 'montant' => null],
                ['groupe' => 'pièce du fournisseur manquante', 'nombre' => 1, 'montant' => null],
            ],
            $villeurbanne->id => [],
        ], $nombres);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))
            ->assertOk()
            ->assertSee('2 preuves à fournir · 1 achat à valider · 1 pièce du fournisseur manquante')
            ->assertSee(htmlspecialchars(Dashboard::getUrl(panel: 'app', tenant: $lyon)), escape: false)
            ->assertDontSee('Acme Inc.')
            ->assertDontSee($export->numero);
    }

    /**
     * Un administrateur n'a que les magasins de sa sélection, et, dans
     * chacun, les groupes dont il voit tous les documents : sans « voir
     * celles de toute l'équipe », aucune preuve ; avec les achats, les
     * achats.
     */
    public function test_un_administrateur_ne_lit_que_sa_portee_et_les_groupes_qu_il_voit(): void
    {
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        $this->venteALExport($lyon);
        $this->achatEnBrouillon($lyon);
        $this->achatEnBrouillon($villeurbanne);
        $administrateur = $this->administrateur($lyon, [$lyon->id], autorisationsMagasin: array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [PermissionRegistry::FACTURE_VIEW_TOUTES])));
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);

        $this->assertSame([$lyon->id => [
            ['groupe' => 'achat à valider', 'nombre' => 1, 'montant' => null],
            ['groupe' => 'pièce du fournisseur manquante', 'nombre' => 1, 'montant' => null],
        ]], app(ChosesAFaireDeLaSociete::class)->parMagasin($administrateur));
    }

    /**
     * Ni une autre société ni le magasin de démonstration ne comptent ; un
     * compte d'un cabinet lié n'en lit rien.
     */
    public function test_les_nombres_sont_isoles_entre_societes_et_sans_le_cabinet(): void
    {
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $ailleurs = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ailleurs']));
        $this->venteALExport($ailleurs);
        $this->achatEnBrouillon($ailleurs);
        $proprietaire = $this->proprietaireDe($lyon);
        $collaborateur = $this->collaborateurDuCabinetLie($lyon);

        $this->assertSame([$lyon->id => []], app(ChosesAFaireDeLaSociete::class)->parMagasin($proprietaire));
        $this->assertSame([], app(ChosesAFaireDeLaSociete::class)->parMagasin($collaborateur));
    }

    /**
     * Chaque groupe se compte en une seule requête pour tous les magasins,
     * chaque document dans son magasin : à Lyon, un ticket détaxé attend le
     * visa de son bordereau et un autre l'a, un devis accepté est déjà
     * transformé, en une facture qui reste en brouillon ; à Villeurbanne, le bordereau visé d'un ticket a été
     * remplacé par un autre, qui attend son visa, et un devis accepté
     * attend d'être transformé.
     */
    public function test_les_visas_et_les_devis_a_transformer_se_comptent_dans_le_magasin_de_chaque_document(): void
    {
        $lyon = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Lyon Centre']));
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->runAs($lyon, function () use ($lyon): void {
            $auteur = self::responsableDeTest($lyon);
            $this->ticketDetaxe($auteur, '2026FR000000001');
            app(NoterLeVisaDuBordereauAction::class)->handle($auteur, $this->ticketDetaxe($auteur, '2026FR000000002'), now()->format('Y-m-d'));
            app(CreerFactureDepuisDevisAction::class)->handle($auteur, $this->devisAccepte($auteur));
        });
        app(TenantContext::class)->runAs($villeurbanne, function () use ($villeurbanne): void {
            $auteur = self::responsableDeTest($villeurbanne);
            $ticket = $this->ticketDetaxe($auteur, '2026FR000000003');
            app(NoterLeVisaDuBordereauAction::class)->handle($auteur, $ticket, now()->format('Y-m-d'));
            app(NoterBordereauDetaxeAction::class)->handle($auteur, $ticket, '2026FR000000004');
            $this->devisAccepte($auteur);
        });
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $this->assertSame([
            $lyon->id => [
                ['groupe' => 'visa de détaxe à noter', 'nombre' => 1, 'montant' => null],
                ['groupe' => 'brouillon de facture', 'nombre' => 1, 'montant' => null],
            ],
            $villeurbanne->id => [
                ['groupe' => 'visa de détaxe à noter', 'nombre' => 1, 'montant' => null],
                ['groupe' => 'devis à transformer', 'nombre' => 1, 'montant' => null],
            ],
        ], app(ChosesAFaireDeLaSociete::class)->parMagasin($proprietaire));
    }

    /**
     * Un ticket émis d'un sac à main de 250,00 € TTC dont cet auteur a noté
     * ce n° de bordereau de détaxe, dans le magasin actif.
     */
    private function ticketDetaxe(User $auteur, string $bordereau): Facture
    {
        $produit = Produit::factory()->create(['nom' => 'Sac à main', 'prix_vente_ttc' => '250.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        app(NoterBordereauDetaxeAction::class)->handle($auteur, $ticket, $bordereau);

        return $ticket;
    }

    /**
     * Un devis envoyé puis accepté par cet auteur, dans le magasin actif.
     */
    private function devisAccepte(User $auteur): Devis
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = app(EmettreDevisAction::class)->handle($auteur, app(CreerDevisAction::class)->handle($auteur, Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));

        return app(AccepterDevisAction::class)->handle($auteur, $devis);
    }

    /**
     * Une vente à Acme Inc., expédiée à New York, dans ce magasin, sans
     * preuve de douane.
     */
    private function venteALExport(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, function () use ($magasin): Facture {
            $auteur = self::responsableDeTest($magasin);
            $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
            $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => 1]], [
                'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
            ]));
        });
    }

    /**
     * Une facture d'achat en brouillon dans ce magasin.
     */
    private function achatEnBrouillon(Magasin $magasin): void
    {
        app(TenantContext::class)->runAs($magasin, function () use ($magasin): void {
            $auteur = self::responsableDeTest($magasin);
            app(CreerFactureAchatAction::class)->handle($auteur, Fournisseur::factory()->create(), [
                ['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id, 'quantite' => 5, 'prix_unitaire_ht' => '4.00', 'taux_tva' => 20],
            ], ['date_facture' => '2026-01-15']);
        });
    }
}
