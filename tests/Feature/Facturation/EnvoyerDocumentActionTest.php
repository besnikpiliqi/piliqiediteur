<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EnvoyerDocumentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_envoyer_une_facture_emise_la_passe_envoyee_et_programme_l_envoi(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasin('Ma Boutique');
        $expediteur = User::factory()->create();
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create());

        $envoi = app(EnvoyerDocumentAction::class)->handle($facture, ' compta@client.fr ', 'Merci de votre confiance.', $expediteur);

        $this->assertSame(StatutFacture::Envoyee, $facture->fresh()->statut);
        $this->assertSame(
            ['compta@client.fr', "Facture {$facture->numero} — Ma Boutique", 'Merci de votre confiance.', NatureEnvoi::Envoi, null, StatutEnvoi::EnAttente, $expediteur->id, $magasin->id],
            [$envoi->destinataire, $envoi->objet, $envoi->message, $envoi->nature, $envoi->niveau_relance, $envoi->fresh()->statut, $envoi->envoye_par, $envoi->magasin_id],
        );
        $this->assertTrue($envoi->document->is($facture));
        Queue::assertPushed(EnvoyerDocumentJob::class, fn (EnvoyerDocumentJob $job): bool => $job->magasinId === $magasin->id && $job->envoiId === $envoi->id);
    }

    /**
     * Un serveur d'envoi lent ne doit jamais retenir la transaction du
     * magasin : l'e-mail ne part qu'une fois l'envoi validé en base.
     */
    public function test_l_e_mail_ne_part_qu_apres_la_validation_de_la_transaction(): void
    {
        Mail::fake();
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());

        $envoi = DB::transaction(function () use ($facture) {
            $envoi = app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, null);
            Mail::assertNothingSent();

            return $envoi;
        });

        Mail::assertSent(DocumentMail::class, 1);
        $this->assertSame(StatutEnvoi::Envoye, $envoi->fresh()->statut);
    }

    public function test_une_facture_partiellement_payee_garde_son_statut_en_partant(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        $this->payer($facture, 50);

        app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, null);

        $this->assertSame(StatutFacture::PartiellementPayee, $facture->fresh()->statut);
    }

    public function test_une_facture_brouillon_ne_part_pas(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, null),
            LogicException::class,
            'Impossible d\'envoyer ce document au statut « Brouillon ».',
        );

        $this->assertSame(0, EnvoiDocument::query()->count());
        Queue::assertNotPushed(EnvoyerDocumentJob::class);
    }

    /**
     * Corrigée par un avoir total, la facture reste le document émis de son
     * client : elle part toujours, et garde son statut (soldée par l'avoir,
     * elle est payée).
     */
    public function test_une_facture_corrigee_par_un_avoir_total_part_toujours(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        app(EnregistrerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]], 'Erreur de client');

        $envoi = app(EnvoyerDocumentAction::class)->handle($facture->fresh(), 'client@exemple.fr', null, null);

        $this->assertTrue($envoi->document->is($facture));
        $this->assertSame(StatutFacture::Payee, $facture->fresh()->statut);
        Queue::assertPushed(EnvoyerDocumentJob::class, 1);
    }

    public function test_un_avoir_emis_part_sans_changer_de_statut(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin('Ma Boutique');
        $avoir = $this->emettreAvoir();

        $envoi = app(EnvoyerDocumentAction::class)->handle($avoir, 'client@exemple.fr', null, null);

        $this->assertSame(StatutAvoir::Emise, $avoir->fresh()->statut);
        $this->assertSame("Avoir {$avoir->numero} — Ma Boutique", $envoi->objet);
    }

    public function test_un_avoir_brouillon_ne_part_pas(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        $avoir = app(CreerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]);

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($avoir, 'client@exemple.fr', null, null),
            LogicException::class,
            'Impossible d\'envoyer ce document au statut « Brouillon ».',
        );

        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    /**
     * Un ticket numéroté directement en base, hors de son émission, n'a
     * aucun enregistrement signé, donc aucun émetteur à nommer dans l'objet
     * de l'e-mail ni à imprimer sur son PDF : l'envoi est refusé dès la
     * demande, rien n'est noté ni programmé, et le ticket garde son
     * statut.
     */
    public function test_un_document_numerote_sans_enregistrement_signe_ne_part_pas(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasin();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-ENVOI-2026-000009', 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($brouillon->fresh(), 'client@exemple.fr', null, null),
            DocumentSansEnregistrementSigneException::class,
            'Le ticket TK-ENVOI-2026-000009 n\'a aucun enregistrement signé au registre fiscal : son PDF n\'est pas produit.',
        );

        $this->assertSame(0, EnvoiDocument::query()->count());
        $this->assertSame(StatutFacture::Emise, $brouillon->fresh()->statut);
        Queue::assertNotPushed(EnvoyerDocumentJob::class);
    }

    public function test_un_devis_brouillon_recoit_son_numero_en_partant(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin('Ma Boutique');
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        $envoi = app(EnvoyerDocumentAction::class)->handle($devis, 'client@exemple.fr', null, null);

        $devis->refresh();
        $this->assertSame(StatutDevis::Envoye, $devis->statut);
        $this->assertNotNull($devis->numero);
        $this->assertNotNull($devis->date_emission);
        $this->assertSame("Devis {$devis->numero} — Ma Boutique", $envoi->objet);
    }

    public function test_un_devis_deja_accepte_se_renvoie_sans_changer_de_statut(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        app(EnvoyerDocumentAction::class)->handle($devis, 'client@exemple.fr', null, null);
        app(AccepterDevisAction::class)->handle($devis);

        app(EnvoyerDocumentAction::class)->handle($devis, 'client@exemple.fr', 'Le voici à nouveau.', null);

        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
        $this->assertSame(2, $devis->envois()->count());
    }

    public function test_une_adresse_e_mail_invalide_est_refusee(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($facture, 'pas-une-adresse', null, null),
            InvalidArgumentException::class,
            'L\'adresse e-mail « pas-une-adresse » n\'est pas valide.',
        );

        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);
        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    /**
     * La norme admet des adresses que le serveur d'envoi refuse (avec un
     * commentaire) ou qui n'arrivent nulle part (domaine sans point) :
     * refusées tout de suite, plutôt qu'un échec trois tentatives plus tard.
     */
    public function test_une_adresse_que_le_serveur_d_envoi_refuserait_est_refusee(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());

        foreach (['jean(bureau)@client.fr', 'jean@client', '"jean dupont"@client.fr'] as $adresse) {
            $this->assertThrows(
                fn () => app(EnvoyerDocumentAction::class)->handle($facture, $adresse, null, null),
                InvalidArgumentException::class,
                "L'adresse e-mail « {$adresse} » n'est pas valide.",
            );
        }

        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    public function test_un_document_d_un_autre_magasin_est_introuvable(): void
    {
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $factureDuMagasinA = $this->emettreFacture(Client::factory()->create());
        $this->magasin();

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($factureDuMagasinA, 'client@exemple.fr', null, null),
            ModelNotFoundException::class,
        );

        $this->assertSame(0, EnvoiDocument::query()->withoutGlobalScopes()->count());
        $this->assertSame(StatutFacture::Emise, Facture::query()->withoutGlobalScopes()->findOrFail($factureDuMagasinA->id)->statut);
    }

    public function test_relancer_une_facture_echue_rappelle_son_montant_son_echeance_et_son_reste(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin('Ma Boutique');
        $facture = $this->emettreFactureEchueLe(Client::factory()->professionnel()->create(), '2026-09-30');
        $this->payer($facture, 50);
        $facture->refresh();

        $relance = app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);

        $this->assertSame(
            [NatureEnvoi::Relance, 1, "Relance n°1 : Facture {$facture->numero} — Ma Boutique"],
            [$relance->nature, $relance->niveau_relance, $relance->objet],
        );
        $this->assertStringContainsString("la facture {$facture->numero} d'un montant de 120,00 € TTC, arrivée à échéance le 30/09/2026, n'est pas entièrement réglée : il reste 70,00 € à payer.", $relance->message);
        $this->assertStringContainsString("Si vous l'avez réglée entre-temps", $relance->message);
        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()->statut);
    }

    /**
     * Un particulier reçoit un ticket de caisse : la relance en parle
     * comme tel, accords compris.
     */
    public function test_la_relance_d_un_ticket_de_caisse_parle_du_ticket(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin('Ma Boutique');
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');

        $relance = app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);

        $this->assertSame("Relance n°1 : Ticket de caisse {$facture->numero} — Ma Boutique", $relance->objet);
        $this->assertStringContainsString("le ticket de caisse {$facture->numero} d'un montant de 120,00 € TTC, arrivé à échéance le 30/09/2026, n'est pas entièrement réglé : il reste 120,00 € à payer.", $relance->message);
        $this->assertStringContainsString("Si vous l'avez réglé entre-temps", $relance->message);
        $this->assertStringNotContainsString('facture', $relance->message);
    }

    public function test_une_relance_manuelle_suit_la_derniere(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);
        $this->travelTo('2026-10-16 10:00:00');

        $relance = app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);

        $this->assertSame(2, $relance->niveau_relance);
        $this->assertSame([1, 2], $facture->envois()->orderBy('niveau_relance')->pluck('niveau_relance')->all());
    }

    /**
     * Au plus une relance par jour et par facture : un double clic, ou la
     * relance automatique du matin, ne la fait pas repartir.
     */
    public function test_une_facture_ne_se_relance_qu_une_fois_par_jour(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);
        $this->travelTo('2026-10-15 23:59:00');

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null),
            LogicException::class,
            "La facture {$facture->numero} a déjà été relancée aujourd'hui : la relance suivante pourra partir demain.",
        );

        $this->assertSame(1, $facture->envois()->count());
        Queue::assertPushed(EnvoyerDocumentJob::class, 1);
    }

    /**
     * Une relance que le serveur d'envoi a refusée n'a jamais atteint le
     * client : c'est elle qui repart, au même niveau, vers l'adresse
     * corrigée -- même le jour de son échec -- et non la suivante.
     */
    public function test_une_relance_en_echec_repart_a_son_niveau_vers_l_adresse_corrigee(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $this->magasin();
        $expediteur = User::factory()->create();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        Mail::shouldReceive('to')->andThrow(new TransportException('550 Mailbox unavailable'));
        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->relancer($facture, 'erreur@exemple.fr', null), TransportException::class);
        $enEchec = $facture->envois()->sole();
        $this->assertSame(StatutEnvoi::Echec, $enEchec->statut);
        $this->assertSame(0, $facture->fresh()->niveauDeRelanceAtteint());
        // Le serveur d'envoi refonctionne : le vrai gestionnaire d'e-mails
        // revient à la place du simulacre en panne, puis est observé.
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        Mail::fake();
        $this->travelTo('2026-10-15 16:00:00');

        $relance = app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', $expediteur);

        $this->assertTrue($relance->is($enEchec));
        $relance->refresh();
        $this->assertSame(
            [1, 'client@exemple.fr', StatutEnvoi::Envoye, null, $expediteur->id, '2026-10-15 16:00:00'],
            [$relance->niveau_relance, $relance->destinataire, $relance->statut, $relance->erreur, $relance->envoye_par, $relance->created_at->format('Y-m-d H:i:s')],
        );
        $this->assertSame(1, $facture->envois()->count());
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr') && $mail->hasSubject("Relance n°1 : Ticket de caisse {$facture->numero} — {$facture->magasin->nom}"));
        $this->assertSame(1, $facture->fresh()->niveauDeRelanceAtteint());
    }

    /**
     * Le client archivé depuis doit toujours la facture : elle se renvoie
     * et se relance comme les autres.
     */
    public function test_la_facture_d_un_client_archive_s_envoie_et_se_relance(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin('Ma Boutique');
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettreFactureEchueLe($client, '2026-09-30');
        $client->delete();

        $envoi = app(EnvoyerDocumentAction::class)->handle($facture->fresh(), 'client@exemple.fr', null, null);
        $relance = app(EnvoyerDocumentAction::class)->relancer($facture->fresh(), 'client@exemple.fr', null);

        $this->assertSame("Facture {$facture->numero} — Ma Boutique", $envoi->objet);
        $this->assertSame("Relance n°1 : Facture {$facture->numero} — Ma Boutique", $relance->objet);
    }

    /**
     * Tous les magasins envoient depuis le domaine de l'éditeur : un membre
     * ne peut pas en faire un relais d'envoi en masse. Les relances
     * automatiques, sans expéditeur, ne sont pas comptées.
     */
    public function test_un_membre_envoie_au_plus_60_e_mails_par_heure(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $vendeur = User::factory()->create();
        $facture = $this->emettreFacture(Client::factory()->create());

        foreach (range(1, EnvoyerDocumentAction::ENVOIS_PAR_HEURE_ET_PAR_MEMBRE) as $numero) {
            app(EnvoyerDocumentAction::class)->handle($facture, "client{$numero}@exemple.fr", null, $vendeur);
        }

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, $vendeur),
            LogicException::class,
            'Trop d\'e-mails envoyés en une heure : réessayez dans 60 minute(s).',
        );
        $this->assertSame(60, $facture->envois()->count());

        app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, null);
        $this->travelTo('2026-10-15 11:00:01');
        app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, $vendeur);

        $this->assertSame(62, $facture->envois()->count());
    }

    /**
     * Plusieurs membres d'un même magasin n'additionnent pas leurs
     * plafonds au-delà de celui du magasin ; un autre magasin garde le
     * sien.
     */
    public function test_un_magasin_envoie_au_plus_200_e_mails_par_heure(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        $membres = User::factory()->count(4)->create();

        foreach (range(1, EnvoyerDocumentAction::ENVOIS_PAR_HEURE_ET_PAR_MAGASIN) as $numero) {
            app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, $membres[$numero % 4]);
        }

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, User::factory()->create()),
            LogicException::class,
            'Trop d\'e-mails envoyés en une heure',
        );

        $this->magasin();
        $factureDUnAutreMagasin = $this->emettreFacture(Client::factory()->create());
        app(EnvoyerDocumentAction::class)->handle($factureDUnAutreMagasin, 'client@exemple.fr', null, User::factory()->create());
        $this->assertSame(1, $factureDUnAutreMagasin->envois()->count());
    }

    public function test_une_facture_pas_encore_echue_ne_se_relance_pas(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-10-15');

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null),
            LogicException::class,
            "La facture {$facture->numero} n'est pas une facture échue et impayée : elle ne se relance pas.",
        );

        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    public function test_une_facture_echue_mais_payee_ne_se_relance_pas(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->magasin();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        $this->payer($facture, 120);

        $this->assertThrows(
            fn () => app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null),
            LogicException::class,
        );

        $this->assertSame(0, EnvoiDocument::query()->count());
    }

    private function magasin(?string $nom = null): Magasin
    {
        $magasin = Magasin::factory()->create($nom === null ? [] : ['nom' => $nom]);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Une facture de 120,00 € TTC.
     */
    private function emettreFacture(Client $client): Facture
    {
        $facture = app(CreerFactureAction::class)->handle($client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreFactureAction::class)->handle($facture);
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

    private function emettreAvoir(): Avoir
    {
        $facture = $this->emettreFacture(Client::factory()->create());
        $avoir = app(CreerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]);

        return app(EmettreAvoirAction::class)->handle($avoir);
    }

    private function payer(Facture $facture, float $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
            'mode_paiement' => ModePaiement::Especes,
            'montant' => $montant,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
