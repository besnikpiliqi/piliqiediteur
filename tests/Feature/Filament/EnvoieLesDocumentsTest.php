<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\AvoirResource\Pages\ViewAvoir;
use App\Filament\App\Resources\DevisResource\Pages\ListDevis;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les gestes d'envoi des devis, factures et avoirs (voir
 * EnvoieLesDocuments) : modale d'envoi, relance, PDF et section « Envois ».
 */
class EnvoieLesDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_modale_propose_l_adresse_du_client_et_envoie_a_l_adresse_choisie(): void
    {
        Mail::fake();
        $this->completerIdentiteLegale($this->connecterLeProprietaire());
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create(['email' => 'compta@client.fr']));

        Livewire::test(ListFactures::class)
            ->mountTableAction('envoyer_par_email', $facture)
            ->assertTableActionDataSet(['destinataire' => 'compta@client.fr'])
            ->setTableActionData(['destinataire' => 'direction@client.fr', 'message' => 'Merci de votre confiance.'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors()
            ->assertNotified('Envoi à direction@client.fr programmé');

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('direction@client.fr'));
        $this->assertSame(StatutFacture::Envoyee, $facture->fresh()->statut);
        $this->assertSame('Merci de votre confiance.', $facture->envois()->sole()->message);
    }

    public function test_la_modale_refuse_une_adresse_invalide(): void
    {
        Mail::fake();
        $this->connecterLeProprietaire();
        $facture = $this->emettreFacture(Client::factory()->create());

        Livewire::test(ListFactures::class)
            ->callTableAction('envoyer_par_email', $facture, data: ['destinataire' => 'pas-une-adresse'])
            ->assertHasTableActionErrors(['destinataire' => 'Cette adresse e-mail n\'est pas valide.']);

        $this->assertSame(0, EnvoiDocument::query()->count());
        Mail::assertNothingSent();
    }

    /**
     * Une adresse que la norme admet mais que le serveur d'envoi refuse
     * (avec un commentaire) est refusée dans la modale, avant tout envoi.
     */
    public function test_la_modale_refuse_une_adresse_que_le_serveur_d_envoi_refuserait(): void
    {
        Mail::fake();
        $this->connecterLeProprietaire();
        $facture = $this->emettreFacture(Client::factory()->create());

        Livewire::test(ListFactures::class)
            ->callTableAction('envoyer_par_email', $facture, data: ['destinataire' => 'jean(bureau)@client.fr'])
            ->assertHasTableActionErrors(['destinataire' => 'Cette adresse e-mail n\'est pas valide.']);

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
        Mail::assertNothingSent();
    }

    /**
     * Le client archivé depuis garde ses factures : la modale de relance
     * propose toujours son adresse, et la relance part.
     */
    public function test_la_facture_d_un_client_archive_se_relance_depuis_sa_fiche(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        $this->connecterLeProprietaire();
        $client = Client::factory()->create(['email' => 'archive@exemple.fr']);
        $facture = $this->emettreFactureEchueLe($client, '2026-10-01');
        $client->delete();

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->mountAction('relancer')
            ->assertActionDataSet(['destinataire' => 'archive@exemple.fr'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('archive@exemple.fr'));
    }

    public function test_une_facture_brouillon_n_a_ni_envoi_ni_pdf(): void
    {
        $magasin = $this->connecterLeProprietaire();
        $brouillon = app(CreerFactureAction::class)->handle($this->responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        Livewire::test(ListFactures::class)
            ->assertTableActionHidden('envoyer_par_email', $brouillon)
            ->assertTableActionHidden('telecharger_pdf', $brouillon)
            ->assertTableActionHidden('relancer', $brouillon);
    }

    /**
     * Sur la fiche : l'envoi, puis son résultat dans la section « Envois ».
     */
    public function test_la_fiche_de_la_facture_envoie_et_montre_ses_envois(): void
    {
        Mail::fake();
        $this->connecterLeProprietaire();
        $facture = $this->emettreFacture(Client::factory()->create(['email' => 'client@exemple.fr']));

        // La section « Envois » vient en dernier : le statut « Envoyée » de
        // la facture, plus haut, ne compte pas pour elle.
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertDontSee('Envois')
            ->assertDontSee('Envoyée')
            ->assertDontSee('client@exemple.fr')
            ->callAction('envoyer_par_email', data: ['destinataire' => 'client@exemple.fr'])
            ->assertHasNoActionErrors()
            ->assertSee('Envoyée')
            ->assertSee('client@exemple.fr');

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSeeInOrder(['Envois', 'client@exemple.fr', 'Envoi', 'Envoyé']);
    }

    public function test_la_fiche_montre_l_echec_d_un_envoi(): void
    {
        $this->connecterLeProprietaire();
        $facture = $this->emettreFacture(Client::factory()->create());
        EnvoiDocument::factory()->for($facture, 'document')->enEchec()->create(['destinataire' => 'ancien@client.fr']);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSeeInOrder(['Envois', 'ancien@client.fr', 'Échec', 'Le serveur d&#039;envoi des e-mails n&#039;a pas accepté le message.'], escape: false);
    }

    public function test_relancer_une_facture_echue_depuis_sa_fiche(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        $this->connecterLeProprietaire();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(['email' => 'client@exemple.fr']), '2026-10-01');

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->mountAction('relancer')
            ->assertActionDataSet(['destinataire' => 'client@exemple.fr'])
            ->assertSee("Relance n°1 : Ticket de caisse {$facture->numero}")
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $relance = $facture->envois()->sole();
        $this->assertSame([NatureEnvoi::Relance, 1], [$relance->nature, $relance->niveau_relance]);
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSeeInOrder(['Envois', 'client@exemple.fr', 'Relance', 'n°1']);
        Livewire::test(ListFactures::class)
            ->mountTableAction('relancer', $facture)
            ->assertSee("Relance n°2 : Ticket de caisse {$facture->numero}");
    }

    public function test_une_facture_pas_encore_echue_ne_se_relance_pas(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $this->connecterLeProprietaire();
        $facture = $this->emettreFacture(Client::factory()->create(['delai_paiement_jours' => 16]));

        Livewire::test(ListFactures::class)->assertTableActionHidden('relancer', $facture);
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])->assertActionHidden('relancer');
    }

    /**
     * Un devis encore brouillon reçoit son numéro en partant, et prévient
     * qu'il ne sera plus un brouillon.
     */
    public function test_envoyer_un_devis_brouillon_depuis_sa_fiche(): void
    {
        Mail::fake();
        $this->connecterLeProprietaire();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(['email' => 'client@exemple.fr']), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertActionHidden('telecharger_pdf')
            ->mountAction('envoyer_par_email')
            ->assertSee('Ce devis reçoit son numéro en partant')
            ->assertActionDataSet(['destinataire' => 'client@exemple.fr'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $devis->refresh();
        $this->assertSame(StatutDevis::Envoye, $devis->statut);
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr'));
        Livewire::test(ListDevis::class)
            ->assertTableActionVisible('telecharger_pdf', $devis)
            ->callTableAction('telecharger_pdf', $devis)
            ->assertFileDownloaded("devis-{$devis->numero}.pdf");
    }

    public function test_un_devis_sans_lignes_ne_part_pas_et_le_magasin_le_sait(): void
    {
        Mail::fake();
        $this->connecterLeProprietaire();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $devis->lignes()->delete();

        Livewire::test(ListDevis::class)
            ->callTableAction('envoyer_par_email', $devis, data: ['destinataire' => 'client@exemple.fr'])
            ->assertNotified('Impossible d\'envoyer un devis sans lignes.');

        $this->assertSame(StatutDevis::Brouillon, $devis->fresh()->statut);
        Mail::assertNothingSent();
    }

    public function test_envoyer_un_avoir_depuis_la_liste_et_depuis_sa_fiche(): void
    {
        Mail::fake();
        $this->connecterLeProprietaire();
        $facture = $this->emettreFacture(Client::factory()->create(['email' => 'client@exemple.fr']));
        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]));

        Livewire::test(ListAvoirs::class)
            ->callTableAction('envoyer_par_email', $avoir, data: ['destinataire' => 'client@exemple.fr'])
            ->assertHasNoTableActionErrors();
        Livewire::test(ViewAvoir::class, ['record' => $avoir->getRouteKey()])
            ->callAction('envoyer_par_email', data: ['destinataire' => 'autre@exemple.fr'])
            ->assertHasNoActionErrors()
            ->assertActionVisible('telecharger_pdf');

        Mail::assertSent(DocumentMail::class, 2);
        Livewire::test(ViewAvoir::class, ['record' => $avoir->getRouteKey()])
            ->assertSee('autre@exemple.fr');
    }

    /**
     * Un ticket numéroté directement en base n'a aucun enregistrement
     * signé : son PDF ne se télécharge pas, et l'écran dit pourquoi au lieu
     * d'une erreur.
     */
    public function test_le_pdf_d_un_document_numerote_sans_enregistrement_signe_ne_se_telecharge_pas(): void
    {
        Exceptions::fake([DocumentSansEnregistrementSigneException::class]);
        $magasin = $this->connecterLeProprietaire();
        $brouillon = app(CreerFactureAction::class)->handle($this->responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-MABOUT-2026-000009', 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);

        Livewire::test(ListFactures::class)
            ->callTableAction('telecharger_pdf', $brouillon->fresh())
            ->assertNotified('Le ticket TK-MABOUT-2026-000009 n\'a aucun enregistrement signé au registre fiscal : son PDF n\'est pas produit. Lancez la vérification de l\'intégrité (Ventes > Caisse sécurisée) et contactez le support.')
            ->assertNoFileDownloaded();

        Exceptions::assertReported(DocumentSansEnregistrementSigneException::class);
    }

    /**
     * Sans la permission d'envoyer les factures, ni l'envoi ni la relance
     * n'apparaissent -- même sur ses propres factures.
     */
    public function test_sans_la_permission_d_envoyer_les_actions_d_envoi_n_apparaissent_pas(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff(RoleMagasin::Vendeur->permissionsParDefaut(), [PermissionRegistry::FACTURE_ENVOYER])), ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr']);
        $this->actingAs($vendeur);
        Filament::setTenant($magasin);
        app(TenantContext::class)->set($magasin);
        $facture = $this->emettreFacture(Client::factory()->create(['delai_paiement_jours' => 0]), $vendeur);
        $this->travelTo('2026-10-15 10:00:00');

        Livewire::test(ListFactures::class)
            ->assertTableActionVisible('telecharger_pdf', $facture)
            ->assertTableActionHidden('envoyer_par_email', $facture)
            ->assertTableActionHidden('relancer', $facture);
    }

    /**
     * La fiche, et donc la section « Envois », d'une facture d'un autre
     * magasin n'existe pas pour lui : 404, sans même confirmer qu'elle
     * existe.
     */
    public function test_la_facture_d_un_autre_magasin_et_ses_envois_sont_introuvables(): void
    {
        $this->connecterLeProprietaire();
        $factureDeA = $this->emettreFacture(Client::factory()->create());
        EnvoiDocument::factory()->for($factureDeA, 'document')->envoye()->create(['destinataire' => 'secret@client-a.fr']);
        $proprietaireB = User::factory()->create();
        $magasinB = $this->ouvrirUneSociete(['nom' => 'Boutique B'], $proprietaireB);

        $this->actingAs($proprietaireB)
            ->get(FactureResource::getUrl('view', ['record' => $factureDeA], tenant: $magasinB))
            ->assertNotFound()
            ->assertDontSee('secret@client-a.fr');
    }

    private function connecterLeProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Une facture de 120,00 € TTC, émise par ce vendeur, sinon par le
     * responsable de test du magasin actif : le propriétaire connecté
     * l'envoie et la relance, mais ne vend jamais.
     */
    private function emettreFacture(Client $client, ?User $vendeur = null): Facture
    {
        $vendeur ??= $this->responsableDeTest(app(TenantContext::class)->magasin());
        $facture = app(CreerFactureAction::class)->handle($vendeur, $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreFactureAction::class)->handle($vendeur, $facture);
    }

    /**
     * Une facture de 120,00 € TTC émise le jour de son échéance, à un client
     * payable comptant (délai de 0 jour, B7) ; le test reprend ensuite à
     * l'instant où il en était.
     */
    private function emettreFactureEchueLe(Client $client, string $echeance): Facture
    {
        $maintenant = now()->toImmutable();
        $client->update(['delai_paiement_jours' => 0]);
        $this->travelTo("{$echeance} 10:00:00");
        $facture = $this->emettreFacture($client);
        $this->travelTo($maintenant);

        return $facture;
    }
}
