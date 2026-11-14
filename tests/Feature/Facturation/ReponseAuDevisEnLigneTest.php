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
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TraceAudit;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\DevisResource\Pages\ViewDevis;
use App\Http\Controllers\ReponseAuDevisController;
use App\Models\User;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
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

    public function test_la_page_du_lien_ecrit_une_remise_decimale_avec_une_virgule(): void
    {
        $produit = Produit::factory()->create(['nom' => 'Câble tressé', 'prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $jeton = $this->envoyer($this->devisEnvoyable(lignes: [['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => '12.5']]));

        $this->repondre('get', $jeton)->assertOk()->assertSee('12,5 %')->assertDontSee('12.5 %');
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

        $this->assertSame(0, ReponseDuDevis::query()->count());
    }

    /**
     * Une adresse tronquée ou altérée (un jeton trop court, trop long, ou
     * qui porte d'autres caractères) mène à la même page neutre, sans
     * lien vers le reste de PILIQI, sans que la base soit lue pour la
     * chercher, sous la même limite d'essais.
     */
    public function test_un_jeton_mal_forme_ne_dit_que_ce_lien_n_est_plus_valable_sans_rien_lire(): void
    {
        $jeton = $this->envoyer($this->devisEnvoyable());
        DB::flushQueryLog();
        DB::enableQueryLog();

        foreach ([substr($jeton, 0, 63), $jeton.'A', substr($jeton, 0, 63).'-', '<script>', 'x'] as $altere) {
            foreach (['get', 'pdf', 'accepter', 'refuser'] as $geste) {
                $reponse = $this->repondre($geste, $altere, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1']);

                $reponse->assertStatus(410)
                    ->assertSee(LienDuDevis::PLUS_VALABLE)
                    ->assertDontSee('Ma Boutique')
                    ->assertDontSee('Revenir à l\'accueil', false)
                    ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                    ->assertHeader('Referrer-Policy', 'no-referrer');
                $this->assertSame(0, preg_match_all('/href="/', (string) $reponse->getContent()));
            }
        }

        $this->assertFalse(collect(DB::getQueryLog())->contains(fn (array $requete): bool => str_contains($requete['query'], 'liens_des_devis')));
        DB::disableQueryLog();
        $this->assertSame(0, ReponseDuDevis::query()->count());
        $this->repondre('get', $jeton)->assertOk();
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
            $courriel = self::texteDuCourriel($avis->toMail($proprietaire));

            return $avis->stocksInsuffisants === [['produit' => 'Lampe de bureau', 'demande' => 4, 'disponible' => 2]]
                && str_contains($courriel, '1 produit n\'a plus assez de stock')
                && str_contains($courriel, '« Lampe de bureau » : 4 demandés, 2 en stock.');
        });
    }

    /**
     * Le nom et le motif viennent d'une page sans compte : dans la cloche,
     * dont Filament interprète le corps en HTML, ils se lisent tels qu'ils
     * ont été tapés, sans jamais devenir un lien ni une mise en forme, chez
     * le demandeur de l'envoi comme chez le propriétaire.
     */
    public function test_le_nom_et_le_motif_du_client_se_lisent_en_texte_dans_la_cloche(): void
    {
        $piege = '<a href="https://evil.example/connexion" style="position:fixed;top:0;left:0;width:100vw;height:100vh">Votre session a expiré</a>';
        $refuse = $this->devisEnvoyable();
        $this->repondre('refuser', $this->envoyer($refuse), ['motif' => $piege])->assertOk();
        $accepte = $this->devisEnvoyable();
        $this->repondre('accepter', $this->envoyer($accepte), ['signataire' => '<a href="https://evil.example">Paul</a>', 'bon_pour_accord' => '1'])->assertOk();

        foreach ([$this->proprietaireDe($this->magasin), self::responsableDeTest($this->magasin)] as $compte) {
            $avis = $compte->notifications()->where('type', ReponseDuClientAuDevis::class)->get();
            $this->assertCount(2, $avis);

            // Le corps tel que la cloche l'écrit dans la page (voir la vue
            // filament-notifications::notification) : passé au filtre de
            // Filament, qui garde les liens.
            $corps = $avis->map(fn ($notification): string => (string) str(NotificationFilament::fromDatabase($notification)->getBody())->sanitizeHtml());
            $this->assertDoesNotMatchRegularExpression('#<a\b#i', $corps->implode(' '));

            $textes = $corps->map(fn (string $html): string => html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5))->implode(' ');
            $this->assertStringContainsString("avec ce motif : « {$piege} »", $textes);
            $this->assertStringContainsString('signé « <a href="https://evil.example">Paul</a> »', $textes);
        }
    }

    /**
     * Dans l'e-mail, que Laravel écrit en Markdown, le nom et le motif tapés
     * par le client se lisent aussi tels quels, sur une seule ligne : ni
     * gras, ni lien, ni titre, dans la version HTML comme dans la version
     * en texte.
     */
    public function test_le_nom_et_le_motif_du_client_se_lisent_en_texte_dans_l_e_mail(): void
    {
        Notification::fake();
        $motif = "**Urgent** : [payez ici](https://evil.example/payer)\n# Votre compte est bloqué";
        $motifSurUneLigne = '**Urgent** : [payez ici](https://evil.example/payer) # Votre compte est bloqué';
        $this->repondre('refuser', $this->envoyer($this->devisEnvoyable()), ['motif' => $motif])->assertOk();
        $this->repondre('accepter', $this->envoyer($this->devisEnvoyable()), ['signataire' => '_Paul_ `Durand`', 'bon_pour_accord' => '1'])->assertOk();

        $proprietaire = $this->proprietaireDe($this->magasin);
        $courriels = Notification::sent($proprietaire, ReponseDuClientAuDevis::class)->map(fn (ReponseDuClientAuDevis $avis): MailMessage => $avis->toMail($proprietaire));
        $this->assertCount(2, $courriels);
        $html = $courriels->map(fn (MailMessage $courriel): string => (string) $courriel->render())->implode(' ');

        $this->assertDoesNotMatchRegularExpression('#<(strong|em|code)\b#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*evil\.example#i', $html);
        $this->assertDoesNotMatchRegularExpression('#<h1\b[^>]*>\s*Votre compte#i', $html);
        $textes = $courriels->map(fn (MailMessage $courriel): string => self::texteDuCourriel($courriel))->implode(' ');
        $this->assertStringContainsString("avec ce motif : « {$motifSurUneLigne} »", $textes);
        $this->assertStringContainsString('signé « _Paul_ `Durand` »', $textes);
        $this->assertStringContainsString("avec ce motif : « {$motifSurUneLigne} »", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
    }

    /**
     * Un motif tapé sur plusieurs lignes, une ligne vide suivie d'un
     * retrait, ferait en Markdown un bloc de code : sa fin sortirait des
     * « », écrite comme si PILIQI l'avait rédigée, et sa ponctuation se
     * lirait en références (« &#46; »). L'e-mail le donne à lire sur une
     * seule ligne, entre les « », tel qu'il a été tapé ; le motif
     * enregistré garde ses lignes.
     */
    public function test_un_motif_sur_plusieurs_lignes_se_lit_sur_une_seule_ligne_dans_l_e_mail(): void
    {
        Notification::fake();
        $motif = "Trop cher.\n\n    Votre compte est bloqué : rappelez le 01-23-45-67-89.\n\t- Merci.";
        $this->repondre('refuser', $this->envoyer($this->devisEnvoyable()), ['motif' => $motif])->assertOk();
        $this->assertSame($motif, ReponseDuDevis::query()->sole()->motif);

        $proprietaire = $this->proprietaireDe($this->magasin);
        $courriel = Notification::sent($proprietaire, ReponseDuClientAuDevis::class)->sole()->toMail($proprietaire);
        $html = (string) $courriel->render();
        $lu = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        $this->assertDoesNotMatchRegularExpression('#<(pre|code)\b#i', $html);
        $this->assertStringContainsString('avec ce motif : « Trop cher. Votre compte est bloqué : rappelez le 01-23-45-67-89. - Merci. ».', $lu);
        $this->assertStringNotContainsString('&#', $lu);
        $this->assertStringContainsString('avec ce motif : « Trop cher. Votre compte est bloqué : rappelez le 01-23-45-67-89. - Merci. ».', self::texteDuCourriel($courriel));
    }

    /**
     * La cloche et la boîte du propriétaire réunissent les avis de tous ses
     * magasins : l'heure d'un magasin d'outre-mer y porte le nom de son
     * heure, comme sur la page du client.
     */
    public function test_l_avis_nomme_l_heure_d_un_magasin_d_outre_mer(): void
    {
        Notification::fake();
        $this->magasin = $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee(Territoire::Martinique, ['nom' => 'Boutique de Fort-de-France', 'code' => 'FDF1']));
        app(TenantContext::class)->set($this->magasin);
        $jeton = $this->envoyer($this->devisEnvoyable());
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-12 06:00'));

        $this->repondre('accepter', $jeton, ['signataire' => 'Paul Durand', 'bon_pour_accord' => '1'])->assertOk()->assertSee('12/11/2026 à 06:00 (heure de la Martinique)');

        $proprietaire = $this->proprietaireDe($this->magasin);
        Notification::assertSentTo($proprietaire, ReponseDuClientAuDevis::class, function (ReponseDuClientAuDevis $avis) use ($proprietaire): bool {
            $courriel = self::texteDuCourriel($avis->toMail($proprietaire));
            $cloche = $avis->toDatabase($proprietaire)['body'];

            return str_contains($courriel, 'le 12/11/2026 à 06:00 (heure de la Martinique)')
                && str_contains(html_entity_decode((string) $cloche, ENT_QUOTES | ENT_HTML5), 'le 12/11/2026 à 06:00 (heure de la Martinique)');
        });
    }

    /**
     * Le client n'a pas de compte : sa réponse s'inscrit au journal des
     * événements au nom du système, quel que soit le compte connecté dans
     * le navigateur qui ouvre le lien, d'une autre société ou du magasin
     * du devis.
     */
    public function test_la_reponse_s_inscrit_au_journal_des_evenements_sans_le_compte_connecte_dans_le_navigateur(): void
    {
        $jetons = [$this->envoyer($this->devisEnvoyable()), $this->envoyer($this->devisEnvoyable())];
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Autre Boutique', 'code' => 'AUTRE']);
        $connectes = [self::responsableDeTest($ailleurs)];
        app(TenantContext::class)->set($this->magasin);
        $connectes[] = $this->membre($this->magasin, RoleMagasin::Vendeur);

        foreach ($connectes as $rang => $compte) {
            $this->actingAs($compte);

            $this->repondre('accepter', $jetons[$rang], ['signataire' => 'Paul Durand', 'bon_pour_accord' => '1'])->assertOk();
        }

        $evenements = EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement)->where('type', (string) CodeEvenement::ReponseAUnDevis->value)->get();
        $this->assertCount(2, $evenements);

        foreach ($evenements as $evenement) {
            $this->assertNull($evenement->operateur_id);
            $this->assertNull(json_decode($evenement->contenu, true)['operateur']);
            $this->assertSame((int) $this->magasin->id, (int) $evenement->magasin_id);
        }

        $this->assertTrue(collect($connectes)->every(fn (User $compte): bool => ! str_contains($evenements->pluck('contenu')->implode(' '), $compte->name)));
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
        Notification::assertSentTo($this->proprietaireDe($this->magasin), ReponseDuClientAuDevis::class, fn (ReponseDuClientAuDevis $avis): bool => str_contains(self::texteDuCourriel($avis->toMail($this->proprietaireDe($this->magasin))), 'avec ce motif : « Trop cher pour nous. »'));
        $this->repondre('get', $jeton)->assertStatus(410);
    }

    /**
     * La confirmation de la réponse en ligne part sans que personne du
     * magasin l'ait demandée : la section « Envois » de la fiche du devis
     * dit qu'elle vient de la réponse du client, jamais d'une relance.
     */
    public function test_la_fiche_dit_que_la_confirmation_vient_de_la_reponse_du_client(): void
    {
        $devis = $this->devisEnvoyable();
        $this->repondre('refuser', $this->envoyer($devis))->assertOk();
        $this->dansLeMagasin($this->magasin, self::responsableDeTest($this->magasin));

        Livewire::test(ViewDevis::class, ['record' => $devis->getRouteKey()])
            ->assertSee('Confirmation du refus')
            ->assertSee('Le client, par sa réponse en ligne')
            ->assertSee('Responsable de test')
            ->assertDontSee('Relance automatique');
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

        $this->repondre('get', $jeton, [], ['REMOTE_ADDR' => '198.51.100.7'])
            ->assertStatus(429)
            ->assertSee(ReponseAuDevisController::TROP_D_ESSAIS)
            ->assertDontSee('Too Many')
            ->assertDontSee('Ma Boutique')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Retry-After');
        $this->repondre('pdf', $jeton, [], ['REMOTE_ADDR' => '198.51.100.7'])->assertStatus(429);
        $this->repondre('get', $jeton, [], ['REMOTE_ADDR' => '198.51.100.8'])->assertOk();

        for ($essai = 1; $essai <= 10; $essai++) {
            $this->repondre('accepter', Str::random(64), ['signataire' => 'X Y', 'bon_pour_accord' => '1'], ['REMOTE_ADDR' => '198.51.100.9'])->assertStatus(410);
        }

        $this->repondre('accepter', $jeton, ['signataire' => 'Claire Martin', 'bon_pour_accord' => '1'], ['REMOTE_ADDR' => '198.51.100.9'])
            ->assertStatus(429)
            ->assertSee(ReponseAuDevisController::TROP_D_ESSAIS)
            ->assertHeader('Referrer-Policy', 'no-referrer');
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

    /**
     * Le texte des lignes d'un e-mail de notification, tel que la version en
     * texte de l'e-mail le donne : Laravel en décode les références (voir
     * LigneDeCourriel).
     */
    private static function texteDuCourriel(MailMessage $courriel): string
    {
        return html_entity_decode(implode(' ', array_map(strval(...), $courriel->introLines)), ENT_QUOTES | ENT_HTML5);
    }
}
