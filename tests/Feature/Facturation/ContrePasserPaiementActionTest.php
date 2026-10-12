<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

/**
 * Un paiement saisi par erreur se corrige par sa contre-passation (B4) :
 * un paiement opposé, lié à l'original et signé, qui rouvre les factures
 * qu'il réglait ; on saisit ensuite le bon paiement, avec lequel le crédit
 * d'avoir disponible du client s'impute (K9).
 */
class ContrePasserPaiementActionTest extends TestCase
{
    use RefreshDatabase;

    private function magasinActif(string $code = 'LYON1'): Magasin
    {
        $magasin = Magasin::factory()->create(['code' => $code]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Une facture de 1 article au prix TTC donné, à 20 % (100,00 € TTC :
     * 83,33 € HT + 16,67 €), émise pour le client, à échéance dans 30
     * jours.
     */
    private function facture(Client $client, float $prixTtc = 100): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
    }

    /**
     * @param  array<int, string>  $imputations  le montant imputé, par identifiant de facture
     */
    private function payer(Client $client, string $montant, array $imputations, ModePaiement $mode = ModePaiement::Especes, ?string $reference = null): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle(self::auteur(),
            $client,
            ['mode_paiement' => $mode->value, 'montant' => $montant, 'reference' => $reference, 'recu_le' => today()->toDateString()],
            array_map(static fn (int $factureId, string $montantImpute): array => ['facture_id' => $factureId, 'montant' => $montantImpute], array_keys($imputations), $imputations),
        );
    }

    private function contrePasser(Paiement $paiement, string $motif = 'Erreur de saisie : 10 € reçus'): Paiement
    {
        return app(ContrePasserPaiementAction::class)->handle(self::auteur(), $paiement, $motif);
    }

