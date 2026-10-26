<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Calendrier\Calendrier;
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
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\CreerMandatPdpAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Actions\FermerLaDemonstrationAction;
use App\Domain\Tenancy\Actions\ModifierParametresFiscauxSocieteAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Exceptions\VenteNonAutoriseeException;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            $this->dansUnPointDeSauvegarde(fn () => Magasin::forceCreate(['societe_id' => $societe->id, 'nom' => 'Seconde', 'code' => 'DEMO2', 'est_demonstration' => true, 'siret' => null, 'territoire' => Territoire::Metropole])),
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);

        $ticket = $this->vendre();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $demonstration, Calendrier::deParis()->aujourdhui());

        $this->assertSame(['payee', 1], [$ticket->fresh()->statut->value, ClotureCaisse::query()->count()]);
        $this->assertSame(0, $societe->magasinsReels()->count());
        $this->assertSame([$demonstration->id], $societe->proprietaire->magasinsAccessibles()->modelKeys());
    }

    /**
     * Le propriétaire ne vend pas, pas même dans le magasin de
     * démonstration : il n'y a ni caisse ni autorisation de vente, l'action
     * le refuse et rien ne s'écrit ; la fenêtre qui ouvre la démonstration
     * le lui dit et l'invite à y créer des comptes d'entraînement pour
     * essayer la caisse, avec lesquels la vente passe.
     */
    public function test_le_proprietaire_ne_vend_pas_dans_la_demonstration_et_la_fenetre_le_dit(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire;
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);

        Livewire::test(TableauDeBord::class)
            ->mountAction('ouvrirLaDemonstration')
            ->assertSee('votre équipe y vend, encaisse et clôture comme pour de vrai ; vous-même ne vendez pas : pour essayer la caisse, créez-y des comptes d\'entraînement : ils ne servent qu\'ici et sont retirés à la validation de votre société.')
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

        $this->saisirLeCodeDuCourrier($societe);

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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);

        $ticket = $this->vendre();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $demonstration, Calendrier::deParis()->aujourdhui());

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

        $this->saisirLeCodeDuCourrier($societe);

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

        $this->saisirLeCodeDuCourrier($societe);
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

        $this->saisirLeCodeDuCourrier($societe);

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

        $this->saisirLeCodeDuCourrier($societe);

        $this->assertTrue($societe->fresh()->estValidee());
        $this->assertSame(StatutMagasin::Actif, $demonstration->fresh()->statut);
        app(TenantContext::class)->set($demonstration->fresh());
        $this->assertThrows(fn () => $this->vendre(), VenteNonAutoriseeException::class, 'Votre société est validée : la démonstration est terminée, vendez dans vos magasins.');

        $this->app->forgetInstance(FermerLaDemonstrationAction::class);
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();

        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
    }

    /**
     * Une inscription close, sans code au bout de trente jours ou par le
     * refus de l'éditeur, ferme sa démonstration pour de bon : elle ne se
     * rouvre plus.
     */
    public function test_une_inscription_close_ne_rouvre_pas_sa_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);

        app(CloreLInscriptionAction::class)->refuser($societe, new InterventionDeLEditeur('Courrier revenu, pièces insuffisantes.', 'DOSSIER-42', ['alice']));

        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);
        $this->assertThrows(fn () => app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh()), VerificationRefuseeException::class, OuvrirLaDemonstrationAction::MESSAGE_SANS_AUTORISATION);
    }

    public function test_une_demonstration_inactive_30_jours_se_ferme_et_se_rouvre_avant_la_validation(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($societe);
        $this->vendre();

        $this->travelTo(self::aParis('2026-11-07 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $this->assertSame(StatutMagasin::Actif, $demonstration->fresh()->statut);

        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $this->assertSame(StatutMagasin::Ferme, $demonstration->fresh()->statut);

        $rouverte = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());

        $this->assertSame([$demonstration->id, StatutMagasin::Actif], [$rouverte->id, $rouverte->statut]);
        $this->assertSame(2, PeriodeActivite::query()->where('magasin_id', $demonstration->id)->count());
        $this->assertSame(['105', '270', '270', '100'], array_slice($this->codesDuJournal($demonstration), -4));
        app(TenantContext::class)->runAs($demonstration, fn () => $this->assertSame(3, Produit::query()->count()));
    }

    /**
     * La démonstration prend le territoire du siège de la société, lu à
     * son code postal : en Martinique, elle propose 8,5 % ; fermée pour
     * inactivité puis rouverte, elle le garde. Un siège inconnu, ou dans
     * une collectivité d'outre-mer (Saint-Barthélemy), donne la métropole.
     */
    public function test_la_demonstration_prend_le_territoire_du_siege_et_le_garde(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $martinique = $this->societeAuSiege('97200', 'FORT-DE-FRANCE', '97209');
        $demonstration = $this->ouvrirLaDemonstrationEtYEntrer($martinique);

        $this->assertSame([Territoire::Martinique, 8.5], [$demonstration->territoire, $demonstration->tauxTvaParDefaut()]);
        app(TenantContext::class)->runAs($demonstration, fn () => $this->assertSame(['8.50'], Produit::query()->distinct()->pluck('taux_tva')->all()));

        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $rouverte = app(OuvrirLaDemonstrationAction::class)->handle($martinique->proprietaire, $martinique->fresh());
        $this->assertSame([$demonstration->id, Territoire::Martinique], [$rouverte->id, $rouverte->fresh()->territoire]);

        $sansSiege = $this->inscrireUneSociete();
        app(TenantContext::class)->runAsSociete($sansSiege, fn (): bool => $sansSiege->forceFill(['siege_adresse' => null, 'siege_code_postal' => null, 'siege_ville' => null])->save());
        $saintBarthelemy = $this->societeAuSiege('97133', 'SAINT-BARTHELEMY', '97701');

        $this->assertSame(Territoire::Metropole, app(OuvrirLaDemonstrationAction::class)->handle($sansSiege->proprietaire, $sansSiege->fresh())->territoire);
        $this->assertSame(Territoire::Metropole, app(OuvrirLaDemonstrationAction::class)->handle($saintBarthelemy->proprietaire, $saintBarthelemy)->territoire);
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
     * Le propriétaire, puis un responsable de la démonstration, y créent
     * des comptes d'entraînement : rattachés à la démonstration, ils n'y
     * ouvrent qu'elle, et y vendent.
     */
    public function test_le_proprietaire_et_un_responsable_creent_des_comptes_d_entrainement_dans_la_demonstration(): void
    {
        $societe = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe);
        app(TenantContext::class)->set($demonstration);

        $chef = $this->choisirSonMotDePasse(app(CreerMembreAction::class)->handle($societe->proprietaire, $demonstration, ['name' => 'Chef d\'essai'], RoleMagasin::Responsable));
        $vendeur = $this->choisirSonMotDePasse(app(CreerMembreAction::class)->handle($chef, $demonstration, ['name' => 'Vendeur d\'essai'], RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut()]));

        foreach ([$chef, $vendeur] as $compte) {
            $this->assertSame([true, (int) $demonstration->id, [(int) $demonstration->id]], [$compte->estUnCompteDEntrainement(), (int) $compte->magasinDeSonIdentifiant()?->id, $compte->magasinsAccessibles()->modelKeys()], $compte->name);
        }
        $this->actingAs($vendeur);
        $this->assertSame('payee', $this->vendre()->fresh()->statut->value);
    }

    /**
     * Un administrateur, même de tous les magasins et autorisé à tout, ne
     * crée aucun compte d'entraînement : ni responsable, ni vendeur.
     */
    public function test_un_administrateur_ne_cree_aucun_compte_d_entrainement(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $administrateur = $this->administrateur($reel, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());

        foreach ([RoleMagasin::Responsable, RoleMagasin::Vendeur] as $role) {
            $this->assertThrows(fn () => app(TenantContext::class)->runAs($demonstration, fn () => app(CreerMembreAction::class)->handle($administrateur, $demonstration, ['name' => 'Essai'], $role)), AuthorizationException::class);
        }

        $this->assertFalse(User::query()->where('name', 'Essai')->exists());
        $this->assertSame(0, Identifiant::withoutGlobalScopes()->where('societe_id', $societe->id)->where('magasin_id', $demonstration->id)->count());
    }

    /**
     * Les comptes d'entraînement sont retirés de la société, au nom du
     * système, quand la démonstration se ferme pour de bon, à la validation
     * comme à la clôture de l'inscription ; fermée pour trente jours
     * d'inactivité, elle les garde, et ils la retrouvent à sa réouverture
     * (le code du premier courrier ayant expiré entre-temps, la société est
     * validée par celui du nouveau courrier demandé au quinzième jour).
     */
    public function test_les_comptes_d_entrainement_sont_retires_a_la_validation_ou_a_la_cloture_et_gardes_a_la_fermeture_pour_inactivite(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $validee = $this->inscrireUneSociete();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($validee->proprietaire, $validee);
        $compte = $this->compteDEntrainement($demonstration);
        $this->ouvrirUneSessionAilleurs($compte);

        $this->travelTo(self::aParis('2026-10-24 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($validee->proprietaire, $validee->fresh());
        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $this->assertSame([StatutMagasin::Ferme, StatutCompte::Actif], [$demonstration->fresh()->statut, $compte->refresh()->statut]);
        app(OuvrirLaDemonstrationAction::class)->handle($validee->proprietaire, $validee->fresh());
        $this->assertTrue($compte->refresh()->peutOuvrir($demonstration->fresh()));

        $this->saisirLeCodeDuCourrier($validee);

        $compte->refresh();
        $this->assertSame([StatutCompte::Retire, null], [$compte->statut, $compte->statut_change_par]);
        $this->assertSame(0, Affectation::query()->where('user_id', $compte->id)->count());
        $this->assertSessionsFermees($compte);
        $this->assertCount(1, $this->entreesDAudit($validee, ActionAuditee::CompteRetire));

        $close = $this->inscrireUneSociete();
        $autreDemonstration = app(OuvrirLaDemonstrationAction::class)->handle($close->proprietaire, $close);
        $compteDeLaCloture = $this->compteDEntrainement($autreDemonstration);
        $this->travelTo(self::aParis('2026-12-10 10:00:00'));
        $this->artisan('app:clore-inscriptions-sans-code')->assertSuccessful();
        $this->assertSame([true, StatutCompte::Retire], [$close->fresh()->estClose(), $compteDeLaCloture->refresh()->statut]);
    }

    /**
     * La démonstration fermée pour de bon (société validée ou inscription
     * close), ses
     * comptes d'entraînement retirés ne reviennent pas : le propriétaire n'y
     * crée plus aucun compte et n'y réintègre aucun ancien, et ni l'espace
     * de la société ni l'équipe de la démonstration ne le lui proposent ;
     * fermée pour inactivité, elle en accepte toujours.
     */
    public function test_aucun_compte_ne_se_cree_ni_ne_se_reintegre_dans_une_demonstration_terminee(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire;
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());
        $ancien = $this->compteDEntrainement($demonstration);
        $this->travelTo(self::aParis('2026-10-24 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($proprietaire, $societe->fresh());
        $this->travelTo(self::aParis('2026-11-09 10:00:00'));
        $this->artisan('app:fermer-demonstrations-inactives')->assertSuccessful();
        $this->assertFalse($demonstration->fresh()->estUneDemonstrationTerminee());
        $this->assertTrue(app(TenantContext::class)->runAs($demonstration, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $demonstration->fresh(), ['name' => 'Pendant la pause'], RoleMagasin::Vendeur))->estActif());

        $this->saisirLeCodeDuCourrier($societe);
        $this->assertSame(StatutCompte::Retire, $ancien->refresh()->statut);
        $this->assertTrue($demonstration->fresh()->estUneDemonstrationTerminee());
        $identifiants = Identifiant::withoutGlobalScopes()->where('magasin_id', $demonstration->id)->count();

        foreach ([RoleMagasin::Vendeur, RoleMagasin::Responsable] as $role) {
            $this->assertThrows(fn () => app(TenantContext::class)->runAs($demonstration, fn () => app(CreerMembreAction::class)->handle($proprietaire, $demonstration->fresh(), ['name' => 'Après la fin'], $role)), AuthorizationException::class);
        }
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reintegrer($proprietaire, $ancien, RoleMagasin::Vendeur, RoleMagasin::Vendeur->permissionsParDefaut()), AuthorizationException::class);
        $this->assertSame(StatutCompte::Retire, $ancien->refresh()->statut);
        $this->assertFalse(User::query()->where('name', 'Après la fin')->exists());
        $this->assertSame($identifiants, Identifiant::withoutGlobalScopes()->where('magasin_id', $demonstration->id)->count());

        $this->dansLEspaceDeLaSociete($societe, $proprietaire);
        Livewire::test(ListMembres::class)
            ->mountAction('creerUnMembre')
            ->assertFormFieldExists('magasin_id', 'mountedActionForm', fn (Select $champ): bool => array_keys($champ->getOptions()) === [(int) $reel->id]);
        $this->dansLeMagasin($demonstration->fresh(), $proprietaire);
        Livewire::test(ListEquipeDuMagasin::class)->assertActionHidden('creerUnCompte');
    }

    /**
     * Un compte d'entraînement n'ouvre jamais un magasin réel : ni par une
     * affectation de membre, que la base refuse hors du magasin de son
     * identifiant, ni par une promotion à un rôle de société, que l'action
     * et la base refusent.
     */
    public function test_un_compte_d_entrainement_n_ouvre_jamais_un_magasin_reel(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($societe->proprietaire, $societe->fresh());
        $compte = $this->compteDEntrainement($demonstration);

        $this->assertFalse($compte->peutOuvrir($reel));
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('affectations')->insert(['societe_id' => $societe->id, 'user_id' => $compte->id, 'magasin_id' => $reel->id, 'role' => RoleMagasin::Vendeur->value, 'created_at' => now(), 'updated_at' => now()])), QueryException::class);
        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($societe->proprietaire, $compte, null, [], []), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_ENTRAINEMENT);
        $this->assertThrows(fn () => app(ChangerLeRoleDeSocieteAction::class)->versExpertComptable($societe->proprietaire, $compte), LogicException::class, ChangerLeRoleDeSocieteAction::MESSAGE_ENTRAINEMENT);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $compte->id)->update(['role_societe' => RoleSociete::ExpertComptable->value])), QueryException::class);

        $this->assertSame([RoleSociete::Membre, [(int) $demonstration->id]], [$compte->refresh()->role_societe, $compte->magasinsAccessibles()->modelKeys()]);
        $this->assertFalse($compte->peutOuvrir($reel));
    }

    /**
     * Un vendeur d'entraînement de la démonstration, créé par le
     * propriétaire, son mot de passe choisi.
     */
    private function compteDEntrainement(Magasin $demonstration): User
    {
        $proprietaire = Societe::query()->findOrFail($demonstration->societe_id)->proprietaire;

        return $this->choisirSonMotDePasse(app(TenantContext::class)->runAs($demonstration, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $demonstration, ['name' => 'Vendeur d\'essai'], RoleMagasin::Vendeur)));
    }

    /**
     * Une société inscrite dont le répertoire Sirene factice situe le siège
     * à ce code postal, dans cette ville, de cette commune : l'adresse de
     * son siège en est reprise.
     */
    private function societeAuSiege(string $codePostal, string $ville, string $codeCommune): Societe
    {
        $siren = SocieteFactory::sirenFictif();
        $repertoire = app(RepertoireSirene::class);
        $this->assertInstanceOf(FauxRepertoireSirene::class, $repertoire);
        $repertoire->deplacer(FauxRepertoireSirene::siretDuSiege($siren), $codePostal, $ville, $codeCommune);
        $societe = $this->inscrireUneSociete($siren);
        $this->assertSame($codePostal, $societe->siege_code_postal);

        return $societe;
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
