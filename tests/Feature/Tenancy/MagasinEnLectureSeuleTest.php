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
use App\Domain\Facturation\Enums\CategorieEReporting;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Pages\ParametresFactures;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
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
 * Un magasin passe en lecture seule (voir Magasin::estEnLectureSeule(),
 * ControleDesDroits et Magasin::verifierQuIlEstOuvert()) quand il est
 * fermé ou suspendu, quand sa société est suspendue, résiliée ou
 * révoquée, ou quand l'inscription de sa société est refusée :
 * consultation et téléchargements possibles, plus aucune vente ni aucun
 * e-mail aux clients ; ses clôtures, les statuts de ses factures et ses
 * déclarations continuent. Ses ventes ont toutes été faites avant ce
 * changement.
 */
class MagasinEnLectureSeuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque situation de lecture seule, et ce qui l'y met tel que les
     * messages le disent.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function situationsEnLectureSeule(): array
    {
        return [
            'magasin suspendu' => ['magasin suspendu', 'Ce magasin est suspendu'],
            'magasin fermé' => ['magasin fermé', 'Ce magasin est fermé'],
            'société suspendue' => ['société suspendue', 'Ce magasin est en lecture seule, votre société étant suspendue'],
            'société résiliée' => ['société résiliée', 'Ce magasin est en lecture seule, votre société étant résiliée'],
            'société révoquée' => ['société révoquée', 'Ce magasin est en lecture seule, votre société étant révoquée'],
            'inscription refusée' => ['inscription refusée', 'Ce magasin est en lecture seule, l\'inscription de votre société ayant été refusée'],
        ];
    }

    /**
     * Les situations d'une société qui a été validée : une société dont
     * l'inscription est refusée ne l'a jamais été ; elle n'a donc rien
     * vendu ni déclaré, et aucun de ses documents ne s'imprime (voir
     * Magasin::verifierQuIlPeutImprimer()).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function situationsApresLaValidation(): array
    {
        return array_diff_key(self::situationsEnLectureSeule(), ['inscription refusée' => true]);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_le_magasin_reste_consultable_et_l_annonce(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);

        $this->get(FactureResource::getUrl('index'))
            ->assertSee($ticket->numero)
            ->assertSee("{$motif} : consultation et téléchargements seulement.")
            ->assertSee('Les clôtures de caisse et les déclarations continuent.');
        $this->get(FactureResource::getUrl('view', ['record' => $ticket]))->assertSee($ticket->numero);
        $this->get(ProduitResource::getUrl('index'))->assertSee('Coque rouge');
    }

    public function test_un_magasin_actif_n_annonce_aucune_lecture_seule(): void
    {
        $this->ouvrirLeMagasin();

        $this->get(FactureResource::getUrl('index'))->assertDontSee('consultation et téléchargements seulement');
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_aucune_saisie_n_est_possible(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $produit = Produit::factory()->create();
        $this->passerEn($magasin, $situation);

        $this->get(Caisse::getUrl())->assertForbidden();
        $this->get(FactureResource::getUrl('create'))->assertForbidden();
        $this->get(ProduitResource::getUrl('edit', ['record' => $produit]))->assertForbidden();
        $this->get(ParametresFactures::getUrl())->assertForbidden();
    }

    #[DataProvider('situationsApresLaValidation')]
    public function test_le_pdf_d_une_vente_emise_se_telecharge_mais_ne_s_envoie_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);

        $telechargement = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->assertActionHidden('envoyer_par_email')
            ->callAction('telecharger_pdf')
            ->assertFileDownloaded("ticket-{$ticket->numero}.pdf");

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_la_journee_se_cloture_toujours_a_la_main(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $this->proprietaireDe($magasin));
        $this->passerEn($magasin, $situation);

        Livewire::test(ListClotureCaisses::class)->callAction('cloturer');

        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame([today()->toDateString(), 1, '12.00'], [$cloture->date_cloture->toDateString(), $cloture->nombre_ventes, $cloture->total_ttc]);
    }

    /**
     * Le vendeur n'a pas le droit de clôturer la journée, seulement celui
     * de vendre, que le magasin en lecture seule n'exerce plus : il ferme
     * pourtant sa caisse, puisque la clôture de la journée l'attend.
     */
    #[DataProvider('situationsEnLectureSeule')]
    public function test_le_vendeur_ferme_toujours_sa_caisse(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);

        Livewire::test(ListClotureCaissePersonnelles::class)->callAction('fermer_ma_caisse');

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([$vendeur->id, 1, '12.00'], [$releve->vendeur_id, $releve->nombre_ventes, $releve->total_ttc]);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_une_vente_ne_s_emet_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon), LogicException::class, $this->refus($motif));

        $brouillon->refresh();
        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->statut, $brouillon->numero]);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente)->count());
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_un_paiement_ne_s_encaisse_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(EnregistrerPaiementAction::class)->handle(self::auteur(), $ticket->client, [
            'mode_paiement' => ModePaiement::Especes->value,
            'montant' => 12,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $ticket->id, 'montant' => 12]]), LogicException::class, $this->refus($motif));

        $this->assertSame(0, Paiement::query()->count());
        $this->assertSame(StatutFacture::Emise, $ticket->fresh()->statut);
        $this->assertSame(0, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement)->count());
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_un_avoir_ne_s_emet_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir), LogicException::class, $this->refus($motif));

        $avoir->refresh();
        $this->assertSame([StatutAvoir::Brouillon, null], [$avoir->statut, $avoir->numero]);
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente)->count());
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_une_facture_d_achat_ne_se_valide_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $produit = Produit::factory()->create(['suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20],
        ]);
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat), LogicException::class, $this->refus($motif));

        $factureAchat->refresh();
        $this->assertSame([StatutFactureAchat::Brouillon, null], [$factureAchat->statut, $factureAchat->numero]);
        $this->assertSame(0, $produit->fresh()->stock_quantite);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_un_avoir_fournisseur_ne_s_emet_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $produit = Produit::factory()->create(['suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20],
        ]));
        $avoir = app(CreerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(EmettreAvoirFournisseurAction::class)->handle(self::auteur(), $avoir), LogicException::class, $this->refus($motif));

        $avoir->refresh();
        $this->assertSame([StatutAvoirFournisseur::Brouillon, null], [$avoir->statut, $avoir->numero]);
        $this->assertSame(3, $produit->fresh()->stock_quantite);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_un_devis_ne_se_numerote_plus(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(EmettreDevisAction::class)->handle(self::auteur(), $devis), LogicException::class, $this->refus($motif));

        $devis->refresh();
        $this->assertSame([StatutDevis::Brouillon, null], [$devis->statut, $devis->numero]);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_aucun_document_ne_s_envoie_plus(string $situation, string $motif): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);

        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->handle($this->proprietaireDe($magasin), $ticket, 'client@exemple.fr', null), LogicException::class, $this->refus($motif));

        Queue::assertNotPushed(EnvoyerDocumentJob::class);
        $this->assertSame(0, EnvoiDocument::query()->count());
        $this->assertSame(StatutFacture::Emise, $ticket->fresh()->statut);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_aucune_facture_ne_se_relance_plus_a_la_main(string $situation, string $motif): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = $this->completerIdentiteLegale($this->ouvrirLeMagasin());
        $facture = $this->factureEchueAujourdhui();
        $this->passerEn($magasin, $situation);
        $this->travelTo('2026-10-20 09:00:00');

        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->relancer($this->proprietaireDe($magasin), $facture, 'client@exemple.fr'), LogicException::class, $this->refus($motif));

        Queue::assertNotPushed(EnvoyerDocumentJob::class);
        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_la_cloture_automatique_traite_le_magasin(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->travel(-1)->days();
        $ticket = $this->vendreUnTicket();
        $this->travelBack();
        $this->passerEn($magasin, $situation);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $cloture = ClotureCaisse::query()->sole();
        $this->assertSame([1, '12.00'], [$cloture->nombre_ventes, $cloture->total_ttc]);
        $this->assertSame($cloture->id, $ticket->fresh()->cloture_caisse_id);
    }

    /**
     * Les ventes du jour du changement de statut sont clôturées à minuit,
     * et leur journée reste à déclarer, avec l'aperçu de ce que
     * l'e-reporting déclarera (le ticket de 12,00 € à 20 %).
     */
    #[DataProvider('situationsApresLaValidation')]
    public function test_les_dernieres_ventes_sont_declarees(string $situation, string $motif): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);
        $this->travelTo('2026-10-06 00:00:00');

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        $cloture = EReportingDesParticuliers::journeesADeclarer($magasin->fresh())->sole();
        $transactions = app(EReportingDesParticuliers::class)->apercu($cloture)->transactions;
        $this->assertSame('2026-10-05', $cloture->date_cloture->toDateString());
        $this->assertSame([[CategorieEReporting::LivraisonsDeBiens, 2000, 1000, 200]], array_map(fn (array $transaction): array => [$transaction['categorie'], $transaction['taux'], $transaction['ht'], $transaction['tva']], $transactions));
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_les_factures_en_retard_sont_marquees(string $situation, string $motif): void
    {
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = $this->completerIdentiteLegale($this->ouvrirLeMagasin());
        $facture = $this->factureEchueAujourdhui();
        $this->passerEn($magasin, $situation);
        $this->travelTo('2026-10-03 00:15:00');

        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    #[DataProvider('situationsEnLectureSeule')]
    public function test_aucune_relance_automatique_n_est_envoyee(string $situation, string $motif): void
    {
        Mail::fake();
        $this->travelTo('2026-10-01 09:00:00');
        $magasin = $this->completerIdentiteLegale($this->ouvrirLeMagasin());
        $magasin->update(['relances_automatiques' => true, 'delais_relance' => [7, 15, 30]]);
        $this->factureEchueAujourdhui();
        $this->passerEn($magasin, $situation);
        $this->travelTo('2026-10-20 09:00:00');

        $this->artisan('app:relancer-factures-impayees')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    /**
     * Les événements techniques continuent : la vérification de la caisse
     * sécurisée s'inscrit au journal, et ne relève aucune anomalie.
     */
    #[DataProvider('situationsEnLectureSeule')]
    public function test_la_verification_d_integrite_continue_sans_anomalie(string $situation, string $motif): void
    {
        $magasin = $this->ouvrirLeMagasin();
        $this->vendreUnTicket();
        $this->passerEn($magasin, $situation);

        $rapport = app(VerifierIntegriteAction::class)->parLeSysteme($magasin, true);

        $this->assertTrue($rapport->estConforme());
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement)->where('type', (string) CodeEvenement::OperationDeControle->value)->count());
    }

    /**
     * L'envoi a été programmé, puis la société suspendue avant qu'il ne
     * parte (file d'attente chargée, nouvelle tentative) : le magasin
     * n'écrit plus à ses clients, et il sait pourquoi.
     */
    public function test_un_envoi_en_file_ne_part_pas_si_la_societe_a_ete_suspendue_entre_temps(): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->ouvrirLeMagasin();
        $ticket = $this->vendreUnTicket();
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $ticket, 'client@exemple.fr', null);
        $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
        $envoi->refresh();
        $this->assertSame(
            [StatutEnvoi::Echec, 'Le magasin est en lecture seule, votre société étant suspendue : il n\'écrit plus à ses clients, l\'e-mail n\'est pas parti.'],
            [$envoi->statut, $envoi->erreur],
        );
    }

    /**
     * Les relances automatiques n'écrivent qu'au nom d'un magasin qui peut
     * vendre : ni celui d'une société suspendue, ni celui d'une société
     * qui n'est pas (ou plus) validée.
     */
    public function test_aucune_relance_automatique_pour_une_societe_suspendue_ou_non_validee(): void
    {
        Mail::fake();
        $this->travelTo('2026-10-01 09:00:00');
        $suspendue = $this->completerIdentiteLegale($this->ouvrirLeMagasin());
        $suspendue->update(['relances_automatiques' => true, 'delais_relance' => [7, 15, 30]]);
        $this->factureEchueAujourdhui();
        $nonValidee = $this->completerIdentiteLegale($this->ouvrirLeMagasin());
        $nonValidee->update(['relances_automatiques' => true, 'delais_relance' => [7, 15, 30]]);
        $this->factureEchueAujourdhui();
        $suspendue->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $nonValidee->societe->forceFill(['validee_le' => null])->save();
        $this->travelTo('2026-10-20 09:00:00');

        $this->artisan('app:relancer-factures-impayees')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, EnvoiDocument::query()->withoutGlobalScopes()->count());
    }

    /**
     * Le magasin du propriétaire connecté, magasin actif du panneau.
     */
    private function ouvrirLeMagasin(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Met le magasin dans la situation de lecture seule, par son statut,
     * sa suspension ou sa société. Le magasin actif (voir TenantContext)
     * est bien celui qui change : les droits vérifiés ensuite dans le test
     * lisent sa nouvelle situation.
     */
    private function passerEn(Magasin $magasin, string $situation): void
    {
        match ($situation) {
            'magasin suspendu' => $magasin->forceFill(['suspendu_le' => now()])->save(),
            'magasin fermé' => $magasin->forceFill(['statut' => StatutMagasin::Ferme])->save(),
            'société suspendue' => $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save(),
            'société résiliée' => $magasin->societe->forceFill(['statut' => StatutSociete::Resiliee])->save(),
            'société révoquée' => $magasin->societe->forceFill(['statut' => StatutSociete::Revoquee])->save(),
            'inscription refusée' => $magasin->societe->forceFill(['validee_le' => null, 'refusee_le' => now()])->save(),
        };

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
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);
    }

    /**
     * Une facture professionnelle de 12,00 € TTC, impayée, émise maintenant
     * à un client payable comptant : échue le jour même (B7).
     */
    private function factureEchueAujourdhui(): Facture
    {
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(['email' => 'client@exemple.fr', 'delai_paiement_jours' => 0]), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
        ]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon);
    }

    private function refus(string $motif): string
    {
        return "{$motif} : seules la consultation et les téléchargements restent possibles.";
    }
}
