<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AnnulerFactureAction;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Notifications\EnvoiDocumentEchoue;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class EnvoyerDocumentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_facture_part_au_destinataire_avec_son_pdf_au_nom_du_magasin(): void
    {
        Mail::fake();
        $this->magasin(['nom' => 'Ma Boutique', 'email' => 'contact@ma-boutique.fr']);
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create(), ['date_echeance' => '2026-11-30']);

        $envoi = app(EnvoyerDocumentAction::class)->handle($facture, 'compta@client.fr', "Bonjour Paul,\nVoici la facture de la réparation.", null);

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

    public function test_l_e_mail_presente_le_document_puis_le_message_de_l_expediteur(): void
    {
        $this->magasin(['nom' => 'Ma Boutique', 'telephone' => '01 23 45 67 89']);
        $facture = $this->emettreFacture(Client::factory()->professionnel()->create(), ['date_echeance' => '2026-11-30']);
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
        Mail::fake();
        $this->magasin();
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], ['date_validite' => '2026-12-31']);

        app(EnvoyerDocumentAction::class)->handle($devis, 'client@exemple.fr', null, null);

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
        $avoir = app(EmettreAvoirAction::class)->handle(app(CreerAvoirAction::class)->handle($facture->client, [['ligne_facture_id' => $facture->lignes->first()->id, 'quantite' => 1]]));

        app(EnvoyerDocumentAction::class)->handle($avoir, 'client@exemple.fr', null, null);

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
        $facture = $this->emettreFacture(Client::factory()->create(), ['date_echeance' => '2026-09-30']);

        app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);

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
        $facture = $this->emettreFacture(Client::factory()->create(), ['date_echeance' => '2026-09-30']);
        Queue::fake([EnvoyerDocumentJob::class]);
        $relance = app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);
        $this->payer($facture, 120);

        (new EnvoyerDocumentJob($magasin->id, $relance->id))->handle();

        Mail::assertNothingSent();
        $relance->refresh();
        $this->assertSame([StatutEnvoi::Echec, 'La facture a été réglée ou soldée par un avoir entre-temps : la relance n\'est pas partie.'], [$relance->statut, $relance->erreur]);
        $this->assertSame(1, $magasin->proprietaires()->sole()->notifications()->count());
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
        $facture = $this->emettreFacture(Client::factory()->create(), ['date_echeance' => '2026-09-30']);
        Queue::fake([EnvoyerDocumentJob::class]);
        $relance = app(EnvoyerDocumentAction::class)->relancer($facture, 'client@exemple.fr', null);
        $this->payer($facture, 50);

        (new EnvoyerDocumentJob($magasin->id, $relance->id))->handle();

        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => str_contains($mail->render(), 'il reste 70,00 € à payer'));
        $this->assertStringContainsString('il reste 70,00 € à payer', $relance->fresh()->message);
        $this->assertSame(StatutEnvoi::Envoye, $relance->fresh()->statut);
    }

    public function test_une_facture_annulee_entre_temps_ne_part_pas(): void
    {
        Mail::fake();
        $magasin = $this->magasin();
        $facture = $this->emettreFacture(Client::factory()->create());
        Queue::fake([EnvoyerDocumentJob::class]);
        $envoi = app(EnvoyerDocumentAction::class)->handle($facture, 'client@exemple.fr', null, null);
        app(AnnulerFactureAction::class)->handle($facture->fresh(), 'Erreur de client');

        (new EnvoyerDocumentJob($magasin->id, $envoi->id))->handle();

        Mail::assertNothingSent();
        $envoi->refresh();
        $this->assertSame([StatutEnvoi::Echec, 'Le document est passé au statut « Annulée » entre-temps : l\'e-mail n\'est pas parti.'], [$envoi->statut, $envoi->erreur]);
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
        $this->assertSame(1, $magasin->proprietaires()->sole()->notifications()->count());
    }

    /**
     * Toutes les tentatives ont échoué : l'envoi passe en échec avec une
     * explication pour le magasin -- jamais la réponse du serveur, qui
     * peut contenir un identifiant -- et l'expéditeur est prévenu, dans
     * une notification qui nomme le magasin.
     */
    public function test_un_echec_passe_l_envoi_en_echec_et_previent_l_expediteur(): void
    {
        $magasin = $this->magasinAvecProprietaire('Ma Boutique');
        $vendeur = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Vendeur', 'email' => 'vendeur@ma-boutique.fr', 'password' => 'secret123'], RoleMagasin::Vendeur);
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
        $this->assertSame(0, $magasin->proprietaires()->sole()->notifications()->count());
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

        $notification = $magasin->proprietaires()->sole()->notifications()->sole();
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
        $this->assertSame(1, $magasin->proprietaires()->sole()->notifications()->count());
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
        return app(CreerMagasinAction::class)->handle(['nom' => $nom], User::factory()->create());
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Une facture de 120,00 € TTC.
     *
     * @param  array<string, mixed>  $attributs
     */
    private function emettreFacture(Client $client, array $attributs = []): Facture
    {
        $facture = app(CreerFactureAction::class)->handle($client, [['produit_id' => $this->produit()->id, 'quantite' => 1]], $attributs);

        return app(EmettreFactureAction::class)->handle($facture);
    }

    private function payer(Facture $facture, float $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle($facture->client, [
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
