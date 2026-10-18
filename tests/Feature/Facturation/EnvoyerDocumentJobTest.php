<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\DocumentSansEnregistrementSigneException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\EnvoiDocumentEchoue;
use App\Domain\Facturation\Services\PartDeReglement;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EnvoyerDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_facture_part_au_destinataire_avec_son_pdf_au_nom_du_magasin(): void
    {
        Mail::fake();
        $this->magasin(['nom' => 'Ma Boutique', 'email' => 'contact@ma-boutique.fr']);
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create());

        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'compta@client.fr', "Bonjour Paul,\nVoici la facture de la réparation.");

        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($facture): bool {
            $pdf = $mail->attachments()[0];

            return $mail->hasTo('compta@client.fr')
                && $mail->hasFrom(config('mail.from.address'), 'Ma Boutique')
                && $mail->hasReplyTo('contact@ma-boutique.fr', 'Ma Boutique')
                && $mail->hasSubject("Facture {$facture->numero} — Ma Boutique")
                && [$pdf->as, $pdf->mime] === ["facture-{$facture->numero}.pdf", 'application/pdf']
                && str_starts_with($this->contenuDe($pdf), '%PDF');
        });
        $envoi->refresh();
        $this->assertSame(StatutEnvoi::Envoye, $envoi->statut);
        $this->assertNotNull($envoi->envoye_le);
        $this->assertNull($envoi->erreur);
    }

    /**
     * Émise le 31 octobre, au délai de paiement du magasin (30 jours), la
     * facture est à régler au plus tard le 30 novembre.
     */
    public function test_l_e_mail_presente_le_document_puis_le_message_de_l_expediteur(): void
    {
        $this->travelTo('2026-10-31 10:00:00');
        $this->magasin(['nom' => 'Ma Boutique', 'telephone' => '01 23 45 67 89']);
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create());
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->create(['message' => "Bonjour Paul,\n\nVoici la facture de la réparation."]);

        $mail = new DocumentMail($envoi, '%PDF', 'facture.pdf');

        // Le rendu de l'e-mail ne réencode pas les apostrophes.
        $mail->assertSeeInOrderInHtml([
            'Ma Boutique',
            "Veuillez trouver ci-joint notre facture {$facture->numero} d'un montant de 120,00 € TTC, à régler au plus tard le 30/11/2026.",
            'Bonjour Paul,',
            'Voici la facture de la réparation.',
            'Cordialement,',
            'Tél. 01 23 45 67 89',
        ], escape: false);
    }

    /**
     * Vendue au comptoir et payée par carte à son émission, la facture a
     * bien une échéance (B7), mais son e-mail ne demande plus de la régler.
     */
    public function test_l_e_mail_d_une_facture_reglee_a_son_emission_ne_demande_pas_de_la_regler(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $this->magasin();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(),
            app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]),
            new ReglementComptant([new PartDeReglement(ModePaiement::CarteBancaire, 12000)]),
        );
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->create(['message' => 'Merci de votre visite.']);

        $mail = new DocumentMail($envoi, '%PDF', 'facture.pdf');

        $mail->assertSeeInHtml("Veuillez trouver ci-joint notre facture {$facture->numero} d'un montant de 120,00 € TTC, déjà réglée.", escape: false);
        $mail->assertDontSeeInHtml('à régler');
        $this->assertSame(['2026-11-08', 0.0], [$facture->date_echeance->toDateString(), $facture->montantRestant()]);
    }

    /**
     * Le message de l'expéditeur arrive tel qu'il l'a écrit : jamais
     * interprété comme du HTML.
     */
    public function test_le_message_de_l_expediteur_n_est_jamais_interprete(): void
    {
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->create(['message' => '<script>alert(1)</script>']);

        $mail = new DocumentMail($envoi, '%PDF', 'facture.pdf');

        $mail->assertDontSeeInHtml('<script>alert(1)</script>', escape: false);
        $mail->assertSeeInHtml('<script>alert(1)</script>');
    }

    /**
     * Ni le Markdown de l'e-mail : un lien, un titre, une liste ou une mise
     * en forme écrits dans le message arrivent comme du texte -- jamais un
     * lien cliquable qui mènerait ailleurs que ce qu'il annonce, envoyé
     * depuis le domaine de l'éditeur. La version texte le reprend mot pour
     * mot.
     */
    public function test_le_message_de_l_expediteur_n_est_jamais_mis_en_forme(): void
    {
        $this->magasin(['nom' => 'Boutique *étoilée*']);
        $facture = $this->emettreFacture(Client::factory()->create());
        $message = "# Urgent\nRéglez ici : [payer](https://pirate.example/payer)\nmerci *beaucoup*\n1. vite";
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->create(['message' => $message]);

        $mail = new DocumentMail($envoi, '%PDF', 'facture.pdf');

        foreach (['href="https://pirate.example/payer"', '<h1', '<em>', '<ol'] as $balise) {
            $mail->assertDontSeeInHtml($balise, escape: false);
        }
        $mail->assertSeeInOrderInHtml(['# Urgent', 'Réglez ici : [payer](https://pirate.example/payer)', 'merci *beaucoup*', '1. vite', 'Boutique *étoilée*'], escape: false);
        $mail->assertSeeInOrderInText(['# Urgent', 'Réglez ici : [payer](https://pirate.example/payer)', 'merci *beaucoup*', '1. vite', 'Cordialement,']);
        $mail->assertDontSeeInText('<p>');
    }

    public function test_le_devis_part_avec_son_pdf_et_sa_date_de_validite(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        Mail::fake();
        $this->magasin();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], ['date_validite' => '2026-12-31']);

        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null);

        $devis->refresh();
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->attachments()[0]->as === "devis-{$devis->numero}.pdf");
        $this->assertStringContainsString(
            "notre devis {$devis->numero} d'un montant de 120,00 € TTC, valable jusqu'au 31/12/2026.",
            (new DocumentMail($devis->envois()->firstOrFail(), '%PDF', 'devis.pdf'))->render(),
        );
    }

    public function test_l_avoir_part_avec_son_pdf(): void
    {
        Mail::fake();
        $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]));

        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $avoir, 'client@exemple.fr', null);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->attachments()[0]->as === "avoir-{$avoir->numero}.pdf"
            && str_starts_with($this->contenuDe($mail->attachments()[0]), '%PDF'));
    }

    /**
     * Une relance n'a pas l'introduction d'un envoi : son rappel dit déjà
     * tout de la facture.
     */
    public function test_une_relance_part_avec_son_rappel_et_la_facture(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        $this->magasin();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');

        app(EnvoyerDocumentAction::class)->relancer(self::auteur(), $facture, 'client@exemple.fr');

        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($facture): bool {
            $html = $mail->render();

            return $mail->attachments()[0]->as === "ticket-{$facture->numero}.pdf"
                && str_contains($html, 'il reste 120,00 € à payer')
                && ! str_contains($html, 'Veuillez trouver ci-joint');
        });
    }

    public function test_un_envoi_deja_envoye_ne_repart_jamais(): void
    {
        Mail::fake();
        $magasin = $this->magasin();
        $envoi = EnvoiDocument::factory()->for($this->emettreFacture(Client::factory()->create()), 'document')->envoye()->create();

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
    }

    /**
     * La relance a été programmée, puis la facture réglée avant qu'elle ne
     * parte (file d'attente chargée, nouvelle tentative) : elle ne part
     * pas, et le magasin sait pourquoi.
     */
    public function test_une_relance_ne_part_pas_si_la_facture_a_ete_reglee_entre_temps(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        Queue::fake([EnvoyerDocumentJob::class]);
        $relance = app(EnvoyerDocumentAction::class)->relancer($this->proprietaireDe($magasin), $facture, 'client@exemple.fr');
        $this->payer($facture, 120);

        (new EnvoyerDocumentJob($magasin->id, $relance->id))->handle();

        Mail::assertNothingSent();
        $relance->refresh();
        $this->assertSame([StatutEnvoi::Echec, 'La facture a été réglée ou soldée par un avoir entre-temps : la relance n\'est pas partie.'], [$relance->statut, $relance->erreur]);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->count());
    }

    /**
     * L'envoi a été programmé, puis le magasin suspendu avant qu'il ne
     * parte (file d'attente chargée, nouvelle tentative) : un magasin en
     * lecture seule n'écrit plus à ses clients, et il sait pourquoi.
     */
    public function test_un_envoi_ne_part_pas_si_le_magasin_a_ete_suspendu_entre_temps(): void
    {
        Mail::fake();
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $facture = $this->emettreFacture(Client::factory()->create());
        Queue::fake([EnvoyerDocumentJob::class]);
        $envoi = app(EnvoyerDocumentAction::class)->handle($this->proprietaireDe($magasin), $facture, 'client@exemple.fr', null);
        $magasin->forceFill(['suspendu_le' => now()])->save();

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
        $envoi->refresh();
        $this->assertSame([StatutEnvoi::Echec, 'Le magasin est suspendu : il n\'écrit plus à ses clients, l\'e-mail n\'est pas parti.'], [$envoi->statut, $envoi->erreur]);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->count());
    }

    /**
     * Payée en partie entre la programmation de la relance et son départ :
     * le rappel dit ce qu'il reste à payer au moment où elle part.
     */
    public function test_le_rappel_d_une_relance_dit_ce_qu_il_reste_a_payer_quand_elle_part(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        $magasin = $this->magasin();
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        Queue::fake([EnvoyerDocumentJob::class]);
        $relance = app(EnvoyerDocumentAction::class)->relancer(self::auteur(), $facture, 'client@exemple.fr');
        $this->payer($facture, 50);

        (new EnvoyerDocumentJob($magasin->id, $relance->id))->handle();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => str_contains($mail->render(), 'il reste 70,00 € à payer'));
        $this->assertStringContainsString('il reste 70,00 € à payer', $relance->fresh()->message);
        $this->assertSame(StatutEnvoi::Envoye, $relance->fresh()->statut);
    }

    /**
     * La facture a été corrigée par un avoir entre la programmation de son
     * envoi et son départ : elle reste le document émis de son client, et
     * part quand même.
     */
    public function test_une_facture_corrigee_par_un_avoir_entre_temps_part_quand_meme(): void
    {
        Mail::fake();
        $magasin = $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        Queue::fake([EnvoyerDocumentJob::class]);
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $facture, 'client@exemple.fr', null);
        app(EnregistrerAvoirAction::class)->handle(self::auteur(), $facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]], 'Erreur de client');

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr')
            && $mail->attachments()[0]->as === "ticket-{$facture->numero}.pdf");
        $this->assertSame(StatutEnvoi::Envoye, $envoi->fresh()->statut);
    }

    /**
     * Une adresse que le composant d'envoi refuse (ici celle du magasin,
     * pour les réponses, enregistrée avant les règles actuelles) : l'envoi
     * passe tout de suite en échec, sans nouvelle tentative inutile, avec
     * une explication qui dit quoi corriger.
     */
    public function test_une_adresse_refusee_par_le_composant_d_envoi_echoue_sans_nouvelle_tentative(): void
    {
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $magasin->update(['email' => 'contact(boutique)@ma-boutique.fr']);
        $envoi = EnvoiDocument::factory()->for($this->emettreFacture(Client::factory()->create()), 'document')->create(['destinataire' => 'client@exemple.fr']);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        $envoi->refresh();
        $this->assertSame(StatutEnvoi::Echec, $envoi->statut);
        $this->assertSame('Une adresse e-mail n\'est pas valide (celle du destinataire, ou celle du magasin, à laquelle le client répond) : corrigez-la, puis renvoyez-le.', $envoi->erreur);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->count());
    }

    /**
     * Un ticket numéroté directement en base n'a aucun enregistrement
     * signé : il ne part pas avec un PDF reconstitué depuis le magasin
     * d'aujourd'hui. L'envoi échoue sans nouvelle tentative, le magasin
     * sait pourquoi, et l'incident est signalé au journal de
     * l'application.
     */
    public function test_un_document_numerote_sans_enregistrement_signe_ne_part_pas(): void
    {
        Mail::fake();
        Exceptions::fake([DocumentSansEnregistrementSigneException::class]);
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-MABOUT-2026-000009', 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);
        $envoi = EnvoiDocument::factory()->for($brouillon->fresh(), 'document')->create(['destinataire' => 'client@exemple.fr']);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
        $envoi->refresh();
        $this->assertSame(StatutEnvoi::Echec, $envoi->statut);
        $this->assertSame('Le ticket TK-MABOUT-2026-000009 n\'a aucun enregistrement signé au registre fiscal : son PDF n\'est pas produit. Lancez la vérification de l\'intégrité (Ventes > Caisse sécurisée) et contactez le support. L\'e-mail n\'est pas parti.', $envoi->erreur);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->count());
        Exceptions::assertReported(DocumentSansEnregistrementSigneException::class);
    }

    /**
     * Toutes les tentatives ont échoué : l'envoi passe en échec avec une
     * explication pour le magasin -- jamais la réponse du serveur, qui
     * peut contenir un identifiant -- et l'expéditeur est prévenu, dans
     * une notification qui nomme le magasin.
     */
    public function test_un_echec_passe_l_envoi_en_echec_et_previent_l_expediteur(): void
    {
        $magasin = $this->completerIdentiteLegale($this->magasinAvecProprietaire('Ma Boutique'));
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr']);
        $this->actingAs($vendeur);
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create());
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->create(['destinataire' => 'compta@client.fr', 'envoye_par' => $vendeur->id]);
        Mail::shouldReceive('to')->andThrow(new TransportException('535 Authentication failed for smtp-user@editeur.example'));

        $this->assertThrows(fn () => EnvoyerDocumentJob::dispatchSync($magasin->id, $envoi->id), TransportException::class);

        $envoi->refresh();
        $this->assertSame(StatutEnvoi::Echec, $envoi->statut);
        $this->assertSame('Le serveur d\'envoi des e-mails n\'a pas accepté le message : vérifiez l\'adresse du destinataire, puis renvoyez-le.', $envoi->erreur);
        $notification = $vendeur->notifications()->sole();
        $this->assertSame(EnvoiDocumentEchoue::class, $notification->type);
        $this->assertSame("Ma Boutique : Échec de l'envoi : Facture {$facture->numero}", $notification->data['title']);
        $this->assertStringContainsString('L\'e-mail à compta@client.fr n\'est pas parti.', $notification->data['body']);
        $this->assertStringNotContainsString('smtp-user', $notification->data['body']);
        $this->assertSame($magasin->id, $notification->data['magasin_id']);
        $this->assertSame(0, $this->proprietaireDe($magasin)->notifications()->count());
    }

    /**
     * Une relance automatique n'a pas d'expéditeur : le propriétaire du
     * magasin est prévenu de son échec.
     */
    public function test_l_echec_d_une_relance_automatique_previent_le_proprietaire(): void
    {
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $facture = $this->emettreFacture(Client::factory()->create());
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->relance(2)->create(['destinataire' => 'client@exemple.fr']);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->failed(new TransportException('Connexion refusée'));

        $notification = $this->proprietaireDe($magasin)->notifications()->sole();
        $this->assertStringContainsString('La relance n°2 à client@exemple.fr n\'est pas partie.', $notification->data['body']);
        $envoi->refresh();
        $this->assertSame(StatutEnvoi::Echec, $envoi->statut);
        $this->assertSame('Le serveur d\'envoi des e-mails n\'a pas accepté le message : vérifiez l\'adresse du destinataire, puis relancez la facture (la relance n°2 repartira).', $envoi->erreur);
    }

    /**
     * Un expéditeur qui a quitté le magasin entre-temps ne reçoit plus rien
     * de lui : c'est le propriétaire qui est prévenu.
     */
    public function test_un_expediteur_qui_a_quitte_le_magasin_n_est_pas_prevenu(): void
    {
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $ancienMembre = User::factory()->create();
        $envoi = EnvoiDocument::factory()->for($this->emettreFacture(Client::factory()->create()), 'document')->create(['envoye_par' => $ancienMembre->id]);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->failed(new TransportException('Connexion refusée'));

        $this->assertSame(0, $ancienMembre->notifications()->count());
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function droitsPerdusAvantLeDepart(): array
    {
        return array_combine($cas = [
            'retiré du magasin',
            'suspendu',
            'retiré de la société',
            'refusé',
            'privé de l\'autorisation d\'envoyer',
            'facture devenue invisible pour lui',
        ], array_map(static fn (string $nom): array => [$nom], $cas));
    }

    /**
     * Le vendeur a demandé l'envoi d'une facture qu'il voyait, puis perd
     * son accès au magasin ou son droit d'envoyer cette facture avant que
     * l'e-mail ne parte : relu au départ, il ne fait rien partir, sans
     * nouvelle tentative, et le propriétaire est prévenu, pas lui.
     */
    #[DataProvider('droitsPerdusAvantLeDepart')]
    public function test_un_envoi_demande_par_un_membre_retire_ou_suspendu_entre_temps_ne_part_pas_et_le_proprietaire_est_prevenu(string $cas): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        app(TenantContext::class)->set($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        $cases = [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES];
        $vendeur = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => $cases], $autre->id => ['role' => RoleMagasin::Vendeur]], $magasin);
        $facture = $this->emettreFacture(Client::factory()->create());
        $envoi = app(EnvoyerDocumentAction::class)->handle($vendeur, $facture, 'client@exemple.fr', null);

        match ($cas) {
            'retiré du magasin' => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, [$magasin->id => null]),
            'suspendu' => app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $vendeur),
            'retiré de la société' => app(ChangerStatutCompteAction::class)->retirer($proprietaire, $vendeur),
            'refusé' => app(RefuserSonCompteAction::class)->handle($vendeur),
            'privé de l\'autorisation d\'envoyer' => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => array_values(array_diff($cases, [PermissionRegistry::FACTURE_ENVOYER]))]]),
            'facture devenue invisible pour lui' => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => RoleMagasin::Vendeur->permissionsParDefaut()]]),
        };

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
        $envoi->refresh();
        $this->assertSame([StatutEnvoi::Echec, EnvoyerDocumentJob::MOTIF_EXPEDITEUR_SANS_DROIT], [$envoi->statut, $envoi->erreur]);
        $this->assertSame(1, $proprietaire->notifications()->where('type', EnvoiDocumentEchoue::class)->count());
        $this->assertSame(0, $vendeur->notifications()->where('type', EnvoiDocumentEchoue::class)->count());
    }

    /**
     * Une relance demandée à la main se relit au départ comme tout envoi :
     * son auteur, suspendu entre-temps, ne la fait pas partir, et le
     * propriétaire est prévenu.
     */
    public function test_une_relance_manuelle_d_un_membre_suspendu_entre_temps_ne_part_pas(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        $relance = app(EnvoyerDocumentAction::class)->relancer($vendeur, $facture, 'client@exemple.fr');
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $vendeur);

        (new EnvoyerDocumentJob($magasin->id, $relance->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame([StatutEnvoi::Echec, EnvoyerDocumentJob::MOTIF_EXPEDITEUR_SANS_DROIT], [$relance->refresh()->statut, $relance->erreur]);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->where('type', EnvoiDocumentEchoue::class)->count());
    }

    /**
     * Une relance automatique n'a pas d'auteur : elle part même quand la
     * personne qui avait émis la facture a quitté la société depuis.
     */
    public function test_une_relance_automatique_ne_depend_d_aucun_auteur(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        Mail::fake();
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $vendeur = $this->vendeurDeLaPremiereHeure($magasin);
        $this->actingAs($vendeur);
        $facture = $this->emettreFactureEchueLe(Client::factory()->create(), '2026-09-30');
        auth()->logout();
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $vendeur);
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->relance(1)->create(['destinataire' => 'client@exemple.fr', 'envoye_par' => null]);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr'));
        $this->assertSame(StatutEnvoi::Envoye, $envoi->refresh()->statut);
    }

    /**
     * L'échec d'un envoi demandé par un administrateur, qui ouvre toujours
     * le magasin, le prévient lui, pas le propriétaire.
     */
    public function test_l_echec_d_un_envoi_d_un_administrateur_le_previent_lui(): void
    {
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $administrateur = $this->administrateur($magasin);
        $facture = $this->emettreFacture(Client::factory()->create());
        $envoi = EnvoiDocument::factory()->for($facture, 'document')->create(['destinataire' => 'client@exemple.fr', 'envoye_par' => $administrateur->id]);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->failed(new TransportException('Connexion refusée'));

        $this->assertSame(EnvoiDocumentEchoue::class, $administrateur->notifications()->sole()->type);
        $this->assertSame(0, $this->proprietaireDe($magasin)->notifications()->count());
    }

    /**
     * Un devis, numéroté dès son premier envoi, ne se supprime plus (voir
     * Devis::estSupprimable()) : la suppression tentée avant le départ de
     * son envoi est refusée, et l'e-mail part.
     */
    public function test_un_devis_qui_attend_le_depart_de_son_envoi_ne_se_supprime_pas_et_l_envoi_part(): void
    {
        Mail::fake();
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
        $envoi = EnvoiDocument::factory()->for($devis, 'document')->create(['destinataire' => 'client@exemple.fr']);
        $this->assertThrows(fn () => $devis->delete(), LogicException::class, 'Ce devis ne peut pas être supprimé : seul un brouillon jamais envoyé se supprime, un devis numéroté reste.');

        EnvoyerDocumentJob::dispatchSync($magasin->id, $envoi->id);

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasTo('client@exemple.fr'));
        $this->assertSame(StatutEnvoi::Envoye, $envoi->fresh()->statut);
    }

    /**
     * L'envoi a été programmé, puis le devis modifié avant qu'il ne parte
     * (file d'attente chargée, nouvelle tentative) : repassé en brouillon,
     * cette version n'a pas été émise (K8), le client ne la reçoit pas, et
     * le magasin sait pourquoi.
     */
    public function test_un_devis_modifie_avant_le_depart_de_son_envoi_ne_part_pas(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        Mail::fake();
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $this->actingAs($this->proprietaireDe($magasin));
        $produit = $this->produit();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => '2026-10-31']);
        Queue::fake([EnvoyerDocumentJob::class]);
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null);
        app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $devis->client, [['produit_id' => $produit->id, 'quantite' => 5]]);

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
        $envoi->refresh();
        $this->assertSame([StatutEnvoi::Echec, 'Le devis a été modifié depuis cette demande d\'envoi : l\'e-mail n\'est pas parti, envoyez sa nouvelle version à votre client si ce n\'est pas déjà fait.'], [$envoi->statut, $envoi->erreur]);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->count());
        $devis->refresh();
        $this->assertSame([StatutDevis::Brouillon, '600.00'], [$devis->statut, $devis->total_ttc]);
    }

    /**
     * Modifié puis renvoyé avant le départ de son premier envoi : le
     * premier e-mail, écrit pour la version précédente, ne part pas ; le
     * client ne reçoit que le second, avec la version de 600,00 € TTC.
     */
    public function test_un_devis_modifie_puis_renvoye_ne_part_qu_avec_son_dernier_envoi(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        Mail::fake();
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $produit = $this->produit();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => '2026-10-31']);
        Queue::fake([EnvoyerDocumentJob::class]);
        $premier = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', 'Voici le devis pour un article.');
        $this->travelTo('2026-10-09 10:20:00');
        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $devis->client, [['produit_id' => $produit->id, 'quantite' => 5]]);
        $second = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $modifie, 'client@exemple.fr', 'Voici le devis pour cinq articles.');

        (new EnvoyerDocumentJob($magasin->id, $premier->id))->handle();
        (new EnvoyerDocumentJob($magasin->id, $second->id))->handle();

        $this->assertSame([$second->id], Mail::sent(DocumentMail::class)->map(fn (DocumentMail $mail): int => $mail->envoi->id)->all());
        $this->assertSame(
            [StatutEnvoi::Echec, 'Le devis a été modifié depuis cette demande d\'envoi : l\'e-mail n\'est pas parti, envoyez sa nouvelle version à votre client si ce n\'est pas déjà fait.'],
            [$premier->fresh()->statut, $premier->fresh()->erreur],
        );
        $this->assertSame(StatutEnvoi::Envoye, $second->fresh()->statut);
        $this->assertStringContainsString("notre devis {$modifie->numero} d'un montant de 600,00 € TTC", (new DocumentMail($second->fresh(), '%PDF', 'devis.pdf'))->render());
    }

    public function test_le_job_envoie_dans_le_magasin_de_l_envoi_quel_que_soit_le_contexte(): void
    {
        Mail::fake();
        $magasinB = $this->magasin(['nom' => 'Boutique B']);
        $envoiDeB = EnvoiDocument::factory()->for($this->emettreFacture(Client::factory()->create()), 'document')->create();
        $this->magasin(['nom' => 'Boutique A']);

        (new EnvoyerDocumentJob($magasinB->id, $envoiDeB->id))->handle();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->hasFrom(config('mail.from.address'), 'Boutique B'));
        $this->assertSame($magasinB->id, app(TenantContext::class)->id());
        $this->assertSame(StatutEnvoi::Envoye, $envoiDeB->fresh()->statut);
    }

    public function test_un_job_qui_designe_l_envoi_d_un_autre_magasin_ne_l_envoie_pas(): void
    {
        Mail::fake();
        $magasinA = $this->magasin();
        $this->magasin();
        $envoiDeB = EnvoiDocument::factory()->for($this->emettreFacture(Client::factory()->create()), 'document')->create();

        $this->assertThrows(fn () => (new EnvoyerDocumentJob($magasinA->id, $envoiDeB->id))->handle(), ModelNotFoundException::class);

        Mail::assertNothingSent();
        $this->assertSame(StatutEnvoi::EnAttente, $envoiDeB->fresh()->statut);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function magasin(array $attributs = []): Magasin
    {
        $magasin = Magasin::factory()->create($attributs);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function magasinAvecProprietaire(string $nom): Magasin
    {
        return $this->ouvrirUneSociete(['nom' => $nom]);
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
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit()->id, 'quantite' => 1]]);

        return app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
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

    private function payer(Facture $facture, float $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Especes,
            'montant' => $montant,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }

    private function contenuDe(Attachment $pieceJointe): string
    {
        return $pieceJointe->attachWith(fn (): string => '', fn (\Closure $donnees): string => $donnees());
    }
}
