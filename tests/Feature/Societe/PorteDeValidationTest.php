<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\MotifCloture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * La porte de validation (voir Magasin::peutVendre()) : tant que sa
 * société n'est pas validée, ou que son établissement n'est pas confirmé
 * par le répertoire Sirene, un magasin réel se configure mais ne fait
 * aucune opération commerciale réelle (ticket, facture, avoir, paiement,
 * devis numéroté, PDF d'un document, e-mail à un client).
 */
class PorteDeValidationTest extends TestCase
{
    use RefreshDatabase;

    private const REFUS = 'Votre société est en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation.';

    public function test_une_societe_a_verifier_n_emet_ni_ticket_ni_facture_ni_avoir_ni_devis_numerote(): void
    {
        $magasin = $this->magasinDUneSocieteAVerifier();
        $produit = $this->produit();
        $ticket = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $ticket), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $facture), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(EmettreDevisAction::class)->handle(self::auteur(), $devis), VenteNonAutoriseeException::class, self::REFUS);

        $this->assertSame([StatutFacture::Brouillon, StatutFacture::Brouillon], [$ticket->fresh()->statut, $facture->fresh()->statut]);
        $this->assertSame([StatutDevis::Brouillon, null], [$devis->fresh()->statut, $devis->fresh()->numero]);
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $magasin->id)->exists());
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', '!=', ChaineFiscale::Evenement->value)->count());
        $this->assertFalse($this->proprietaireDe($magasin)->can(PermissionRegistry::AVOIR_CREER));
        $this->assertFalse($this->proprietaireDe($magasin)->can(PermissionRegistry::FACTURE_EMETTRE));
    }

    public function test_une_societe_a_verifier_n_enregistre_aucun_paiement_et_n_ecrit_a_aucun_client(): void
    {
        Mail::fake();
        $magasin = $this->magasinDUneSocieteAVerifier();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(['email' => 'client@example.test']), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->handle($proprietaire, $devis, 'client@example.test', null), VenteNonAutoriseeException::class, self::REFUS);

        $this->assertFalse($proprietaire->can(PermissionRegistry::PAIEMENT_CREER));
        $this->assertFalse($proprietaire->can(PermissionRegistry::DEVIS_ENVOYER));
        $this->assertSame(0, EnvoiDocument::query()->count());
        Mail::assertNothingSent();
    }

    /**
     * Avant la validation, la société se prépare : catalogue, clients,
     * fournisseurs, équipe, paramètres du magasin, achats saisis et
     * validés par le propriétaire ; brouillons de ventes par un
     * responsable, le propriétaire ne vendant jamais.
     */
    public function test_une_societe_a_verifier_se_configure_catalogue_equipe_parametres_et_achats(): void
    {
        $magasin = $this->magasinDUneSocieteAVerifier();
        $proprietaire = $this->proprietaireDe($magasin);
        $this->actingAs($proprietaire);

        $client = Client::factory()->create();
        $service = Service::factory()->create();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);
        $magasin->update(['delai_paiement_jours' => 45]);
        $achat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [[
            'produit' => ['nom' => 'Câble USB-C', 'reference' => 'CBL-USBC', 'prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => true, 'seuil_alerte' => 2],
            'quantite' => 3,
            'prix_unitaire_ht' => 0.47,
        ]]));
        $brouillon = app(CreerFactureAction::class)->handle($this->responsableDeTest($magasin), $client, [['service_id' => $service->id, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'quantite' => 1]]);

        $this->assertSame(3, Produit::query()->sole()->stock_quantite);
        $this->assertSame(StatutFactureAchat::Validee, $achat->fresh()->statut);
        $this->assertTrue($vendeur->peutOuvrir($magasin));
        $this->assertSame(45, $magasin->fresh()->delai_paiement_jours);
        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);
        $this->assertTrue($vendeur->can(PermissionRegistry::FACTURE_CREATE));
        $this->assertTrue($this->responsableDeTest($magasin)->can(PermissionRegistry::VENTE_REMISES_ET_PRIX));
        $this->assertFalse($proprietaire->can(PermissionRegistry::FACTURE_CREATE));
        $this->assertTrue($proprietaire->can(PermissionRegistry::PRODUIT_CREATE));
    }

    /**
     * Le registre fiscal refuse lui-même toute écriture d'une vente ou d'un
     * encaissement d'un magasin dont la société n'est pas validée, quel
     * que soit le chemin qui y mène ; ses événements techniques, eux,
     * s'inscrivent.
     */
    public function test_le_registre_refuse_toute_vente_d_un_magasin_dont_la_societe_n_est_pas_validee(): void
    {
        $this->magasinDUneSocieteAVerifier();
        $registre = app(RegistreFiscal::class);

        $operateur = app(OperateurCourant::class);
        $this->assertThrows(fn () => $operateur->pendantLeSysteme(fn () => DB::transaction(fn () => $registre->enregistrerVente('ticket', 1200, ['numero' => 'TK-X-2026-000001']))), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => $operateur->pendantLeSysteme(fn () => DB::transaction(fn () => $registre->enregistrer(ChaineFiscale::Encaissement, 'paiement', ['montant' => 1200]))), VenteNonAutoriseeException::class, self::REFUS);
        DB::transaction(fn () => $registre->journaliser(CodeEvenement::ExportDeDonnees));

        $this->assertSame([ChaineFiscale::Evenement, ChaineFiscale::Evenement], EnregistrementFiscal::query()->orderBy('id')->pluck('chaine')->all());
    }

    /**
     * Dans une société validée, un magasin dont l'INSEE n'a pas encore
     * confirmé l'établissement ne vend pas ; confirmé, il vend.
     */
    public function test_un_magasin_dont_l_etablissement_n_est_pas_verifie_ne_vend_pas(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']);
        $magasin->forceFill(['etablissement_verifie_le' => null])->save();
        $ticket = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $this->assertThrows(
            fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $ticket),
            VenteNonAutoriseeException::class,
            "L'établissement de ce magasin (SIRET {$magasin->siret}) n'est pas encore confirmé par le répertoire Sirene de l'INSEE : aucune vente avant sa confirmation.",
        );
        $this->assertSame(StatutFacture::Brouillon, $ticket->fresh()->statut);

        $magasin->forceFill(['etablissement_verifie_le' => now()])->save();
        $this->assertSame('TK-MABOUT-2026-000001', app(EmettreFactureAction::class)->handle(self::auteur(), $ticket)->numero);
    }

    /**
     * Le SIREN d'une société ne change jamais, avant comme après sa
     * validation, même par une écriture qui ne passe par aucune action :
     * une erreur se traite par la procédure de l'éditeur, ou par
     * l'inscription close faute de code, qui libère le SIREN.
     */
    public function test_le_siren_ne_change_jamais_ni_avant_ni_apres_la_validation(): void
    {
        $magasin = $this->magasinDUneSocieteAVerifier();
        $societe = $magasin->societe;
        $siren = (string) $societe->siren;
        $changer = fn () => app(TenantContext::class)->runAsSociete($societe, fn () => $societe->fresh()->forceFill(['siren' => '200000008'])->save());
        app(TenantContext::class)->clear();

        $this->assertThrows($changer, LogicException::class, 'Le SIREN d\'une société ne change jamais');
        $societe->fresh()->forceFill(['validee_le' => now()])->save();
        $this->assertThrows($changer, LogicException::class, 'Le SIREN d\'une société ne change jamais');

        $this->assertSame($siren, $societe->fresh()->siren);
    }

    public function test_une_societe_n_est_jamais_a_la_fois_validee_et_close(): void
    {
        $societe = Societe::factory()->validee()->create();

        $this->assertThrows(fn () => $societe->forceFill(['close_le' => now(), 'motif_cloture' => MotifCloture::Refus])->save(), LogicException::class, 'Une société n\'est jamais à la fois validée et close.');

        $this->assertNull($societe->fresh()->close_le);
        $this->assertThrows(fn () => Societe::factory()->validee()->create(['close_le' => now(), 'motif_cloture' => MotifCloture::Refus]), LogicException::class, 'Une société n\'est jamais à la fois validée et close.');
    }

    /**
     * Avant la validation, aucun PDF d'un magasin réel ne se produit, pas
     * même celui d'un brouillon : un usurpateur ne fabrique pas un faux
     * devis au nom d'une entreprise qui n'a rien demandé.
     */
    public function test_aucun_pdf_d_un_magasin_reel_ne_se_telecharge_avant_la_validation(): void
    {
        $this->magasinDUneSocieteAVerifier();
        $produit = $this->produit();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        $this->assertThrows(fn () => app(FacturePdfGenerator::class)->generer($brouillon), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(DevisPdfGenerator::class)->generer($devis), VenteNonAutoriseeException::class, self::REFUS);
        $this->assertThrows(fn () => app(FacturePdfGenerator::class)->html($brouillon), VenteNonAutoriseeException::class, self::REFUS);
    }

    /**
     * Le magasin MABOUT d'une société en cours de vérification, magasin
     * actif.
     */
    private function magasinDUneSocieteAVerifier(): Magasin
    {
        return $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT', 'a_verifier' => true]);
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
    }
}