    /**
     * @return array<string, mixed>
     */
    private function donneesSignees(Paiement $paiement): array
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Encaissement->value)
            ->where('document_type', $paiement->getMorphClass())
            ->where('document_id', $paiement->getKey())
            ->sole()
            ->contenuSigne()['donnees'];
    }

    public function test_la_contre_passation_annule_le_paiement_errone_et_rouvre_la_facture(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $operateur = $this->responsable($this->magasinActif());
        $this->actingAs($operateur);
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00'], reference: 'CAISSE-7');

        $contrePassation = $this->contrePasser($errone);

        $this->assertSame(
            [$client->id, ModePaiement::Especes, '-100.00', 'CAISSE-7', 'Erreur de saisie : 10 € reçus', '2026-10-09', $operateur->id, $errone->id],
            [$contrePassation->client_id, $contrePassation->mode_paiement, $contrePassation->montant, $contrePassation->reference, $contrePassation->notes, $contrePassation->recu_le->toDateString(), $contrePassation->cree_par, $contrePassation->contre_passe_paiement_id],
        );
        $this->assertSame([[$facture->id, '-100.00']], $contrePassation->allocations->map(fn ($allocation): array => [$allocation->facture_id, $allocation->montant_alloue])->all());
        $facture->refresh();
        $this->assertSame(['83.33', '16.67', '100.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame([StatutFacture::Emise, 0.0, 100.0], [$facture->statut, $facture->montantPaye(), $facture->montantRestant()]);
        $this->assertSame(['100.00', null], [$errone->fresh()->montant, $errone->fresh()->contre_passe_paiement_id]);
        $this->assertTrue($errone->fresh()->contrePassation->is($contrePassation));
    }

    public function test_le_bon_paiement_saisi_apres_la_contre_passation_laisse_la_facture_partiellement_payee(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $this->contrePasser($this->payer($client, '100.00', [$facture->id => '100.00']));

        $this->payer($client, '10.00', [$facture->id => '10.00']);

        $facture->refresh();
        $this->assertSame([StatutFacture::PartiellementPayee, 10.0, 90.0], [$facture->statut, $facture->montantPaye(), $facture->montantRestant()]);
    }

    public function test_une_facture_echue_rouverte_par_la_contre_passation_est_en_retard(): void
    {
        $this->travelTo('2026-09-01 10:00:00');
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);
        $this->travelTo('2026-10-09 10:00:00');

        $this->contrePasser($errone);

        $this->assertSame([StatutFacture::EnRetard, 100.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
    }

    public function test_une_facture_envoyee_au_client_rouverte_par_la_contre_passation_redevient_envoyee(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'compta@client.fr', null);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);

        $this->contrePasser($errone);

        $this->assertSame([StatutFacture::Envoyee, 100.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant()]);
    }

    public function test_un_paiement_reparti_sur_deux_factures_les_rouvre_toutes_les_deux(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $premiere = $this->facture($client, 60);
        $seconde = $this->facture($client, 40);
        $errone = $this->payer($client, '100.00', [$premiere->id => '60.00', $seconde->id => '40.00'], ModePaiement::Virement);

        $contrePassation = $this->contrePasser($errone);

        $this->assertSame([[$premiere->id, '-60.00'], [$seconde->id, '-40.00']], $contrePassation->allocations->sortBy('id')->map(fn ($allocation): array => [$allocation->facture_id, $allocation->montant_alloue])->values()->all());
        $this->assertSame([StatutFacture::Emise, 60.0], [$premiere->fresh()->statut, $premiere->fresh()->montantRestant()]);
        $this->assertSame([StatutFacture::Emise, 40.0], [$seconde->fresh()->statut, $seconde->fresh()->montantRestant()]);
    }

    public function test_un_paiement_ne_se_contre_passe_qu_une_fois(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);
        $this->contrePasser($errone);

        $this->assertThrows(fn () => $this->contrePasser($errone), LogicException::class, 'Ce paiement a déjà été contre-passé.');

        $this->assertSame(2, Paiement::query()->count());
        $this->assertSame(100.0, $facture->fresh()->montantRestant());
    }

    public function test_une_contre_passation_ne_se_contre_passe_pas(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $contrePassation = $this->contrePasser($this->payer($client, '100.00', [$facture->id => '100.00']));

        $this->assertThrows(fn () => $this->contrePasser($contrePassation), LogicException::class, 'Une contre-passation ne se contre-passe pas.');

        $this->assertSame(2, Paiement::query()->count());
    }

    public function test_une_contre_passation_sans_motif_est_refusee(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);

        $this->assertThrows(fn () => $this->contrePasser($errone, '   '), LogicException::class, 'Indiquez le motif de la contre-passation.');

        $this->assertSame([1, StatutFacture::Payee], [Paiement::query()->count(), $facture->fresh()->statut]);
    }

    /**
     * Une contre-passation est datée du jour où elle est faite et ne
     * touche jamais une journée déjà clôturée : après la clôture du jour,
     * elle attend le lendemain.
     */
    public function test_une_contre_passation_attend_le_lendemain_d_une_journee_cloturee(): void
    {
        $this->travelTo('2026-10-09 18:00:00');
        $magasin = $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, now());

        $this->assertThrows(fn () => $this->contrePasser($errone), LogicException::class, 'La journée du 09/10/2026 est clôturée : la contre-passation pourra être faite demain.');
        $this->travelTo('2026-10-10 09:00:00');
        $contrePassation = $this->contrePasser($errone);

        $this->assertSame('2026-10-10', $contrePassation->recu_le->toDateString());
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
    }

    /**
     * La chaîne des encaissements signe le paiement erroné, sa
     * contre-passation, qui le désigne avec la séquence de son
     * enregistrement, puis le bon paiement ; la vérification de
     * l'intégrité n'y trouve rien à redire.
     */
    public function test_la_contre_passation_est_signee_dans_la_chaine_des_encaissements(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);

        $contrePassation = $this->contrePasser($errone);
        $bon = $this->payer($client, '10.00', [$facture->id => '10.00']);

        $chaine = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Encaissement->value)->orderBy('sequence')->get();
        $this->assertSame(
            [[1, 'paiement', $errone->id], [2, 'contre_passation', $contrePassation->id], [3, 'paiement', $bon->id]],
            $chaine->map(fn (EnregistrementFiscal $enregistrement): array => [$enregistrement->sequence, $enregistrement->type, (int) $enregistrement->document_id])->all(),
        );
        $this->assertSame('Contre-passation', $chaine[1]->libelleDuType());
        $donnees = $this->donneesSignees($contrePassation);
        $this->assertSame(['mode' => 'especes', 'montant' => -10000, 'notes' => 'Erreur de saisie : 10 € reçus'], Arr::only($donnees['paiement'], ['mode', 'montant', 'notes']));
        $this->assertSame(['paiement_id' => $errone->id, 'sequence' => 1], $donnees['contre_passe']);
        $this->assertSame([['facture_id' => $facture->id, 'numero' => 'FAC-LYON1-2026-000001', 'montant' => -10000]], $donnees['allocations']);
        $this->assertNull($this->donneesSignees($bon)['contre_passe']);
        $clePublique = Arr::first(config('caisse_securisee.cles_publiques'));
        $chaine->each(fn (EnregistrementFiscal $enregistrement) => $this->assertSame(1, openssl_verify($enregistrement->contenu, base64_decode(strtr($enregistrement->signature, '-_', '+/')), $clePublique, OPENSSL_ALGO_SHA256)));
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    public function test_le_paiement_contre_passe_reste_inalterable_en_base(): void
    {
        $magasin = $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);
        $this->contrePasser($errone);

        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('paiements')->where('magasin_id', $magasin->id)->where('id', $errone->id)->update(['montant' => '10.00'])), QueryException::class);

        $this->assertSame('100.00', $errone->fresh()->montant);
    }

    public function test_un_paiement_d_un_autre_magasin_est_introuvable(): void
    {
        $this->magasinActif('LYON2');
        $clientDeB = Client::factory()->professionnel()->create();
        $paiementDeB = $this->payer($clientDeB, '100.00', [$this->facture($clientDeB)->id => '100.00']);
        $this->magasinActif();

        $this->assertThrows(fn () => $this->contrePasser($paiementDeB), ModelNotFoundException::class);

        $this->assertSame(1, Paiement::query()->withoutGlobalScope(MagasinScope::class)->count());
    }

    /**
     * K9 : la contre-passation annule exactement l'erreur ; le bon
     * paiement de 10,00 € en espèces impute ensuite le crédit d'avoir du
     * client sur le reste de la facture (90,00 €), et il reste 10,00 € de
     * crédit.
     */
    public function test_le_bon_paiement_impute_le_credit_d_avoir_disponible_sur_le_reste_de_la_facture(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $dejaPayee = $this->facture($client);
        $this->payer($client, '100.00', [$dejaPayee->id => '100.00'], ModePaiement::Virement);
        $facture = $this->facture($client);
        $errone = $this->payer($client, '100.00', [$facture->id => '100.00']);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $dejaPayee->lignes()->sole()->id, 'quantite' => 1]], 'Retour');
        $this->contrePasser($errone);
        $this->assertSame([100.0, 100.0], [$client->soldeCrediteur(), $facture->fresh()->montantRestant()]);

        $bon = $this->payer($client, '10.00', [$facture->id => '10.00']);

        $facture->refresh();
        $this->assertSame([StatutFacture::Payee, 10.0, 90.0, 0.0], [$facture->statut, $facture->montantPaye(), $facture->montantCrediteParAvoir(), $facture->montantRestant()]);
        $this->assertSame([[$avoir->id, $facture->id, '90.00']], $bon->creditsImputes->map(fn ($credit): array => [$credit->avoir_id, $credit->facture_id, $credit->montant_alloue])->all());
        $this->assertSame(10.0, $client->soldeCrediteur());
        $this->assertSame([[
            'avoir_id' => $avoir->id,
            'avoir_numero' => 'AV-LYON1-2026-000001',
            'facture_id' => $facture->id,
            'facture_numero' => 'FAC-LYON1-2026-000002',
            'montant' => 9000,
        ]], $this->donneesSignees($bon)['credits_imputes']);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    /**
     * Un client a 100,00 € de crédit et deux factures dues de 100,00 € :
     * A, la plus ancienne, et R. Elles étaient réglées par un virement
     * quand l'avoir a été émis, qui n'a donc rien eu à régler (B6), puis la
     * contre-passation du virement, rejeté, les a rouvertes. Le paiement de
     * 10,00 € saisi par erreur sur R y impute aussi 90,00 € de crédit.
     *
     * @return array{client: Client, ancienne: Facture, recente: Facture, avoir: Avoir, errone: Paiement}
     */
    private function paiementErroneQuiImputeDuCredit(): array
    {
        $this->travelTo('2026-10-09 10:00:00');
        $client = Client::factory()->professionnel()->create();
        $dejaPayee = $this->facture($client);
        $this->payer($client, '100.00', [$dejaPayee->id => '100.00'], ModePaiement::Virement);
        $this->travelTo('2026-10-09 10:01:00');
        $ancienne = $this->facture($client);
        $this->travelTo('2026-10-09 10:02:00');
        $recente = $this->facture($client);
        $virementRejete = $this->payer($client, '200.00', [$ancienne->id => '100.00', $recente->id => '100.00'], ModePaiement::Virement);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $dejaPayee->lignes()->sole()->id, 'quantite' => 1]], 'Retour');
        $this->contrePasser($virementRejete, 'Virement rejeté');
        $errone = $this->payer($client, '10.00', [$recente->id => '10.00']);

        return compact('client', 'ancienne', 'recente', 'avoir', 'errone');
    }

    /**
     * K9 : la contre-passation est l'inverse exact du paiement erroné, le
     * crédit qu'il avait imputé compris, qui revient à son avoir. Le bon
     * paiement de 10,00 € sur A y impute ensuite 90,00 € de crédit : A est
     * payée, R reste due en entier et il reste 10,00 € de crédit, comme
     * sans l'erreur.
     */
    public function test_la_contre_passation_rend_le_credit_que_le_paiement_errone_avait_impute(): void
    {
        $this->magasinActif();
        ['client' => $client, 'ancienne' => $ancienne, 'recente' => $recente, 'avoir' => $avoir, 'errone' => $errone] = $this->paiementErroneQuiImputeDuCredit();
        $this->assertSame([StatutFacture::Payee, 10.0], [$recente->fresh()->statut, $client->soldeCrediteur()]);

        $contrePassation = $this->contrePasser($errone, 'Saisi sur la mauvaise facture');
        $this->payer($client, '10.00', [$ancienne->id => '10.00']);

        $this->assertSame([[$avoir->id, $recente->id, '-90.00']], $contrePassation->creditsImputes->map(fn ($credit): array => [$credit->avoir_id, $credit->facture_id, $credit->montant_alloue])->all());
        $this->assertSame([[
            'avoir_id' => $avoir->id,
            'avoir_numero' => 'AV-LYON1-2026-000001',
            'facture_id' => $recente->id,
            'facture_numero' => 'FAC-LYON1-2026-000003',
            'montant' => -9000,
        ]], $this->donneesSignees($contrePassation)['credits_imputes']);
        $ancienne->refresh();
        $this->assertSame([StatutFacture::Payee, 10.0, 90.0, 0.0], [$ancienne->statut, $ancienne->montantPaye(), $ancienne->montantCrediteParAvoir(), $ancienne->montantRestant()]);
        $recente->refresh();
        $this->assertSame([StatutFacture::Emise, 0.0, 0.0, 100.0], [$recente->statut, $recente->montantPaye(), $recente->montantCrediteParAvoir(), $recente->montantRestant()]);
        $this->assertSame(10.0, $client->soldeCrediteur());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    /**
     * Le compte de l'application n'aura, en production, ni UPDATE ni
     * DELETE sur les tables en ajout seul (voir CLAUDE.md, étape 15) : or
     * PostgreSQL exige le droit de modifier pour un SELECT ... FOR UPDATE
     * ou FOR SHARE. Aucune requête d'un paiement, d'un avoir ou d'une
     * contre-passation ne verrouille donc une ligne de ces tables, que
     * PostgreSQL désigne par leurs déclencheurs ; le verrou du registre
     * suffit à faire passer ces opérations une par une.
     */
    public function test_aucune_requete_ne_verrouille_une_ligne_d_une_table_en_ajout_seul(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite n\'écrit aucun verrou de ligne : ce test ne tourne que sous PostgreSQL.');
        }

        $this->magasinActif();
        $tablesEnAjoutSeul = collect(DB::select("select distinct c.relname as nom from pg_trigger t join pg_class c on c.oid = t.tgrelid where t.tgname like '%\\_ajout\\_seul\\_update'"))->pluck('nom')->sort()->values()->all();
        $this->assertSame(['avoir_allocations', 'bordereaux_detaxe', 'consultations_vies', 'enregistrements_fiscaux', 'paiement_allocations', 'paiements', 'preuves_operation'], $tablesEnAjoutSeul);
        $verrous = [];
        DB::listen(static function (QueryExecuted $requete) use ($tablesEnAjoutSeul, &$verrous): void {
            $tables = implode('|', $tablesEnAjoutSeul);

            if (preg_match('/\bfor\s+(no\s+key\s+update|update|key\s+share|share)\b/i', $requete->sql) === 1 && preg_match('/\b(from|join)\s+"('.$tables.')"/i', $requete->sql) === 1) {
                $verrous[] = $requete->sql;
            }
        });

        ['client' => $client, 'ancienne' => $ancienne, 'errone' => $errone] = $this->paiementErroneQuiImputeDuCredit();
        $this->contrePasser($errone, 'Saisi sur la mauvaise facture');
        $this->payer($client, '10.00', [$ancienne->id => '10.00']);

        $this->assertSame([], $verrous);
    }

    /**
     * Un avoir déjà imputé sur la facture qu'il corrige, dont il reste du
     * crédit, s'y impute de nouveau quand le bon paiement règle la facture
     * rouverte par la contre-passation.
     */
    public function test_le_credit_d_un_avoir_deja_impute_sur_la_facture_s_y_impute_de_nouveau_apres_la_contre_passation(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2]]));
        $errone = $this->payer($client, '80.00', [$facture->id => '80.00']);
        $avoir = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $facture->lignes()->sole()->id, 'quantite' => 1]]);
        $this->assertSame([StatutFacture::Payee, 30.0], [$facture->fresh()->statut, $avoir->fresh()->montantNonAlloue()]);
        $this->contrePasser($errone);

        $bon = $this->payer($client, '50.00', [$facture->id => '50.00']);

        $this->assertSame(
            [[null, '20.00'], [$bon->id, '30.00']],
            $avoir->allocations()->orderBy('id')->get()->map(fn ($allocation): array => [$allocation->paiement_id, $allocation->montant_alloue])->all(),
        );
        $this->assertSame([StatutFacture::Payee, 0.0, 0.0], [$facture->fresh()->statut, $facture->fresh()->montantRestant(), $avoir->fresh()->montantNonAlloue()]);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier(app(TenantContext::class)->magasin())->anomalies());
    }

    /**
     * Le crédit solde d'abord la facture la plus ancienne que règle le bon
     * paiement, en puisant d'abord dans l'avoir le plus ancien.
     */
    public function test_le_credit_solde_d_abord_la_facture_la_plus_ancienne_avec_l_avoir_le_plus_ancien(): void
    {
        $this->magasinActif();
        $client = Client::factory()->professionnel()->create();
        $retourneeA = $this->facture($client, 30);
        $retourneeB = $this->facture($client, 50);
        $this->payer($client, '80.00', [$retourneeA->id => '30.00', $retourneeB->id => '50.00'], ModePaiement::Virement);
        $ancienne = $this->facture($client, 60);
        $recente = $this->facture($client, 40);
        $errone = $this->payer($client, '100.00', [$ancienne->id => '60.00', $recente->id => '40.00']);
        $avoirAncien = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $retourneeA->lignes()->sole()->id, 'quantite' => 1]]);
        $avoirRecent = app(EnregistrerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $retourneeB->lignes()->sole()->id, 'quantite' => 1]]);
        $this->contrePasser($errone);

        $bon = $this->payer($client, '30.00', [$recente->id => '20.00', $ancienne->id => '10.00']);

        $this->assertSame(
            [[$avoirAncien->id, $ancienne->id, '30.00'], [$avoirRecent->id, $ancienne->id, '20.00'], [$avoirRecent->id, $recente->id, '20.00']],
            $bon->creditsImputes->sortBy('id')->map(fn ($credit): array => [$credit->avoir_id, $credit->facture_id, $credit->montant_alloue])->values()->all(),
        );
        $this->assertSame([StatutFacture::Payee, StatutFacture::Payee], [$ancienne->fresh()->statut, $recente->fresh()->statut]);
        $this->assertSame(10.0, $client->soldeCrediteur());
    }
}
