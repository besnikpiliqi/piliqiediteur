<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\CaisseSecurisee\Actions\VerifierIntegriteAction;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Enums\DeclenchementVerification;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Pages\IdentiteMagasin;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\MembreEquipeResource;
use App\Filament\App\Resources\MembreEquipeResource\Pages\ListMembreEquipes;
use App\Filament\App\Resources\ProduitResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un magasin suspendu ou fermé passe en lecture seule (voir
 * StatutMagasin, ControleDesDroits et Magasin::verifierQuIlEstOuvert()) :
 * consultation et téléchargements possibles, plus aucune vente ni aucun
 * e-mail aux clients ; ses clôtures, les statuts de ses factures et ses
 * déclarations continuent. Ses ventes ont toutes été faites avant le
 * changement de statut.
 */
class MagasinEnLectureSeuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: StatutMagasin}>
     */
    public static function statutsEnLectureSeule(): array
    {
        return [
            'suspendu' => [StatutMagasin::Suspendu],
            'fermé' => [StatutMagasin::Ferme],
        ];
    }

    /**
     * Chaque statut et le mot qui le nomme dans les messages.
     *
     * @return array<string, array{0: StatutMagasin, 1: string}>
     */
    public static function statutsEnLectureSeuleEtLeurNom(): array
    {
        return [
            'suspendu' => [StatutMagasin::Suspendu, 'suspendu'],
            'fermé' => [StatutMagasin::Ferme, 'fermé'],
        ];
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_le_magasin_reste_consultable_et_l_annonce(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $statut);

        $this->get(FactureResource::getUrl('index'))
            ->assertSee($ticket->numero)
            ->assertSee("Magasin {$libelle} : consultation et téléchargements seulement.")
            ->assertSee('Les clôtures de caisse et les déclarations continuent.');
        $this->get(FactureResource::getUrl('view', ['record' => $ticket]))->assertSee($ticket->numero);
        $this->get(ProduitResource::getUrl('index'))->assertSee('Coque rouge');
    }

    public function test_un_magasin_actif_n_annonce_aucune_lecture_seule(): void
    {
        $this->ouvrirLeMagasin();

        $this->get(FactureResource::getUrl('index'))->assertDontSee('consultation et téléchargements seulement');
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_aucune_saisie_n_est_possible(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $produit = Produit::factory()->create();
        $this->passerEn($magasin, $statut);

        $this->get(Caisse::getUrl())->assertForbidden();
        $this->get(FactureResource::getUrl('create'))->assertForbidden();
        $this->get(ProduitResource::getUrl('edit', ['record' => $produit]))->assertForbidden();
        $this->get(IdentiteMagasin::getUrl())->assertForbidden();
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_le_pdf_d_une_vente_emise_se_telecharge_mais_ne_s_envoie_plus(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $statut);

        $telechargement = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertActionHidden('envoyer_par_email')
            ->callAction('telecharger_pdf')
            ->assertFileDownloaded("ticket-{$ticket->numero}.pdf");

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_la_journee_se_cloture_toujours_a_la_main(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travel(-1)->days();
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin));
        $this->travelBack();
        $this->passerEn($magasin, $statut);

        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame([1, '12.00'], [$cloture->nombre_ventes, $cloture->total_ttc]);
    }

    /**
     * Le vendeur n'a pas le droit de clôturer la journée, seulement celui
     * de vendre, que le magasin en lecture seule n'exerce plus : il ferme
     * pourtant sa caisse, puisque la clôture de la journée l'attend.
     */
    #[DataProvider('statutsEnLectureSeule')]
    public function test_le_vendeur_ferme_toujours_sa_caisse(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        $this->vendreUnTicket();
        $this->passerEn($magasin, $statut);

        Livewire::test(ListClotureCaissePersonnelles::class)->callAction('fermer_ma_caisse');

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([$vendeur->id, 1, '12.00'], [$releve->cree_par, $releve->nombre_ventes, $releve->total_ttc]);
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_l_equipe_reste_consultable_et_ouverte_aux_nouveaux_comptes(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->passerEn($magasin, $statut);

        $this->get(MembreEquipeResource::getUrl('index'))->assertSee($vendeur->email);
        $this->get(MembreEquipeResource::getUrl('create'))->assertOk();
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_un_acces_se_coupe_toujours(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $saLigne = $magasin->membres()->where('user_id', $vendeur->id)->sole();
        $this->passerEn($magasin, $statut);

        Livewire::test(ListMembreEquipes::class)->callTableAction('retirer', $saLigne);

        $this->assertModelMissing($saLigne);
        $this->assertFalse($vendeur->fresh()->appartientA($magasin));
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_une_vente_ne_s_emet_plus(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle($brouillon), LogicException::class, $this->refus($libelle));

        $brouillon->refresh();
        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->statut, $brouillon->numero]);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente)->count());
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_un_paiement_ne_s_encaisse_plus(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(EnregistrerPaiementAction::class)->handle($ticket->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $ticket->id, 'montant' => 12]]), LogicException::class, $this->refus($libelle));

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $ticket->fresh()->statut);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement)->count());
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_un_avoir_ne_s_emet_plus(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $avoir = app(CreerAvoirAction::class)->handle($ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(EmettreAvoirAction::class)->handle($avoir), LogicException::class, $this->refus($libelle));

        $avoir->refresh();
        $this->assertSame([StatutAvoir::Brouillon, null], [$avoir->statut, $avoir->numero]);
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente)->count());
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_une_facture_d_achat_ne_se_valide_plus(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $produit = Produit::factory()->create(['suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20],
        ]);
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(ValiderFactureAchatAction::class)->handle($factureAchat), LogicException::class, $this->refus($libelle));

        $factureAchat->refresh();
        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->statut, $factureAchat->numero]);
        $this->assertSame(0, $produit->fresh()->stock_quantite);
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_un_avoir_fournisseur_ne_s_emet_plus(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $produit = Produit::factory()->create(['suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(app(CreerFactureAchatAction::class)->handle(Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20],
        ]));
        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(EmettreAvoirFournisseurAction::class)->handle($avoir), LogicException::class, $this->refus($libelle));

        $avoir->refresh();
        $this->assertSame([StatutAvoirFournisseur::Brouillon, null], [$avoir->statut, $avoir->numero]);
        $this->assertSame(3, $produit->fresh()->stock_quantite);
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_un_devis_ne_se_numerote_plus(StatutMagasin $statut, string $libelle): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(EmettreDevisAction::class)->handle($devis), LogicException::class, $this->refus($libelle));

        $devis->refresh();
        $this->assertSame([StatutDevis::Brouillon, null], [$devis->statut, $devis->numero]);
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_aucun_document_ne_s_envoie_plus(StatutMagasin $statut, string $libelle): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $statut);

        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->handle($ticket, 'client@exemple.fr', null, $this->proprietaireDe($magasin)), LogicException::class, $this->refus($libelle));

        Queue::assertNotPushed(EnvoyerDocumentJob::class);
        $this->assertSame(0, EnvoiDocument::query()->count());
        $this->assertSame(StatutFacture::Emise, $ticket->fresh()->statut);
    }

    #[DataProvider('statutsEnLectureSeuleEtLeurNom')]
    public function test_aucune_facture_ne_se_relance_plus_a_la_main(StatutMagasin $statut, string $libelle): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $facture = $this->factureEchueLe('2026-10-01');
        $this->passerEn($magasin, $statut);
        $this->travelTo('2026-10-20 09:00:00');

        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', $this->proprietaireDe($magasin)), LogicException::class, $this->refus($libelle));

        Queue::assertNotPushed(EnvoyerDocumentJob::class);
        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_la_cloture_automatique_traite_le_magasin(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travel(-1)->days();
        $ticket = $this->vendreUnTicket();
        $this->travelBack();
        $this->passerEn($magasin, $statut);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame([1, '12.00'], [$cloture->nombre_ventes, $cloture->total_ttc]);
        $this->assertSame($cloture->id, $ticket->fresh()->cloture_caisse_id);
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_les_factures_en_retard_sont_marquees(StatutMagasin $statut): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $facture = $this->factureEchueLe('2026-10-01');
        $this->passerEn($magasin, $statut);
        $this->travelTo('2026-10-03 00:15:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    #[DataProvider('statutsEnLectureSeule')]
    public function test_aucune_relance_automatique_n_est_envoyee(StatutMagasin $statut): void
    {
        Mail::fake();
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $magasin->update(['relances_automatiques' => true, 'delais_relance' => [7, 15, 30]]);
        $this->factureEchueLe('2026-10-01');
        $this->passerEn($magasin, $statut);
        $this->travelTo('2026-10-20 09:00:00');

        $this->artisan('app:relancer-factures-impayees')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    /**
     * Les événements techniques continuent : la vérification de la caisse
     * sécurisée s'inscrit au journal, et ne relève aucune anomalie.
     */
    #[DataProvider('statutsEnLectureSeule')]
    public function test_la_verification_d_integrite_continue_sans_anomalie(StatutMagasin $statut): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        $this->passerEn($magasin, $statut);

        $rapport = app(VerifierIntegriteAction::class)->handle($magasin, true, DeclenchementVerification::Commande);

        $this->assertTrue($rapport->estConforme());
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement)->where('type', (string) CodeEvenement::OperationDeControle->value)->count());
    }

    /**
     * Le magasin du propriétaire connecté, magasin actif du panneau.
     */
    private function ouvrirLeMagasin(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Le magasin actif (voir TenantContext) est bien celui dont le statut
     * change : les droits vérifiés ensuite dans le test lisent son nouveau
     * statut.
     */
    private function passerEn(Magasin $magasin, StatutMagasin $statut): void
    {
        $magasin->forceFill(['statut' => $statut])->save();
        $this->assertSame($magasin, app(TenantContext::class)->magasin());
    }

    /**
     * Un produit vendu 12,00 € TTC, sans suivi de stock.
     */
    private function produit(): Produit
    {
        return Produit::factory()->create(['nom' => 'Coque rouge', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Un ticket de 12,00 € TTC, émis par l'utilisateur connecté, pas encore
     * réglé.
     */
    private function vendreUnTicket(): Facture
    {
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreFactureAction::class)->handle($brouillon);
    }

    /**
     * Une facture professionnelle de 12,00 € TTC, impayée, échue le jour
     * donné.
     */
    private function factureEchueLe(string $echeance): Facture
    {
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->professionnel()->create(['email' => 'client@exemple.fr']), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
        ], ['date_echeance' => $echeance]);

        return app(EmettreFactureAction::class)->handle($brouillon);
    }

    private function refus(string $libelle): string
    {
        return "Ce magasin est {$libelle} : seules la consultation et les téléchargements restent possibles.";
    }
}
