<?php

namespace Tests\Feature\Calendrier;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PeriodesDActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\PaiementResource\Pages\CreatePaiement;
use App\Filament\App\Widgets\FacturesApercu;
use App\Filament\Societe\Pages\LesCaisses;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La nuit, le jour de Paris n'est pas celui de l'UTC : de minuit à 2 h en
 * été, de minuit à 1 h en hiver. Tout ce qui se compte en jours (journée
 * de caisse, clôtures, échéances, validités, années des numéros, délais,
 * aperçus, filtres, limites) se compte en jours de Paris, à quelque heure
 * de la nuit qu'il se fasse.
 */
class NuitDeParisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pour chaque saison : le soir, à 23 h 30 à Paris, la nuit qui suit, à
     * 0 h 30 à Paris, écrits en UTC, puis le jour de Paris de cette nuit et
     * celui du soir.
     *
     * @return array<string, array{CarbonImmutable, CarbonImmutable, string, string}>
     */
    public static function nuits(): array
    {
        return [
            'nuit d\'été' => [self::enUtc('2026-07-14 21:30:00'), self::enUtc('2026-07-14 22:30:00'), '2026-07-15', '2026-07-14'],
            'nuit d\'hiver' => [self::enUtc('2026-11-15 22:30:00'), self::enUtc('2026-11-15 23:30:00'), '2026-11-16', '2026-11-15'],
        ];
    }

    #[DataProvider('nuits')]
    public function test_une_vente_apres_minuit_a_paris_appartient_a_la_journee_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($soir);
        $magasin = $this->magasinActif();
        $venteDuSoir = $this->vendre(12);
        $this->travelTo($nuit);
        $venteDeLaNuit = $this->vendre(30);

        $clotureDeLaVeille = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $veille);
        $clotureDuJour = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $jour);

        $this->assertSame([1, '12.00', 1, '30.00'], [$clotureDeLaVeille->nombre_ventes, $clotureDeLaVeille->total_ttc, $clotureDuJour->nombre_ventes, $clotureDuJour->total_ttc]);
        $this->assertSame([$clotureDeLaVeille->id, $clotureDuJour->id], [$venteDuSoir->fresh()->cloture_caisse_id, $venteDeLaNuit->fresh()->cloture_caisse_id]);
    }

    #[DataProvider('nuits')]
    public function test_la_caisse_refuse_une_vente_apres_la_cloture_du_jour_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($soir);
        $magasin = $this->magasinActif();
        $this->vendre(12);
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $veille);
        $this->travelTo($nuit);
        $this->vendre(12);

        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $jour);

        $this->assertThrows(fn () => $this->vendre(12), LogicException::class, 'La journée du '.Calendrier::afficherJour($jour, 'd/m/Y').' est clôturée : les ventes reprennent demain.');
    }

    #[DataProvider('nuits')]
    public function test_la_cloture_automatique_clot_la_journee_de_paris_qui_vient_de_finir(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($soir);
        $magasin = $this->magasinActif();
        $this->vendre(12);
        $this->travelTo($nuit);

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $this->assertSame([$veille], ClotureCaisse::query()->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
    }

    /**
     * La journée du dimanche 25 octobre 2026 dure 25 heures à Paris : la
     * caisse de Paul, fermée d'office à sa clôture, compte le ticket émis
     * à 23 h 59 min 59 s à Paris et son règlement du jour, mais ni le
     * ticket émis le lendemain à 0 h, ni son règlement ; elle s'arrête à
     * la dernière seconde de la journée, et le relevé le dit.
     */
    public function test_une_caisse_personnelle_fermee_d_office_couvre_la_journee_de_paris(): void
    {
        $this->travelTo(self::aParis('2026-10-25 10:00:00'));
        $magasin = $this->magasinActif();
        $paul = $this->membre($magasin, RoleMagasin::Vendeur);
        $paul->forceFill(['name' => 'Paul Martin'])->save();
        $this->travelTo(self::aParis('2026-10-25 23:59:59'));
        $duJour = $this->vendre(12, $paul);
        $this->travelTo(self::aParis('2026-10-26 00:00:00'));
        $duLendemain = $this->vendre(30, $paul);
        $this->travelTo(self::aParis('2026-10-26 00:01:00'));

        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();

        app(TenantContext::class)->set($magasin);
        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->sole();
        $this->assertSame([[$duJour->id], '12.00', ['especes' => '12.00']], [$releve->factures->modelKeys(), $releve->total_ttc, $releve->encaissements]);
        $this->assertMemeInstant(self::enUtc('2026-10-25 22:59:59'), $releve->jusqu_au);
        $this->assertTrue(Facture::query()->nonFermeesParLeVendeur($paul->id)->whereKey($duLendemain->id)->exists());
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(LesCaisses::class)
            ->set('jour', '2026-10-26')
            ->assertSee('→ 25/10/2026 23:59');
    }

    #[DataProvider('nuits')]
    public function test_un_paiement_recu_le_jour_de_paris_est_accepte_la_nuit(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($soir);
        $this->magasinActif();
        $facture = $this->emettreUneFacture(Client::factory()->create(), 120);
        $this->travelTo($nuit);
        $lendemain = Calendrier::ajouterJours($jour, 1);
        $payer = fn (string $recuLe) => app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => 60,
            'recu_le' => $recuLe,
        ], [['facture_id' => $facture->id, 'montant' => 60]]);

        $paiement = $payer($jour);

        $this->assertSame($jour, $paiement->recu_le);
        $this->assertThrows(fn () => $payer($lendemain), LogicException::class, 'Un paiement ne peut pas être reçu à une date future.');
    }

    /**
     * Une facture émise la nuit, payable à 30 jours, échoit 30 jours de
     * Paris plus tard ; elle passe en retard au passage de minuit à Paris
     * qui suit son échéance, et pas avant.
     */
    #[DataProvider('nuits')]
    public function test_l_echeance_et_le_retard_se_comptent_en_jours_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($nuit);
        $this->magasinActif();
        $facture = $this->emettreUneFacture(Client::factory()->create(['delai_paiement_jours' => 30]), 120);
        $echeance = Calendrier::ajouterJours($jour, 30);

        $this->assertSame($echeance, $facture->date_echeance);

        $this->travelTo(self::aParis("{$echeance} 23:30:00"));
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);

        $this->travelTo(self::aParis(Calendrier::ajouterJours($echeance, 1).' 00:30:00'));
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    /**
     * Une relance envoyée la nuit compte depuis son jour de Paris : six
     * jours plus tard, il s'est écoulé six jours depuis elle.
     */
    #[DataProvider('nuits')]
    public function test_les_relances_comptent_les_jours_depuis_la_derniere_relance_en_jours_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        Mail::fake();
        $this->travelTo(self::aParis("{$veille} 10:00:00"));
        $this->magasinActif();
        $facture = $this->emettreUneFacture(Client::factory()->create(['email' => 'client@exemple.fr', 'delai_paiement_jours' => 0]), 120);
        $this->travelTo($nuit);
        app(EnvoyerDocumentAction::class)->relancer(self::auteur(), $facture, 'client@exemple.fr');

        $this->travelTo(self::aParis(Calendrier::ajouterJours($jour, 6).' 10:00:00'));

        $this->assertSame(6, $facture->fresh()->joursDepuisLaDerniereRelance());
    }

    /**
     * Un devis valable jusqu'à un jour expire au passage de minuit à Paris
     * qui suit, et pas avant.
     */
    #[DataProvider('nuits')]
    public function test_un_devis_expire_le_lendemain_de_sa_validite_a_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo(self::aParis("{$veille} 10:00:00"));
        $this->magasinActif();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => $veille]));
        $this->travelTo($soir);
        $this->artisan('app:expirer-devis')->assertSuccessful();
        $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut);

        $this->travelTo($nuit);
        $this->artisan('app:expirer-devis')->assertSuccessful();

        $this->assertSame(StatutDevis::Expire, $devis->fresh()->statut);
    }

    /**
     * Le 1er janvier 2027 à 0 h 30 à Paris, encore le 31 décembre 2026 en
     * UTC : les huit séries de numéros (inventaire d'ouverture, facture
     * d'achat, avoir fournisseur, ticket, facture, avoir, devis, clôture)
     * prennent l'année 2027.
     */
    public function test_les_huit_series_de_numeros_prennent_l_annee_de_paris(): void
    {
        $this->travelTo(self::enUtc('2026-12-31 23:30:00'));
        $magasin = $this->magasinActif();
        $inventaire = app(ValiderInventaireOuvertureAction::class)->handle(self::auteur(), app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [
            ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
        ]));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 10],
        ]));
        $avoirFournisseur = app(EnregistrerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [['ligne_achat_id' => $factureAchat->lignes->sole()->id, 'quantite' => 1]], 'Retour', 'AV-0001');
        $client = Client::factory()->professionnel()->create();
        $ticket = $this->vendre(12);
        $facture = $this->emettreUneFacture($client, 60);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $ticket->client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2027-01-01');

        $this->assertSame(
            ['INV-LYON1-2027-000001', 'FA-LYON1-2027-000001', 'AF-LYON1-2027-000001', 'TK-LYON1-2027-000001', 'FAC-LYON1-2027-000001', 'AV-LYON1-2027-000001', 'DEV-LYON1-2027-000001', 'Z-LYON1-2027-000001'],
            [$inventaire->numero, $factureAchat->numero, $avoirFournisseur->numero, $ticket->numero, $facture->numero, $avoir->numero, $devis->numero, $cloture->numero],
        );
    }

    /**
     * Une vérification VIES faite la nuit compte pour son jour de Paris : une
     * livraison intracommunautaire émise le matin même la retient.
     */
    #[DataProvider('nuits')]
    public function test_la_consultation_vies_compte_pour_son_jour_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($nuit);
        $this->magasinActif();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create(['code_postal' => '10115', 'ville' => 'Berlin']);
        $consultation = ConsultationVies::forceCreate(['client_id' => $allemand->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'identifiant_consultation' => 'WAPIAAAAW1234567', 'consultee_le' => now(), 'reponse' => '{"valid":true}']);

        $this->assertSame([[], [$consultation->id]], [ConsultationVies::query()->duJour($veille)->pluck('id')->all(), ConsultationVies::query()->duJour($jour)->pluck('id')->all()]);

        $this->travelTo(self::aParis("{$jour} 10:00:00"));
        $facture = $this->emettreUneLivraisonIntracommunautaire($allemand, $jour);
        $this->assertSame($consultation->id, $facture->consultation_vies_id);
    }

    /**
     * L'attestation de réception d'une livraison du 12 octobre est attendue
     * au plus tard le 10 novembre : le délai n'est dépassé qu'au passage de
     * minuit à Paris qui suit, encore le 10 novembre en UTC.
     */
    public function test_le_delai_des_preuves_d_exoneration_se_compte_en_jours_de_paris(): void
    {
        $this->travelTo(self::aParis('2026-10-12 10:00:00'));
        $this->magasinActif();
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create(['code_postal' => '10115', 'ville' => 'Berlin']);
        ConsultationVies::forceCreate(['client_id' => $allemand->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'identifiant_consultation' => 'WAPIAAAAW1234567', 'consultee_le' => now(), 'reponse' => '{"valid":true}']);
        $facture = $this->emettreUneLivraisonIntracommunautaire($allemand, '2026-10-12');
        $attestation = static fn (): string => collect(app(PreuvesRequises::class)->manquantes($facture->fresh()))->sole(static fn (string $preuve): bool => str_contains($preuve, 'attendue au plus tard'));

        $this->travelTo(self::enUtc('2026-11-10 22:30:00'));
        $this->assertStringEndsWith('attendue au plus tard le 10/11/2026', $attestation());

        $this->travelTo(self::enUtc('2026-11-10 23:30:00'));
        $this->assertStringEndsWith('attendue au plus tard le 10/11/2026 : délai dépassé', $attestation());
    }

    /**
     * @return array<string, array{CarbonImmutable, CarbonImmutable, string}>
     */
    public static function debutsDePeriode(): array
    {
        return [
            'lundi 16 novembre, dimanche 15 en UTC' => [self::enUtc('2026-11-15 22:30:00'), self::enUtc('2026-11-15 23:30:00'), 'Cette semaine'],
            'samedi 1er août, vendredi 31 juillet en UTC' => [self::enUtc('2026-07-31 21:30:00'), self::enUtc('2026-07-31 22:30:00'), 'Ce mois-ci'],
            '1er janvier, 31 décembre en UTC' => [self::enUtc('2026-12-31 22:30:00'), self::enUtc('2026-12-31 23:30:00'), 'Cette année'],
        ];
    }

    /**
     * La vente de la nuit, à 0 h 30 à Paris, compte dans la semaine, le
     * mois ou l'année qui commence à Paris, pas la vente du soir qui
     * précède, à 23 h 30.
     */
    #[DataProvider('debutsDePeriode')]
    public function test_les_apercus_de_la_semaine_du_mois_et_de_l_annee_commencent_a_minuit_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $periode): void
    {
        $this->travelTo($soir);
        $magasin = $this->magasinActif();
        $this->vendre(12);
        $this->travelTo($nuit);
        $this->vendre(30);
        $this->actingAs(self::responsableDeTest($magasin));
        Filament::setTenant($magasin);

        Livewire::test(FacturesApercu::class)->assertSeeInOrder([$periode, '25,00 € HT', '30,00 € TTC · 1 facture']);
    }

    /**
     * Le filtre « Période » du journal des événements compte en jours de
     * Paris : l'événement de la nuit est du jour qui commence à Paris.
     */
    #[DataProvider('nuits')]
    public function test_le_filtre_du_journal_des_evenements_compte_les_jours_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($soir);
        $magasin = $this->magasinActif();
        $duSoir = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        $this->travelTo($nuit);
        $deLaNuit = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->filterTable('periode', ['du' => $jour, 'au' => $jour])
            ->assertCanSeeTableRecords([$deLaNuit])
            ->assertCanNotSeeTableRecords([$duSoir]);
    }

    /**
     * La limite de comptes que le propriétaire crée chaque jour repart au
     * minuit de Paris : atteinte le soir, elle ne l'est plus la nuit.
     */
    #[DataProvider('nuits')]
    public function test_la_limite_de_comptes_crees_par_jour_compte_les_jours_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($soir);
        $magasin = $this->magasinActif();
        $proprietaire = $this->proprietaireDe($magasin);
        $limite = CreationDesComptes::limiteDuJour($magasin->societe);
        $dejaCrees = User::query()->where('cree_par', $proprietaire->id)->count();
        User::factory()->membreDe($magasin)->count($limite - $dejaCrees)->create(['cree_par' => $proprietaire->id]);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'De trop'], RoleMagasin::Vendeur), ValidationException::class, CreationDesComptes::messageDeLaLimite($limite));

        $this->travelTo($nuit);

        $this->assertTrue(app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Après minuit'], RoleMagasin::Vendeur)->exists);
    }

    /**
     * L'aperçu de l'e-reporting de la journée compte l'encaissement d'un
     * service payé la nuit au jour de Paris ; une période d'activité close
     * la nuit finit ce jour-là.
     */
    #[DataProvider('nuits')]
    public function test_l_e_reporting_et_les_periodes_d_activite_comptent_les_jours_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($nuit);
        $magasin = $this->magasinActif();
        $pose = Service::factory()->create();
        self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['service_id' => $pose->id, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20, 'quantite' => 1]]);
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, $jour);

        $apercu = app(EReportingDesParticuliers::class)->apercu($cloture);
        $this->assertSame([$jour, [$jour]], [$apercu->journee, array_column($apercu->encaissementsDeServices, 'date')]);

        app(FermerMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin->fresh(), MotifFermeture::Temporaire, 'password');
        $this->assertSame([['2026-01-01', $jour]], app(PeriodesDActivite::class)->jours($magasin));
    }

    #[DataProvider('nuits')]
    public function test_le_pdf_d_une_vente_a_0_h_30_a_paris_porte_la_date_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($nuit);
        $this->magasinActif();

        $ticket = $this->vendre(12);

        $html = app(FacturePdfGenerator::class)->html($ticket->load('client', 'lignes', 'magasin'));
        $this->assertStringContainsString('Émise le '.Calendrier::afficherJour($jour, 'd/m/Y'), $html);
    }

    /**
     * La date proposée pour un paiement, et la plus tardive acceptée, est
     * le jour de Paris, même la nuit.
     */
    #[DataProvider('nuits')]
    public function test_les_choix_de_date_proposent_le_jour_de_paris(CarbonImmutable $soir, CarbonImmutable $nuit, string $jour, string $veille): void
    {
        $this->travelTo($nuit);
        $magasin = $this->magasinActif();
        $this->actingAs(self::responsableDeTest($magasin));
        Filament::setTenant($magasin);

        Livewire::test(CreatePaiement::class)
            ->assertFormSet(['recu_le' => $jour])
            ->fillForm(['recu_le' => Calendrier::ajouterJours($jour, 1)])
            ->call('create')
            ->assertHasFormErrors(['recu_le' => 'before_or_equal']);
    }

    /**
     * Le magasin LYON1 d'une société validée, à l'identité légale complète,
     * magasin actif.
     */
    private function magasinActif(): Magasin
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'LYON1']));
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Un ticket de ce montant à 20 % à un particulier, réglé en espèces à la
     * caisse, par ce vendeur ou par le responsable de test.
     */
    private function vendre(float $prixTtc, ?User $vendeur = null): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return self::vendreAuComptant($vendeur ?? self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
    }

    private function emettreUneFacture(Client $client, float $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    private function emettreUneLivraisonIntracommunautaire(Client $client, string $livraison): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 25, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 12]], [
            'nature_operation' => NatureOperation::LivraisonIntracommunautaire,
            'livraison_adresse' => 'Musterstraße 1',
            'livraison_code_postal' => '10115',
            'livraison_ville' => 'Berlin',
            'livraison_pays' => 'DE',
            'livraison_date' => $livraison,
            'transport_par' => TransportPar::Acquereur,
        ]));
    }
}
