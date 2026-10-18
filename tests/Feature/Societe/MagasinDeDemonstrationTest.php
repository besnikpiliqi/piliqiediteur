<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\SequenceDocument;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Actions\CreerMandatPdpAction;
use App\Domain\Tenancy\Actions\FermerLaDemonstrationAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RefuserLaSocieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Pages\Caisse;
use App\Filament\Societe\Pages\TableauDeBord;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Le magasin de démonstration d'une société en cours de vérification
 * (voir OuvrirLaDemonstrationAction et FermerLaDemonstrationAction) : un
 * mode école au sens de la caisse sécurisée, magasin distinct avec son
 * registre, ses séries et ses clôtures, à l'identité fictive, qui n'écrit
 * à aucun client, ne transmet rien et ne touche jamais un magasin réel ;
 * il se ferme à la validation de la société.
 */
class MagasinDeDemonstrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_ouvre_une_seule_demonstration_avant_la_validation(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire;

        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        $deNouveau = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);

        $this->assertSame($demonstration->id, $deNouveau->id);
        $this->assertSame(
            [true, 'Démonstration', 'DEMO', null, StatutMagasin::Actif],
            [$demonstration->est_demonstration, $demonstration->nom, $demonstration->code, $demonstration->siret, $demonstration->statut],
        );
        $this->assertSame(1, $societe->magasins()->where('est_demonstration', true)->count());
        app(TenantContext::class)->runAs($demonstration, function (): void {
            $this->assertSame(['DEMO-CARNET' => 20, 'DEMO-STYLO' => 50, 'DEMO-TASSE' => 10], Produit::query()->orderBy('reference')->pluck('stock_quantite', 'reference')->all());
            $this->assertSame(2, Service::query()->count());
            $this->assertSame(3, Client::query()->count());
        });
        $this->assertThrows(
            $this->dansUnPointDeSauvegarde(fn () => Magasin::forceCreate(['societe_id' => $societe->id, 'nom' => 'Seconde', 'code' => 'DEMO2', 'est_demonstration' => true, 'siret' => null])),
            QueryException::class,
        );
        $vendeur = $this->membre($reel, RoleMagasin::Vendeur);
        $this->assertThrows(fn () => app(OuvrirLaDemonstrationAction::class)->handle($vendeur, $societe), VerificationRefuseeException::class, OuvrirLaDemonstrationAction::MESSAGE_SANS_AUTORISATION);
    }

    /**
     * Une société qui n'a encore aucun magasin réel s'essaie dans sa
     * démonstration comme une autre : son propriétaire l'ouvre, son équipe
     * y vend, y encaisse et y clôture sa journée, et aucun magasin réel
     * n'apparaît.
     */
    public function test_la_demonstration_fonctionne_sans_aucun_magasin_reel(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);

        $ticket = $this->vendre();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $demonstration, now());

        $this->assertSame(['payee', 1], [$ticket->fresh()->statut->value, ClotureCaisse::query()->count()]);
        $this->assertSame(0, $societe->magasinsReels()->count());
        $this->assertSame([$demonstration->id], $societe->proprietaire->magasinsAccessibles()->modelKeys());
    }

    /**
     * Le propriétaire ne vend pas, pas même dans le magasin de
     * démonstration : il n'y a ni caisse ni autorisation de vente, l'action
     * le refuse et rien ne s'écrit ; la fenêtre qui ouvre la démonstration
     * le lui dit et l'invite à y créer un compte pour essayer la caisse,
     * avec lequel la vente passe.
     */
    public function test_le_proprietaire_ne_vend_pas_dans_la_demonstration_et_la_fenetre_le_dit(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire;
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);

        Livewire::test(TableauDeBord::class)
            ->mountAction('ouvrirLaDemonstration')
            ->assertSee('votre équipe y vend, encaisse et clôture comme pour de vrai ; vous-même ne vendez pas : pour essayer la caisse, créez-y un compte de responsable ou de vendeur.')
            ->callMountedAction();

        $demonstration = $societe->demonstration()->sole();
        $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), $proprietaire->autorisationsDans($demonstration))));
        $this->assertFalse($proprietaire->tientUneCaisseDans($demonstration));
        $this->dansLeMagasin($demonstration, $proprietaire);
        $this->get(Caisse::getUrl(tenant: $demonstration))->assertForbidden();
        $this->assertThrows(fn () => $this->vendre(), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, Facture::query()->count());

        $this->actingAs($this->responsableDeTest($demonstration));

        $this->assertMatchesRegularExpression('/^TK-DEMO-/', $this->vendre()->numero);
    }

    public function test_l_ouverture_et_la_fin_de_la_demonstration_s_inscrivent_en_jet_100_et_105(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);

        $this->verifierLIdentiteDuProprietaire($societe);

        $this->assertSame(['260', '100', '130', '105', '270'], $this->codesDuJournal($demonstration));
        $statut = app(TenantContext::class)->runAs($demonstration, fn () => EnregistrementFiscal::query()->where('type', '270')->sole()->contenuSigne()['donnees']['details']);
        $this->assertSame(['parametre' => 'statut_magasin', 'magasin' => ['id' => $demonstration->id, 'nom' => 'Démonstration'], 'ancienne_valeur' => 'actif', 'nouvelle_valeur' => 'ferme'], $statut);
        $this->assertNotContains('100', $this->codesDuJournal($reel));
        $this->assertNotContains('105', $this->codesDuJournal($reel));
        $this->assertSame(
            [ActionAuditee::DemonstrationOuverte, ActionAuditee::DemonstrationFermee],
            app(TenantContext::class)->runAsSociete($societe, fn () => EntreeAudit::query()->where('magasin_id', $demonstration->id)->orderBy('id')->pluck('action')->all()),
        );
    }

    public function test_la_demonstration_vend_encaisse_et_cloture_dans_son_propre_registre(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);

        $ticket = $this->vendre();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $demonstration, now());

        $this->assertSame(['payee', 1, 1], [$ticket->fresh()->statut->value, ClotureCaisse::query()->count(), Facture::query()->count()]);
        $chainesDeLaDemonstration = EnregistrementFiscal::query()->pluck('chaine')->map->value->unique()->sort()->values()->all();
        $this->assertSame(['encaissement', 'evenement', 'vente'], $chainesDeLaDemonstration);
        $this->assertSame(
            0,
            EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $reel->id)->where('chaine', '!=', ChaineFiscale::Evenement->value)->count(),
        );
    }

    /**
     * Point 21 : la clôture de la journée de démonstration, faite par le
     * système à sa fermeture, s'inscrit (JET 50) dans le journal de ce
     * magasin distinct, jamais dans celui d'un magasin réel.
     */
    public function test_la_cloture_du_magasin_de_demonstration_s_inscrit_dans_son_propre_journal(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $this->vendre();

        $this->verifierLIdentiteDuProprietaire($societe);

        $evenement = EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)
            ->where('magasin_id', $demonstration->id)
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', (string) CodeEvenement::CloturePeriode->value)
            ->sole();
        $this->assertSame(['DEMO', 1, null], [
            explode('-', $evenement->contenuSigne()['donnees']['details']['numero'])[1],
            $evenement->contenuSigne()['donnees']['details']['nombre_ventes'],
            $evenement->contenuSigne()['operateur'],
        ]);
        $this->assertNotContains((string) CodeEvenement::CloturePeriode->value, $this->codesDuJournal($reel));
    }

    /**
     * Les services du catalogue fictif ont un prix de référence : un
     * vendeur, qui ne fixe aucun prix, les voit à la caisse et les vend.
     */
    public function test_un_vendeur_de_la_demonstration_vend_ses_services_a_leur_prix_de_reference(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $vendeur = $this->membre($demonstration, RoleMagasin::Vendeur);
        $this->actingAs($vendeur);
        Filament::setTenant($demonstration);

        Livewire::test(Caisse::class)
            ->mountFormComponentAction('ajouterUnServiceAction', 'ajouterUnService')
            ->assertSee('Emballage cadeau')
            ->assertSee('Livraison à domicile');
        $ticket = self::vendreAuComptant($vendeur,
            Client::query()->where('est_comptoir', true)->sole(),
            Service::query()->orderBy('nom')->pluck('id')->map(fn (int $service): array => ['service_id' => $service, 'quantite' => 1])->all(),
            ModePaiement::Especes,
        );

        $this->assertSame([['Emballage cadeau', '3.00', '20.00'], ['Livraison à domicile', '5.90', '20.00']], $ticket->lignes->sortBy('ordre')->map(fn ($ligne): array => [$ligne->description, $ligne->prix_unitaire_ttc, $ligne->taux_tva])->values()->all());
        $this->assertSame(['8.90', 'payee'], [$ticket->total_ttc, $ticket->statut->value]);
    }

    public function test_ses_numeros_portent_le_code_demo_et_ne_touchent_aucune_serie_reelle(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $this->ouvrirLaDemonstrationEtYEntrer($societe);

        $ticket = $this->vendre();

        $this->assertMatchesRegularExpression('/^TK-DEMO-\d{4}-000001$/', $ticket->numero);
        $this->assertFalse(SequenceDocument::query()->where('magasin_id', $reel->id)->exists());
    }

    public function test_aucun_e_mail_ni_aucune_transmission_ne_part_de_la_demonstration(): void
    {
        Mail::fake();
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $client = Client::query()->where('type_client', 'professionnel')->sole();
        $client->update(['email' => 'client@example.test']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => Produit::query()->where('reference', 'DEMO-TASSE')->value('id'), 'quantite' => 1]]));
        $this->vendre();

        $this->assertThrows(fn () => app(EnvoyerDocumentAction::class)->handle($societe->proprietaire, $facture, 'client@example.test', null), VenteNonAutoriseeException::class, 'Le magasin de démonstration n\'écrit à aucun client et ne transmet rien.');
        $this->assertThrows(fn () => app(CreerMandatPdpAction::class)->handle($demonstration, $societe->proprietaire), LogicException::class, 'Le magasin de démonstration ne transmet rien à la plateforme.');
        $this->assertSame(0, EnvoiDocument::query()->count());
        Mail::assertNothingSent();

        $this->verifierLIdentiteDuProprietaire($societe);
        $apercu = app(TenantContext::class)->runAs($demonstration, fn () => app(EReportingDesParticuliers::class)->apercu(ClotureCaisse::query()->sole()));
        $this->assertSame([true, false], [$apercu->demonstration, $apercu->aDeclarer()]);
        $this->assertSame(0, app(TenantContext::class)->runAs($demonstration, fn (): int => app(EReportingDesParticuliers::class)->journeesADeclarer($demonstration)->count()));
    }

    public function test_ses_documents_portent_une_identite_fictive_et_le_filigrane(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $ticket = $this->vendre();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::query()->where('type_client', 'professionnel')->sole(), [['produit_id' => Produit::query()->where('reference', 'DEMO-STYLO')->value('id'), 'quantite' => 3]]);

        $pdfDuTicket = app(FacturePdfGenerator::class)->html($ticket);
        $pdfDuDevis = app(DevisPdfGenerator::class)->html($devis);

        $this->assertStringContainsString('TICKET DE DÉMONSTRATION — NE PAS REMETTRE À UN CLIENT', $pdfDuTicket);

        foreach ([$pdfDuTicket, $pdfDuDevis] as $pdf) {
            $this->assertStringContainsString('DÉMONSTRATION — SANS VALEUR', $pdf);
            $this->assertStringContainsString('ENTREPRISE DE DÉMONSTRATION', $pdf);
            $this->assertStringNotContainsString($societe->siren, $pdf);
            $this->assertStringNotContainsString((string) $reel->siret, $pdf);
            $this->assertStringNotContainsString((string) $societe->raison_sociale, $pdf);
        }
    }

    public function test_l_en_tete_signe_de_la_demonstration_n_a_ni_siren_ni_siret(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);

        $vente = $this->vendre()->enregistrementDeVente()->firstOrFail()->contenuSigne();

        $this->assertSame(['uuid' => $demonstration->uuid, 'siren' => null, 'siret' => null, 'demonstration' => true], $vente['magasin']);
        $emetteur = $vente['donnees']['contexte']['emetteur'];
        $this->assertSame(['ENTREPRISE DE DÉMONSTRATION', null, null, true], [$emetteur['raison_sociale'], $emetteur['siren'], $emetteur['siret'], $emetteur['demonstration']]);
    }

    /**
     * Le catalogue, les clients, les ventes et le stock de la démonstration
     * restent dans la démonstration : un magasin réel n'en voit rien, une
     * vente de démonstration ne verrouille pas le mois de fin d'exercice,
     * et les réglages de la société ne s'inscrivent pas au journal de la
     * démonstration.
     */
    public function test_rien_ne_passe_du_magasin_de_demonstration_aux_magasins_reels(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $this->vendre();

        app(TenantContext::class)->runAs($reel, function (): void {
            $this->assertSame([0, 0, 0], [Produit::query()->count(), Facture::query()->count(), Service::query()->count()]);
            $this->assertSame([Client::NOM_COMPTOIR], Client::query()->pluck('nom')->all());
        });
        $this->assertFalse($societe->fresh()->aVenduUneFois());

        app(TenantContext::class)->clear();
        app(ModifierParametresFiscauxSocieteAction::class)->handle($societe->proprietaire, $societe->fresh(), ['regime_tva' => 'reel_normal_mensuel']);

        $this->assertContains('270', $this->codesDuJournal($reel));
        $this->assertNotContains('270', $this->codesDuJournal($demonstration));
    }

    public function test_la_validation_ferme_la_demonstration_qui_reste_consultable(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $ticket = $this->vendre();

        $this->verifierLIdentiteDuProprietaire($societe);

        $fermee = $demonstration->fresh();
        $this->assertSame([StatutMagasin::Ferme, false], [$fermee->statut, $fermee->peutVendre()]);
        $periode = PeriodeActivite::query()->where('magasin_id', $demonstration->id)->sole();
        $this->assertSame(MotifFermeture::FinDemonstration, $periode->motif_fin);
        app(TenantContext::class)->set($fermee);
        $this->assertTrue($societe->proprietaire->can('viewAny', Facture::class));
        $this->assertStringContainsString('TICKET DE DÉMONSTRATION', app(FacturePdfGenerator::class)->html($ticket->fresh()));
        $this->assertThrows(fn () => app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh()), VerificationRefuseeException::class);
    }

    public function test_la_demonstration_ne_vend_plus_apres_la_validation_meme_si_sa_fermeture_a_echoue(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $this->mock(FermerLaDemonstrationAction::class, fn ($fermeture) => $fermeture->shouldReceive('handle')->andThrow(new RuntimeException('Registre indisponible.')));

        $this->verifierLIdentiteDuProprietaire($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame(StatutMagasin::Actif, $demonstration->fresh()->statut);
        app(TenantContext::class)->set($demonstration->fresh());
        $this->assertThrows(fn () => $this->vendre(), VenteNonAutoriseeException::class, 'Votre société est validée : la démonstration est terminée, vendez dans vos magasins.');

        $this->app->forgetInstance(FermerLaDemonstrationAction::class);
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();

        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
    }

    public function test_une_societe_refusee_ne_rouvre_pas_sa_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);

        app(RefuserLaSocieteAction::class)->handle($societe, new InterventionDeLEditeur('Pièces falsifiées, signalement du vrai dirigeant.', 'DOSSIER-42', ['alice']));

        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        $this->assertThrows(fn () => app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh()), VerificationRefuseeException::class, OuvrirLaDemonstrationAction::MESSAGE_SANS_AUTORISATION);
    }

    public function test_une_demonstration_inactive_30_jours_se_ferme_et_se_rouvre_avant_la_validation(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $this->vendre();

        $this->travel(29)->days();
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $this->assertSame(StatutMagasin::Actif, $demonstration->fresh()->statut);

        $this->travel(2)->days();
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);

        $rouverte = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());

        $this->assertSame([$demonstration->id, StatutMagasin::Actif], [$rouverte->id, $rouverte->statut]);
        $this->assertSame(2, PeriodeActivite::query()->where('magasin_id', $demonstration->id)->count());
        $this->assertSame(['105', '270', '270', '100'], array_slice($this->codesDuJournal($demonstration), -4));
        app(TenantContext::class)->runAs($demonstration, fn () => $this->assertSame(3, Produit::query()->count()));
    }

    public function test_le_verificateur_d_integrite_controle_aussi_la_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $this->vendre();
        app(TenantContext::class)->clear();

        $this->artisan('caisse:verifier', ['magasin' => $demonstration->uuid, '--complet' => true])->assertSuccessful();

        $controle = app(TenantContext::class)->runAs($demonstration, fn () => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::OperationDeControle->value)->sole()->contenuSigne()['donnees']['details']);
        $this->assertSame(['complete', 'conforme', 0], [$controle['verification'], $controle['resultat'], $controle['nombre_anomalies']]);
        $this->assertGreaterThan(0, $controle['enregistrements_verifies']['vente']);
    }

    public function test_aucun_pdf_d_un_magasin_reel_ne_se_telecharge_avant_la_validation(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        app(TenantContext::class)->set($reel);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::query()->sole(), [['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => 12, 'taux_tva' => 20, 'quantite' => 1]]);
        $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $ticket = $this->vendre();

        $this->assertThrows(fn () => app(FacturePdfGenerator::class)->html($brouillon), VenteNonAutoriseeException::class);
        $this->assertStringContainsString('DÉMONSTRATION', app(FacturePdfGenerator::class)->html($ticket));
    }

    /**
     * Ouvre la démonstration de la société par son propriétaire ; son
     * responsable de test, le compte que l'équipe y crée pour essayer la
     * caisse (le propriétaire ne vend pas), s'y connecte : elle devient le
     * magasin actif.
     */
    private function ouvrirLaDemonstrationEtYEntrer(Societe $societe): Magasin
    {
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        app(TenantContext::class)->set($demonstration);
        $this->actingAs($this->responsableDeTest($demonstration));

        return $demonstration;
    }

    /**
     * Un carnet vendu au comptoir de la démonstration par la personne
     * connectée, payé en espèces.
     */
    private function vendre(): Facture
    {
        return self::vendreAuComptant(self::auteur(),
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]],
            ModePaiement::Especes,
        );
    }

    /**
     * Les codes du journal des événements du magasin, dans l'ordre.
     *
     * @return list<string>
     */
    private function codesDuJournal(Magasin $magasin): array
    {
        return EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)
            ->where('magasin_id', $magasin->id)
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->orderBy('sequence')
            ->pluck('type')
            ->all();
    }
}
