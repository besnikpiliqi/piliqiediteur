<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La commande caisse:verifier : chaque magasin qui a des données fiscales,
 * ou celui qu'on lui désigne, vérifié et journalisé ; un échec au moindre
 * défaut ; une vérification complète une fois par mois, au jour propre à
 * chaque magasin ; un passage chaque nuit à 3 h, heure de Paris.
 */
class VerifierIntegriteFiscaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un magasin qui a vendu un article : son registre, son ticket et son
     * paiement.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function magasinQuiAVendu(array $attributs = []): Magasin
    {
        $magasin = Magasin::factory()->create($attributs);

        app(TenantContext::class)->runAs($magasin, function (): void {
            $this->vendre();
        });

        return $magasin;
    }

    private function vendre(): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EnregistrerVenteCaisseAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
    }

    /**
     * Les opérations de contrôle inscrites au journal du magasin, de la
     * plus ancienne à la plus récente : leurs détails signés.
     *
     * @return list<array<string, mixed>>
     */
    private function controlesDe(Magasin $magasin): array
    {
        return EnregistrementFiscal::query()
            ->withoutGlobalScopes()
            ->where('magasin_id', $magasin->id)
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', '280')
            ->orderBy('sequence')
            ->get()
            ->map(static fn (EnregistrementFiscal $controle): array => $controle->contenuSigne()['donnees']['details'])
            ->all();
    }

    public function test_un_registre_intact_fait_reussir_la_commande_qui_inscrit_son_controle(): void
    {
        $this->travelTo('2026-10-02 03:00:00');
        $magasin = $this->magasinQuiAVendu(['nom' => 'Boutique Lefèvre']);

        $this->artisan('caisse:verifier')
            ->expectsTable(['Magasin', 'Vérification', 'Enregistrements vérifiés', 'Anomalies'], [['Boutique Lefèvre', 'Complète', '3', 'Aucune']])
            ->expectsOutputToContain('Intégrité de la caisse sécurisée vérifiée : aucune anomalie.')
            ->assertSuccessful();

        $controles = $this->controlesDe($magasin);
        $this->assertCount(1, $controles);
        $this->assertSame(['commande', 'conforme'], [$controles[0]['declenchement'], $controles[0]['resultat']]);
        $this->assertNull(EnregistrementFiscal::query()->withoutGlobalScopes()->where('magasin_id', $magasin->id)->where('type', '280')->sole()->operateur_id);
    }

    public function test_la_moindre_anomalie_fait_echouer_la_commande_et_l_affiche(): void
    {
        $magasin = $this->magasinQuiAVendu(['nom' => 'Boutique Lefèvre']);
        DB::table('sequences_documents')->where('magasin_id', $magasin->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 3]);

        $this->artisan('caisse:verifier')
            ->expectsOutputToContain('Boutique Lefèvre [95] Série TK-2026- : 1 numéro(s) attribué(s) sans document : TK-2026-000002.')
            ->expectsOutputToContain('Intégrité de la caisse sécurisée : 1 magasin(s) en défaut.')
            ->assertFailed();

        $this->assertSame('anomalies', $this->controlesDe($magasin)[0]['resultat']);
    }

    /**
     * Une clé retirée du dépôt rend suspect chaque enregistrement, ici
     * 105 : la vente, son encaissement et 103 événements. La sortie n'en
     * liste que cent, comme le journal des événements, jusqu'au 98e
     * événement.
     */
    public function test_la_commande_ne_liste_que_les_cent_premieres_anomalies_d_un_magasin(): void
    {
        $magasin = $this->magasinQuiAVendu(['nom' => 'Boutique Lefèvre']);
        app(TenantContext::class)->runAs($magasin, fn () => DB::transaction(function (): void {
            foreach (range(1, 102) as $export) {
                app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees, ['export' => $export]);
            }
        }));
        $nouvellePaire = SignataireFiscal::genererUnePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($nouvellePaire['cle_privee']),
            'caisse_securisee.cles_publiques' => ['nouvelle.pem' => $nouvellePaire['cle_publique']],
        ]);
        $this->app->forgetInstance(RegistreFiscal::class);

        $this->artisan('caisse:verifier')
            ->expectsOutputToContain('Boutique Lefèvre [90] Enregistrement n° 98 du journal des événements')
            ->doesntExpectOutputToContain('Enregistrement n° 99 du journal des événements')
            ->expectsOutputToContain('Boutique Lefèvre : et 5 autre(s) anomalie(s).')
            ->assertFailed();
    }

    /**
     * Le magasin désigné, par son uuid ou son identifiant, est le seul
     * vérifié : le journal d'un autre magasin, même en défaut, n'est
     * jamais touché.
     */
    public function test_la_commande_ne_verifie_que_le_magasin_designe(): void
    {
        $magasinA = $this->magasinQuiAVendu();
        $magasinB = $this->magasinQuiAVendu();
        DB::table('sequences_documents')->where('magasin_id', $magasinB->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 3]);
        $registreDeB = DB::table('enregistrements_fiscaux')->where('magasin_id', $magasinB->id)->count();

        $this->artisan('caisse:verifier', ['magasin' => $magasinA->uuid])->assertSuccessful();
        $this->artisan('caisse:verifier', ['magasin' => (string) $magasinA->id])->assertSuccessful();

        $this->assertCount(2, $this->controlesDe($magasinA));
        $this->assertSame($registreDeB, DB::table('enregistrements_fiscaux')->where('magasin_id', $magasinB->id)->count());
    }

    public function test_un_magasin_inconnu_fait_echouer_la_commande(): void
    {
        $this->artisan('caisse:verifier', ['magasin' => 'inconnu'])
            ->expectsOutputToContain('Aucun magasin « inconnu ».')
            ->assertFailed();

        $this->assertDatabaseCount('enregistrements_fiscaux', 0);
    }

    /**
     * Un magasin qui n'a encore rien vendu n'a rien à vérifier : la
     * commande n'ouvre pas son registre.
     */
    public function test_un_magasin_sans_donnees_fiscales_n_est_pas_verifie(): void
    {
        $magasinSansVente = Magasin::factory()->create();
        $magasinQuiAVendu = $this->magasinQuiAVendu();

        $this->artisan('caisse:verifier')->assertSuccessful();

        $this->assertSame(0, DB::table('enregistrements_fiscaux')->where('magasin_id', $magasinSansVente->id)->count());
        $this->assertCount(1, $this->controlesDe($magasinQuiAVendu));
    }

    /**
     * Le magasin n° 31 a sa vérification complète le 3 de chaque mois :
     * les autres nuits, la vérification reprend après la précédente.
     */
    public function test_la_verification_planifiee_est_complete_au_jour_du_magasin_et_incrementale_les_autres_jours(): void
    {
        $this->travelTo('2026-10-02 03:00:00');
        $magasin = $this->magasinQuiAVendu(['id' => 31]);
        $this->artisan('caisse:verifier')->assertSuccessful();

        $this->travelTo('2026-10-03 03:00:00');
        $this->artisan('caisse:verifier')->assertSuccessful();
        $this->travelTo('2026-10-04 03:00:00');
        $this->artisan('caisse:verifier')->assertSuccessful();
        $this->travelTo('2026-11-03 03:00:00');
        $this->artisan('caisse:verifier')->assertSuccessful();

        $this->assertSame(['complete', 'complete', 'incrementale', 'complete'], array_column($this->controlesDe($magasin), 'verification'));
    }

    public function test_complet_verifie_tout_le_registre_quel_que_soit_le_jour(): void
    {
        $this->travelTo('2026-10-02 03:00:00');
        $magasin = $this->magasinQuiAVendu(['id' => 31]);
        $this->artisan('caisse:verifier')->assertSuccessful();
        $this->travelTo('2026-10-04 03:00:00');

        $this->artisan('caisse:verifier', ['--complet' => true])->assertSuccessful();

        $this->assertSame(['complete', 'complete'], array_column($this->controlesDe($magasin), 'verification'));
    }

    /**
     * Chaque nuit à 3 h, heure de Paris : 1 h UTC en heure d'été.
     */
    public function test_la_verification_part_chaque_nuit_a_3_heures_heure_de_paris(): void
    {
        // Le planning de bootstrap/app.php s'enregistre au démarrage
        // d'Artisan, comme sur le serveur avant schedule:run.
        $this->artisan('schedule:list')->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 01:00:00', 'UTC'));
        $this->assertTrue($this->verificationDue());

        $this->travelTo(CarbonImmutable::parse('2026-10-15 03:00:00', 'UTC'));
        $this->assertFalse($this->verificationDue());
    }

    private function verificationDue(): bool
    {
        return app(Schedule::class)->dueEvents($this->app)
            ->contains(fn (Event $evenement): bool => str_contains((string) $evenement->command, 'caisse:verifier'));
    }
}
