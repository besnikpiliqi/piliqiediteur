<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Délais de relance de chaque magasin dans ces tests : 7, 15 et 30 jours
 * après l'échéance (l'exemple du logiciel), échéance au 1er octobre 2026 :
 * les factures y sont émises ce jour-là à des clients payables comptant.
 */
class RelancerFacturesImpayeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_niveau_echu_part_une_seule_fois(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $facture = $this->factureImpayee($this->magasinQuiRelance(), 'client@exemple.fr');

        $this->relancerLe('2026-10-07');
        $this->assertSame([], $this->niveauxRelances($facture));

        $this->relancerLe('2026-10-08');
        $this->relancerLe('2026-10-08');
        $this->relancerLe('2026-10-15');
        $this->assertSame([1], $this->niveauxRelances($facture));

        $this->relancerLe('2026-10-16');
        $this->relancerLe('2026-10-30');
        $this->assertSame([1, 2], $this->niveauxRelances($facture));

        $this->relancerLe('2026-10-31');
        $this->relancerLe('2026-12-15');
        $this->assertSame([1, 2, 3], $this->niveauxRelances($facture));

        Mail::assertSent(DocumentMail::class, 3);
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr') && $mail->hasSubject("Relance n°3 : Ticket de caisse {$facture->numero} — {$facture->magasin->nom}"));
    }

    /**
     * Relances activées alors que la facture est échue depuis 19 jours :
     * le client reçoit d'abord la relance n°1, jamais une « n°2 » sans la
     * précédente ni toutes d'un coup ; puis les suivantes, chacune à son
     * délai et au rythme choisi par le magasin (8 jours entre la n°1 et la
     * n°2, 15 entre la n°2 et la n°3).
     */
    public function test_une_facture_deja_tres_en_retard_recoit_les_relances_dans_l_ordre_et_au_rythme_des_delais(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasin = $this->magasinQuiRelance();
        $magasin->update(['relances_automatiques' => false]);
        $facture = $this->factureImpayee($magasin, 'client@exemple.fr');
        $magasin->update(['relances_automatiques' => true]);

        $this->relancerLe('2026-10-20');
        $this->relancerLe('2026-10-21');
        $this->relancerLe('2026-10-27');
        $this->assertSame([1], $this->niveauxRelances($facture));

        $this->relancerLe('2026-10-28');
        $this->relancerLe('2026-11-11');
        $this->assertSame([1, 2], $this->niveauxRelances($facture));

        $this->relancerLe('2026-11-12');
        $this->assertSame([1, 2, 3], $this->niveauxRelances($facture));
        Mail::assertSent(DocumentMail::class, 3);
    }

    /**
     * Une relance manuelle compte : relancée à la main après la n°1, la
     * facture ne reçoit pas une deuxième n°2, et la n°3 attend son délai.
     */
    public function test_une_relance_manuelle_n_est_jamais_redoublee(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $facture = $this->factureImpayee($this->magasinQuiRelance(), 'client@exemple.fr');
        $this->relancerLe('2026-10-08');
        $this->travelTo(self::aParis('2026-10-10 14:00:00'));
        app(TenantContext::class)->set($facture->magasin);
        app(EnvoyerDocumentAction::class)->relancer(self::auteur(), $facture, 'client@exemple.fr');

        $this->relancerLe('2026-10-16');
        $this->relancerLe('2026-10-30');
        $this->assertSame([1, 2], $this->niveauxRelances($facture));

        $this->relancerLe('2026-10-31');
        $this->assertSame([1, 2, 3], $this->niveauxRelances($facture));
    }

    /**
     * Le serveur d'envoi a refusé la relance n°1 : elle n'a jamais atteint
     * le client, elle repart le lendemain, au même niveau, à sa place dans
     * l'historique.
     */
    public function test_une_relance_en_echec_repart_le_lendemain(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $facture = $this->factureImpayee($this->magasinQuiRelance(), 'client@exemple.fr');
        $this->relancerLe('2026-10-08');
        $relance = EnvoiDocument::query()->withoutGlobalScopes()->sole();
        $relance->forceFill(['statut' => StatutEnvoi::Echec, 'erreur' => 'Le serveur d\'envoi des e-mails n\'a pas accepté le message.'])->save();

        $this->relancerLe('2026-10-09');

        $this->assertSame([1], $this->niveauxRelances($facture));
        $this->assertSame([StatutEnvoi::Envoye, null], [$relance->fresh()->statut, $relance->fresh()->erreur]);
        Mail::assertSent(DocumentMail::class, 2);
    }

    /**
     * Archivé depuis, le client doit toujours la facture : elle est
     * relancée comme les autres.
     */
    public function test_la_facture_d_un_client_archive_est_relancee(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $facture = $this->factureImpayee($this->magasinQuiRelance(), 'archive@exemple.fr');
        $facture->client->delete();

        $this->relancerLe('2026-10-08');

        $this->assertSame([1], $this->niveauxRelances($facture));
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('archive@exemple.fr'));
    }

    public function test_les_factures_reglees_corrigees_par_un_avoir_ou_sans_adresse_ne_sont_pas_relancees(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasin = $this->magasinQuiRelance();
        $payee = $this->factureImpayee($magasin, 'payee@exemple.fr');
        $this->payer($payee, 120);
        $corrigeeParAvoir = $this->factureImpayee($magasin, 'avoir@exemple.fr');
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $corrigeeParAvoir->client, [['ligne_facture_id' => $corrigeeParAvoir->lignes->first()->id, 'quantite' => 1]], 'Erreur');
        $sansAdresse = $this->factureImpayee($magasin, null);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(['delai_paiement_jours' => 0]), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $partiellementPayee = $this->factureImpayee($magasin, 'partielle@exemple.fr');
        $this->payer($partiellementPayee, 20);

        $this->relancerLe('2026-10-10');

        Mail::assertSent(DocumentMail::class, 1);
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('partielle@exemple.fr'));
        foreach ([$payee, $corrigeeParAvoir, $sansAdresse, $brouillon] as $facture) {
            $this->assertSame([], $this->niveauxRelances($facture));
        }
    }

    /**
     * Un ticket numéroté directement en base, hors de son émission, n'a
     * aucun enregistrement signé : il n'est pas relancé, l'incident est
     * signalé au journal de l'application, et la facture qui le suit part
     * quand même.
     */
    public function test_un_ticket_sans_enregistrement_signe_ne_bloque_pas_les_autres_relances(): void
    {
        Mail::fake();
        Exceptions::fake([DocumentSansEnregistrementSigneException::class]);
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasin = $this->magasinQuiRelance();
        app(TenantContext::class)->set($magasin);
        $sansEnregistrement = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(['email' => 'anomalie@exemple.fr', 'delai_paiement_jours' => 0]), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $sansEnregistrement->id)
            ->update(['numero' => 'TK-RELANCE-2026-000009', 'statut' => 'emise', 'date_emission' => Instant::enBase(self::enUtc('2026-10-01 09:00:00')), 'date_echeance' => '2026-10-01']);
        $facture = $this->factureImpayee($magasin, 'client@exemple.fr');
        $this->travelTo(self::aParis('2026-10-08 09:00:00'));
        app(TenantContext::class)->clear();

        $this->artisan('app:relancer-factures-impayees')
            ->expectsOutputToContain('Facture TK-RELANCE-2026-000009 non relancée : Le ticket TK-RELANCE-2026-000009 n\'a aucun enregistrement signé au registre fiscal')
            ->assertSuccessful();

        $this->assertSame([], $this->niveauxRelances($sansEnregistrement));
        $this->assertSame([1], $this->niveauxRelances($facture));
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr'));
        Mail::assertNotSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('anomalie@exemple.fr'));
        Exceptions::assertReported(DocumentSansEnregistrementSigneException::class);
    }

    public function test_un_magasin_aux_relances_desactivees_ne_relance_rien(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasin = $this->magasinQuiRelance();
        $magasin->update(['relances_automatiques' => false]);
        $facture = $this->factureImpayee($magasin, 'client@exemple.fr');

        $this->relancerLe('2026-10-20');

        Mail::assertNothingSent();
        $this->assertSame([], $this->niveauxRelances($facture));
    }

    public function test_un_magasin_suspendu_ne_relance_rien(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasin = $this->magasinQuiRelance();
        $facture = $this->factureImpayee($magasin, 'client@exemple.fr');
        $magasin->forceFill(['suspendu_le' => now()])->save();

        $this->relancerLe('2026-10-20');

        $this->assertSame([], $this->niveauxRelances($facture));
    }

    /**
     * Chaque magasin relance selon ses propres réglages, ses propres
     * factures et son propre historique de relances.
     */
    public function test_chaque_magasin_relance_selon_ses_reglages_et_ses_factures(): void
    {
        Mail::fake();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $magasinA = $this->magasinQuiRelance();
        $factureDeA = $this->factureImpayee($magasinA, 'a@exemple.fr');
        $magasinB = $this->magasinQuiRelance();
        $magasinB->update(['delais_relance' => [3]]);
        $factureDeB = $this->factureImpayee($magasinB, 'b@exemple.fr');
        $magasinC = Magasin::factory()->create();
        $factureDeC = $this->factureImpayee($magasinC, 'c@exemple.fr');

        $this->relancerLe('2026-10-05');

        $this->assertSame([], $this->niveauxRelances($factureDeA));
        $this->assertSame([1], $this->niveauxRelances($factureDeB));
        $this->assertSame([], $this->niveauxRelances($factureDeC));
        $this->assertSame([$magasinB->id], EnvoiDocument::query()->withoutGlobalScopes()->pluck('magasin_id')->all());
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('b@exemple.fr'));
        Mail::assertNotSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('a@exemple.fr') || $mail->hasTo('c@exemple.fr'));
    }

    /**
     * Chaque matin à 9 h, heure de Paris, pour un magasin de métropole : la
     * tâche passe toutes les heures, mais ne relance un magasin que de 9 h
     * à 11 h 59 à son heure locale. Échue depuis 7 jours, la facture n'est
     * pas relancée au passage de 8 h, l'est à celui de 9 h, et pas une
     * seconde fois à celui de 10 h ; un magasin dont les relances
     * s'activent après 11 h 59 attend le lendemain matin.
     */
    public function test_les_relances_partent_chaque_matin_a_9_heures_heure_de_paris(): void
    {
        Mail::fake();
        $this->artisan('schedule:list')->assertSuccessful();
        $this->travelTo(self::aParis('2026-10-01 09:00:00'));
        $facture = $this->factureImpayee($this->magasinQuiRelance(), 'client@exemple.fr');
        $tardif = $this->magasinQuiRelance();
        $tardif->update(['relances_automatiques' => false]);
        $factureTardive = $this->factureImpayee($tardif, 'tardif@exemple.fr');

        foreach ([[self::aParis('2026-10-08 08:00:00'), []], [self::aParis('2026-10-08 09:00:00'), [1]], [self::aParis('2026-10-08 10:00:00'), [1]]] as [$instant, $niveaux]) {
            $this->travelTo($instant);
            $this->assertTrue($this->relanceDue(), $instant->toIso8601String());
            $this->relancerMaintenant();
            $this->assertSame($niveaux, $this->niveauxRelances($facture), $instant->toIso8601String());
        }

        $this->travelTo(self::aParis('2026-10-08 11:30:00'));
        $tardif->update(['relances_automatiques' => true]);
        $this->travelTo(self::aParis('2026-10-08 12:00:00'));
        $this->relancerMaintenant();
        $this->assertSame([], $this->niveauxRelances($factureTardive));

        $this->travelTo(self::aParis('2026-10-09 09:00:00'));
        $this->relancerMaintenant();
        $this->assertSame([1], $this->niveauxRelances($factureTardive));
    }

    private function relanceDue(): bool
    {
        return app(Schedule::class)->dueEvents($this->app)
            ->contains(fn (Event $evenement): bool => str_contains((string) $evenement->command, 'app:relancer-factures-impayees'));
    }

    private function magasinQuiRelance(): Magasin
    {
        return Magasin::factory()->create(['relances_automatiques' => true, 'delais_relance' => [7, 15, 30]]);
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Une facture de 120,00 € TTC émise maintenant à un client payable
     * comptant (délai de 0 jour) : échue le jour même (B7).
     */
    private function factureImpayee(Magasin $magasin, ?string $emailDuClient): Facture
    {
        app(TenantContext::class)->set($magasin);
        $client = Client::factory()->create(['email' => $emailDuClient, 'delai_paiement_jours' => 0]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
    }

    private function payer(Facture $facture, float $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Especes,
            'montant' => $montant,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    private function relancerLe(string $jour): void
    {
        $this->travelTo(self::aParis("{$jour} 09:00:00"));
        $this->relancerMaintenant();
    }

    private function relancerMaintenant(): void
    {
        app(TenantContext::class)->clear();

        $this->artisan('app:relancer-factures-impayees')->assertSuccessful();
    }

    /**
     * @return list<int>
     */
    private function niveauxRelances(Facture $facture): array
    {
        return EnvoiDocument::query()->withoutGlobalScopes()
            ->where('document_type', $facture->getMorphClass())
            ->where('document_id', $facture->id)
            ->where('nature', NatureEnvoi::Relance)
            ->orderBy('niveau_relance')
            ->pluck('niveau_relance')
            ->all();
    }
}
