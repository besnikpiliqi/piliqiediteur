<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\RetirerLeLienDuDevisAction;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\ReponseAuDevis;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\LienDuDevis;
use App\Domain\Facturation\Models\ReponseDuDevis;
use App\Domain\Facturation\Notifications\ReponseDuClientAuDevis;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * La réponse du client à son devis par le lien secret de son envoi, sans
 * compte (décision de l'éditeur du 02/11/2026, lot DV) : un jeton d'au
 * moins 256 bits par envoi, gardé par sa seule empreinte ; une page sans
 * index ni référent, qui ne montre que ce devis ; un lien valable jusqu'à
 * la fin de la validité du devis et caduc dès sa réponse, sa modification,
 * un nouvel envoi ou son retrait ; l'acceptation par le nom tapé et
 * « Bon pour accord », ou le refus, enregistrés une seule fois avec leurs
 * traces ; l'e-mail de confirmation au client et l'avis au magasin.
 */
class ReponseAuDevisEnLigneTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-12 10:00:00'));
        Mail::fake();
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'code' => 'MABOUT']));
        app(TenantContext::class)->set($this->magasin);
    }

    public function test_l_envoi_d_un_devis_porte_un_lien_secret_dont_seule_l_empreinte_se_garde(): void
    {
        $devis = $this->devisEnvoyable();

        $jeton = $this->envoyer($devis);

        $lien = LienDuDevis::query()->sole();
        $this->assertSame(64, strlen($jeton));
        $this->assertSame(hash('sha256', $jeton), $lien->jeton_hache);
        $this->assertSame([$devis->id, EnvoiDocument::query()->sole()->id], [$lien->devis_id, $lien->envoi_document_id]);
        $this->assertFalse(DB::table('liens_des_devis')->where('jeton_hache', $jeton)->exists());
        $this->assertFalse(DB::table('envois_documents')->where('message', 'like', "%{$jeton}%")->orWhere('objet', 'like', "%{$jeton}%")->exists());
        $this->assertFalse(DB::table('journal_audit')->where('nouvelles_valeurs', 'like', "%{$jeton}%")->exists());
    }

    public function test_la_page_du_lien_montre_le_seul_devis_envoye_sans_indexation_ni_referent(): void
    {
        $devis = $this->devisEnvoyable(['date_validite' => '2026-11-30']);
        $jeton = $this->envoyer($devis);

        $reponse = $this->repondre('get', $jeton);

        $reponse->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee("Devis {$devis->numero}")
            ->assertSee('Ma Boutique')
            ->assertSee('Valable jusqu\'au', false)
            ->assertSee('30/11/2026')
            ->assertSee('Câble tressé')
            ->assertSee('144,00 €')
            ->assertSee('Bon pour accord')
            ->assertSee('Accepter le devis')
            ->assertSee('Refuser le devis')
            ->assertDontSee('/app', false)
            ->assertDontSee('/societe', false);
        $this->assertSame(1, preg_match_all('/href="([^"]+)"/', (string) $reponse->getContent(), $liens));
        $this->assertSame([route('devis.reponse.pdf', ['jeton' => $jeton])], $liens[1]);
    }

    public function test_le_pdf_du_lien_est_celui_du_devis_envoye_en_piece_jointe(): void
    {
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis);
        app(TenantContext::class)->clear();

        $reponse = $this->get(route('devis.reponse.pdf', ['jeton' => $jeton]));

        $reponse->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('attachment', (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString("devis-{$devis->numero}.pdf", (string) $reponse->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $reponse->streamedContent());
    }

    public function test_un_jeton_inconnu_ne_dit_que_ce_lien_n_est_plus_valable(): void
    {
        $this->envoyer($this->devisEnvoyable());

        foreach (['get', 'pdf', 'accepter', 'refuser'] as $geste) {
            $this->repondre($geste, Str::random(64))->assertStatus(410)->assertSee(LienDuDevis::PLUS_VALABLE)->assertDontSee('Ma Boutique')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }

        $this->get('/devis/'.Str::random(63))->assertNotFound();
        $this->assertSame(0, ReponseDuDevis::query()->count());
    }

    public function test_accepter_enregistre_le_nom_la_date_l_empreinte_et_les_traces_une_seule_fois(): void
    {
        Notification::fake();
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis);
        $empreinte = $devis->fresh()->empreinteDeSaVersion();
        $this->travelTo(self::aParis('2026-11-13 14:05:00'));

        $this->repondre('accepter', $jeton, ['signataire' => '  Claire   Martin ', 'bon_pour_accord' => '1'], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Navigateur du client'])
            ->assertOk()
            ->assertSee('Merci : votre acceptation est enregistrée')
            ->assertSee('13/11/2026 à 14:05 (heure de Paris)')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $enregistree = ReponseDuDevis::query()->sole();
        $this->assertSame([ReponseAuDevis::Acceptation, 'Claire Martin', null, $empreinte, self::aParis('2026-11-13 14:05:00')->getTimestamp()], [$enregistree->reponse, $enregistree->signataire, $enregistree->motif, $enregistree->empreinte_du_devis, $enregistree->created_at->getTimestamp()]);
        $devis->refresh();
        $this->assertSame([StatutDevis::Accepte, self::aParis('2026-11-13 14:05:00')->getTimestamp()], [$devis->statut, $devis->accepte_le?->getTimestamp()]);

        $entree = EntreeAudit::query()->where('action', ActionAuditee::DevisAccepteEnLigne)->sole();
        $this->assertSame(['client', null, (int) $this->magasin->id, ['devis' => $devis->numero, 'empreinte_du_devis' => $empreinte, 'signataire' => 'Claire Martin']], [$entree->origine, $entree->auteur_id, (int) $entree->magasin_id, $entree->nouvelles_valeurs]);
        $this->assertSame(['203.0.113.9', 'Navigateur du client'], [TraceAudit::query()->findOrFail($entree->id)->ip, TraceAudit::query()->findOrFail($entree->id)->agent_utilisateur]);

        $evenement = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement)->where('type', (string) CodeEvenement::ReponseAUnDevis->value)->sole();
        $this->assertSame(['devis_id' => $devis->id, 'numero' => $devis->numero, 'reponse' => 'acceptation', 'empreinte_du_devis' => $empreinte], json_decode($evenement->contenu, true)['donnees']['details']);
        $this->assertSame('203.0.113.9', TraceEvenement::query()->findOrFail($evenement->id)->ip);
        $this->assertStringNotContainsString('203.0.113.9', $evenement->contenu);
        $this->assertStringNotContainsString('Claire', $evenement->contenu);

        $this->repondre('get', $jeton)->assertStatus(410)->assertSee(LienDuDevis::PLUS_VALABLE);
        $this->repondre('accepter', $jeton, ['signataire' => 'Autre Personne', 'bon_pour_accord' => '1'])->assertStatus(410);
        $this->repondre('refuser', $jeton)->assertStatus(410);
        $this->assertSame(1, ReponseDuDevis::query()->count());
        $this->assertSame(StatutDevis::Accepte, $devis->fresh()->statut);
    }

    public function test_l_acceptation_confirme_au_client_avec_le_devis_et_previent_le_demandeur_et_le_proprietaire(): void
    {
        Notification::fake();
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis, 'paul@client.fr');

        $this->repondre('accepter', $jeton, ['signataire' => 'Paul Durand', 'bon_pour_accord' => '1'])->assertOk();

        $confirmation = EnvoiDocument::query()->where('nature', NatureEnvoi::Acceptation)->sole();
        $this->assertSame(['paul@client.fr', null, "Acceptation enregistrée : Devis {$devis->numero} — Ma Boutique"], [$confirmation->destinataire, $confirmation->envoye_par, $confirmation->objet]);
        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($devis, $confirmation): bool {
            $html = $mail->render();

            return $mail->envoi->is($confirmation)
                && $mail->hasTo('paul@client.fr')
                && str_contains($html, "Nous avons bien reçu votre acceptation de notre devis {$devis->numero}")
                && ! str_contains($html, 'Répondre au devis')
                && $mail->attachments()[0]->as === "devis-{$devis->numero}.pdf";
        });
        $demandeur = self::responsableDeTest($this->magasin);
        $proprietaire = $this->proprietaireDe($this->magasin);
        Notification::assertSentTo([$demandeur, $proprietaire], ReponseDuClientAuDevis::class, function (ReponseDuClientAuDevis $avis, array $canaux) use ($devis): bool {
            return $canaux === ['database', 'mail'] && $avis->devis->is($devis) && $avis->stocksInsuffisants === [];
        });
        Notification::assertCount(2);
    }

    public function test_l_avis_d_une_acceptation_dit_quels_produits_n_ont_plus_assez_de_stock(): void
    {
        Notification::fake();
        $produit = Produit::factory()->create(['nom' => 'Lampe de bureau', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'stock_quantite' => 2]);
        $devis = $this->devisEnvoyable(lignes: [['produit_id' => $produit->id, 'quantite' => 4]]);
        $jeton = $this->envoyer($devis);

        $this->repondre('accepter', $jeton, ['signataire' => 'Paul Durand', 'bon_pour_accord' => '1'])->assertOk();

        $proprietaire = $this->proprietaireDe($this->magasin);
        Notification::assertSentTo($proprietaire, ReponseDuClientAuDevis::class, function (ReponseDuClientAuDevis $avis) use ($proprietaire): bool {
            $courriel = implode(' ', $avis->toMail($proprietaire)->introLines);

            return $avis->stocksInsuffisants === [['produit' => 'Lampe de bureau', 'demande' => 4, 'disponible' => 2]]
                && str_contains($courriel, '1 produit n\'a plus assez de stock')
                && str_contains($courriel, '« Lampe de bureau » : 4 demandés, 2 en stock.');
        });
    }

    public function test_accepter_exige_le_nom_tape_et_bon_pour_accord(): void
    {
        $jeton = $this->envoyer($this->devisEnvoyable());

        $this->repondre('accepter', $jeton, ['signataire' => 'Claire Martin'])->assertStatus(422)->assertSee('Cochez « Bon pour accord » pour accepter le devis.')->assertSee('value="Claire Martin"', false);
        $this->repondre('accepter', $jeton, ['signataire' => ' ', 'bon_pour_accord' => '1'])->assertStatus(422)->assertSee('Tapez votre nom et votre prénom pour accepter le devis.');
        $this->repondre('accepter', $jeton, ['signataire' => str_repeat('a', 151), 'bon_pour_accord' => '1'])->assertStatus(422)->assertSee('Votre nom ne doit pas dépasser 150 caractères.');

        $this->assertSame(0, ReponseDuDevis::query()->count());
        $this->assertSame(StatutDevis::Envoye, Devis::query()->sole()->statut);
        $this->repondre('get', $jeton)->assertOk();
    }

    public function test_refuser_enregistre_son_motif_facultatif_confirme_et_previent(): void
    {
        Notification::fake();
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis, 'paul@client.fr');

        $this->repondre('refuser', $jeton, ['motif' => 'Trop cher pour nous.'])->assertOk()->assertSee('Votre refus est enregistré');

        $enregistree = ReponseDuDevis::query()->sole();
        $this->assertSame([ReponseAuDevis::Refus, null, 'Trop cher pour nous.'], [$enregistree->reponse, $enregistree->signataire, $enregistree->motif]);
        $this->assertSame([StatutDevis::Refuse, null], [$devis->fresh()->statut, $devis->fresh()->accepte_le]);
        $this->assertSame(['devis' => $devis->numero, 'empreinte_du_devis' => $enregistree->empreinte_du_devis, 'motif' => 'Trop cher pour nous.'], EntreeAudit::query()->where('action', ActionAuditee::DevisRefuseEnLigne)->sole()->nouvelles_valeurs);
        $this->assertSame('paul@client.fr', EnvoiDocument::query()->where('nature', NatureEnvoi::Refus)->sole()->destinataire);
        Notification::assertSentTo($this->proprietaireDe($this->magasin), ReponseDuClientAuDevis::class, fn (ReponseDuClientAuDevis $avis): bool => str_contains(implode(' ', $avis->toMail($this->proprietaireDe($this->magasin))->introLines), 'avec ce motif : « Trop cher pour nous. »'));
        $this->repondre('get', $jeton)->assertStatus(410);
    }

    public function test_un_refus_sans_motif_est_enregistre(): void
    {
        $jeton = $this->envoyer($this->devisEnvoyable());

        $this->repondre('refuser', $jeton, ['motif' => '   '])->assertOk();

        $this->assertNull(ReponseDuDevis::query()->sole()->motif);
    }

    public function test_le_lien_vaut_jusqu_au_dernier_jour_de_la_validite_du_devis(): void
    {
        $jeton = $this->envoyer($this->devisEnvoyable(['date_validite' => '2026-11-20']));

        $this->travelTo(self::aParis('2026-11-20 23:59:00'));
        $this->repondre('get', $jeton)->assertOk();
        $this->travelTo(self::aParis('2026-11-21 00:00:00'));
        $this->repondre('get', $jeton)->assertStatus(410)->assertSee(LienDuDevis::PLUS_VALABLE);
        $this->repondre('accepter', $jeton, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'])->assertStatus(410);

        $this->assertSame(0, ReponseDuDevis::query()->count());
    }

    public function test_un_devis_modifie_rend_son_lien_caduc_meme_renvoye_sans_e_mail(): void
    {
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis);

        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $devis->client, [['produit_id' => $devis->lignes->first()->produit_id, 'quantite' => 3]]);
        $this->repondre('get', $jeton)->assertStatus(410);

        $this->travelTo(self::aParis('2026-11-12 10:05:00'));
        app(EmettreDevisAction::class)->handle(self::auteur(), $modifie);
        $this->repondre('get', $jeton)->assertStatus(410);
        $this->repondre('accepter', $jeton, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'])->assertStatus(410);
    }

    public function test_un_nouvel_envoi_donne_un_nouveau_lien_et_rend_l_ancien_caduc(): void
    {
        $devis = $this->devisEnvoyable();
        $premier = $this->envoyer($devis);
        $second = $this->envoyer($devis->fresh());

        $this->assertNotSame($premier, $second);
        $this->repondre('get', $premier)->assertStatus(410);
        $this->repondre('get', $second)->assertOk();
        $this->repondre('accepter', $premier, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'])->assertStatus(410);
        $this->repondre('accepter', $second, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'])->assertOk();
    }

    public function test_le_magasin_retire_le_lien_et_le_journal_le_garde(): void
    {
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis);
        $auteur = self::responsableDeTest($this->magasin);

        app(RetirerLeLienDuDevisAction::class)->handle($auteur, $devis);

        $lien = LienDuDevis::query()->sole();
        $this->assertSame([$auteur->id, $auteur->name], [$lien->retire_par, $lien->retire_par_nom]);
        $this->assertNotNull($lien->retire_le);
        $this->assertSame(['devis' => $devis->numero], EntreeAudit::query()->where('action', ActionAuditee::LienDuDevisRetire)->sole()->nouvelles_valeurs);
        $this->repondre('get', $jeton)->assertStatus(410);
        $this->assertSame(StatutDevis::Envoye, $devis->fresh()->statut);
        $this->assertThrows(fn () => app(RetirerLeLienDuDevisAction::class)->handle($auteur, $devis), LogicException::class, RetirerLeLienDuDevisAction::REFUS_SANS_LIEN);
    }

    public function test_une_reponse_du_magasin_ou_une_transformation_rend_le_lien_caduc(): void
    {
        $devis = $this->devisEnvoyable();
        $jeton = $this->envoyer($devis);

        $accepte = app(AccepterDevisAction::class)->handle(self::auteur(), $devis);
        $this->repondre('get', $jeton)->assertStatus(410);
        app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $accepte);
        $this->repondre('get', $jeton)->assertStatus(410);
        $this->repondre('refuser', $jeton)->assertStatus(410);

        $this->assertSame(StatutDevis::Converti, $devis->fresh()->statut);
        $this->assertSame(0, ReponseDuDevis::query()->count());
    }

    public function test_un_devis_deja_accepte_se_renvoie_sans_lien(): void
    {
        $devis = $this->devisEnvoyable();
        $this->envoyer($devis);
        $accepte = app(AccepterDevisAction::class)->handle(self::auteur(), $devis);

        app(EnvoyerDocumentAction::class)->handle(self::auteur(), $accepte, 'client@exemple.fr', null, (string) Str::uuid());

        $this->assertSame(1, LienDuDevis::query()->count());
        $dernier = EnvoiDocument::query()->latest('id')->firstOrFail();
        Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail): bool => $mail->envoi->is($dernier) && ! str_contains($mail->render(), 'Répondre au devis'));
    }

    public function test_un_magasin_en_lecture_seule_ne_recoit_plus_de_reponse(): void
    {
        $jeton = $this->envoyer($this->devisEnvoyable());
        $this->magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();

        $this->repondre('get', $jeton)->assertStatus(410);
        $this->repondre('accepter', $jeton, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'])->assertStatus(410);

        $this->assertSame(0, ReponseDuDevis::query()->count());
    }

    public function test_les_essais_sont_limites_par_adresse_ip(): void
    {
        $jeton = $this->envoyer($this->devisEnvoyable());

        for ($essai = 1; $essai <= 30; $essai++) {
            $this->repondre('get', Str::random(64), [], ['REMOTE_ADDR' => '198.51.100.7'])->assertStatus(410);
        }

        $this->repondre('get', $jeton, [], ['REMOTE_ADDR' => '198.51.100.7'])->assertStatus(429);
        $this->repondre('get', $jeton, [], ['REMOTE_ADDR' => '198.51.100.8'])->assertOk();

        for ($essai = 1; $essai <= 10; $essai++) {
            $this->repondre('accepter', Str::random(64), ['signataire' => 'X Y', 'bon_pour_accord' => '1'], ['REMOTE_ADDR' => '198.51.100.9'])->assertStatus(410);
        }

        $this->repondre('accepter', $jeton, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'], ['REMOTE_ADDR' => '198.51.100.9'])->assertStatus(429);
        $this->assertSame(0, ReponseDuDevis::query()->count());
    }

    public function test_le_lien_d_un_magasin_ne_montre_ni_ne_touche_rien_d_un_autre(): void
    {
        $devisA = $this->devisEnvoyable(client: Client::factory()->create(['nom' => 'Client de la boutique A']));
        $jetonA = $this->envoyer($devisA);
        $magasinB = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Autre Boutique', 'code' => 'AUTRE']));
        app(TenantContext::class)->set($magasinB);
        $devisB = $this->devisEnvoyable(client: Client::factory()->create(['nom' => 'Client de la boutique B']));
        $jetonB = $this->envoyer($devisB);

        $this->repondre('get', $jetonA)->assertOk()->assertSee('Ma Boutique')->assertSee('Client de la boutique A')->assertDontSee('Autre Boutique')->assertDontSee('Client de la boutique B')->assertDontSee($devisB->numero);
        $this->repondre('accepter', $jetonA, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'])->assertOk();

        $this->assertSame(StatutDevis::Accepte, Devis::withoutGlobalScopes()->findOrFail($devisA->id)->statut);
        $this->assertSame(StatutDevis::Envoye, Devis::withoutGlobalScopes()->findOrFail($devisB->id)->statut);
        $reponse = ReponseDuDevis::withoutGlobalScopes()->sole();
        $this->assertSame([(int) $this->magasin->id, $devisA->id], [(int) $reponse->magasin_id, $reponse->devis_id]);
        $this->assertSame((int) $this->magasin->id, (int) EntreeAudit::query()->withoutGlobalScopes()->where('action', ActionAuditee::DevisAccepteEnLigne)->sole()->magasin_id);
        $this->repondre('get', $jetonB)->assertOk()->assertSee('Autre Boutique')->assertDontSee('Client de la boutique A');
    }

    /**
     * Un devis de 144,00 € TTC (une ligne de 120,00 € TTC à 20 % et
     * une de 24,00 €), envoyable.
     *
     * @param  array<string, mixed>  $attributs
     * @param  list<array<string, mixed>>|null  $lignes
     */
    private function devisEnvoyable(array $attributs = [], ?array $lignes = null, ?Client $client = null): Devis
    {
        $lignes ??= [
            ['produit_id' => Produit::factory()->create(['nom' => 'Câble tressé', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
            ['produit_id' => Produit::factory()->create(['nom' => 'Prise murale', 'prix_vente_ttc' => 24, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
        ];

        return app(CreerDevisAction::class)->handle(self::auteur(), $client ?? Client::factory()->create(), $lignes, $attributs);
    }

    /**
     * Envoie le devis par e-mail, comme le magasin (la tâche d'envoi tourne
     * aussitôt, QUEUE_CONNECTION=sync), et rend le jeton que porte le lien
     * de l'e-mail parti.
     */
    private function envoyer(Devis $devis, string $adresse = 'client@exemple.fr'): string
    {
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::responsableDeTest(app(TenantContext::class)->magasin()), $devis, $adresse, null, (string) Str::uuid());
        $jeton = null;

        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($envoi, &$jeton): bool {
            if (! $mail->envoi->is($envoi) || preg_match('#/devis/([A-Za-z0-9]{64})"#', $mail->render(), $trouve) !== 1) {
                return false;
            }

            $jeton = $trouve[1];

            return true;
        });
        $devis->refresh();

        return (string) $jeton;
    }

    /**
     * Un geste du client sur la page de son lien, hors de tout magasin
     * ouvert, comme un visiteur sans compte.
     *
     * @param  array<string, string>  $donnees
     * @param  array<string, string>  $serveur
     */
    private function repondre(string $geste, string $jeton, array $donnees = [], array $serveur = []): TestResponse
    {
        $contexte = app(TenantContext::class);
        $avant = $contexte->has() ? $contexte->magasin() : null;
        $contexte->clear();

        try {
            return match ($geste) {
                'get' => $this->withServerVariables($serveur)->get(route('devis.reponse.afficher', ['jeton' => $jeton])),
                'pdf' => $this->withServerVariables($serveur)->get(route('devis.reponse.pdf', ['jeton' => $jeton])),
                'accepter' => $this->withServerVariables($serveur)->post(route('devis.reponse.accepter', ['jeton' => $jeton]), $donnees),
                'refuser' => $this->withServerVariables($serveur)->post(route('devis.reponse.refuser', ['jeton' => $jeton]), $donnees),
            };
        } finally {
            if ($avant !== null) {
                $contexte->set($avant);
            }
        }
    }
}
