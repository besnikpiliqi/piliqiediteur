<?php

namespace Tests\Feature\Calendrier;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CaissesDeLaSociete;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\MotifCode;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Models\CodeConfirmation;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Services\LimitationDesConnexions;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\InscriptionSociete;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ViewEnregistrementFiscal;
use App\Filament\App\Resources\EnregistrementFiscalResource\Widgets\ConformiteApercu;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le 25 octobre 2026, l'heure de Paris passe deux fois de 2 h à 3 h : de
 * 00:00 à 01:00 UTC à l'heure d'été (premier passage), puis de 01:00 à
 * 02:00 UTC à l'heure d'hiver (second passage). Chaque délai, chaque
 * comparaison et chaque ordre s'y comptent sur les instants réels, l'horloge
 * figée directement à chacun des deux passages.
 */
class HeureRepeteeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Un lien « Mot de passe oublié » créé au premier passage vaut
     * exactement 60 minutes : encore valable à 01:29:59 UTC, refusé à
     * 01:30:00 UTC ; une seconde demande est refusée avant 60 secondes et
     * acceptée après.
     */
    public function test_un_lien_mot_de_passe_oublie_cree_au_premier_passage_expire_apres_exactement_60_minutes(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        $proprietaire = $this->proprietaire();
        $jeton = Password::broker()->createToken($proprietaire);
        $demande = fn (): string => Password::broker()->sendResetLink(['email' => 'patron@boutique.test'], static function (): void {});

        $this->travelTo(self::enUtc('2026-10-25 00:30:59'));
        $this->assertSame(Password::RESET_THROTTLED, $demande());
        $this->travelTo(self::enUtc('2026-10-25 01:29:59'));
        $this->assertTrue(app(JetonsDuLien::class)->estValable($proprietaire, $jeton));
        $this->travelTo(self::enUtc('2026-10-25 01:30:00'));
        $this->assertFalse(app(JetonsDuLien::class)->estValable($proprietaire, $jeton));

        $nouveau = Password::broker()->createToken($proprietaire);
        $this->travelTo(self::enUtc('2026-10-25 01:31:00'));
        $this->assertSame(Password::RESET_LINK_SENT, $demande());
        $this->assertFalse(app(JetonsDuLien::class)->estValable($proprietaire, $nouveau));
    }

    /**
     * Le lien d'inscription demandé au premier passage expire 24 heures
     * plus tard, en base comme dans l'e-mail : valable le 26 octobre à
     * 00:29:59 UTC, refusé à 00:30:00 UTC.
     */
    public function test_un_lien_d_inscription_demande_au_premier_passage_expire_24_heures_plus_tard(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        Mail::fake();
        Livewire::test(InscriptionSociete::class)
            ->fillForm(['siren' => SocieteFactory::sirenFictif(), 'email' => 'nouvelle@exemple.fr', 'password' => self::MOT_DE_PASSE_CHOISI, 'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI])
            ->call('register');
        $mail = Mail::sent(DemandeInscriptionMail::class)->sole();

        $this->assertMemeInstant(self::enUtc('2026-10-26 00:30:00'), DemandeInscription::query()->sole()->expire_le);
        $this->assertMemeInstant(self::enUtc('2026-10-26 00:30:00'), $mail->expireLe);
        $this->travelTo(self::enUtc('2026-10-26 00:29:59'));
        $this->get($mail->lien)->assertOk();
        $this->travelTo(self::enUtc('2026-10-26 00:30:00'));
        $this->get($mail->lien)->assertStatus(410);
    }

    /**
     * Un lien qui expire au premier passage (2 h 40 à l'heure d'été) est
     * refusé pendant le second passage, même à 2 h 20 à l'heure d'hiver,
     * dont l'heure murale est plus tôt.
     */
    public function test_un_lien_expire_au_premier_passage_est_refuse_pendant_le_second(): void
    {
        $jeton = Str::random(64);
        (new DemandeInscription)->forceFill([
            'email' => 'nouvelle@exemple.fr',
            'jeton_hache' => DemandeInscription::empreinte($jeton),
            'donnees' => ['siren' => SocieteFactory::sirenFictif(), 'mot_de_passe' => self::MOT_DE_PASSE_CHOISI],
            'expire_le' => self::enUtc('2026-10-25 00:40:00'),
            'created_at' => self::enUtc('2026-10-24 00:40:00'),
        ])->save();

        $this->travelTo(self::enUtc('2026-10-25 00:39:59'));
        $this->get(InscriptionSociete::lienDeConfirmation($jeton))->assertOk();
        $this->travelTo(self::enUtc('2026-10-25 01:20:00'));
        $this->get(InscriptionSociete::lienDeConfirmation($jeton))->assertStatus(410);
    }

    /**
     * La transmission de la propriété demandée au premier passage attend
     * 24 heures exactement ; la nouvelle adresse de connexion demandée au
     * premier passage se confirme jusqu'à son instant d'expiration, et plus
     * à cet instant.
     */
    public function test_une_transmission_de_la_propriete_et_une_nouvelle_adresse_expirent_a_leur_instant(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $this->demanderLaTransmission($proprietaire, $this->administrateurPretAReprendre($magasin));
        app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'nouvelle@boutique.test');
        $jeton = $this->jetonDeLAdresseEnAttente('nouvelle@boutique.test');
        $transmission = fn (): TransmissionPropriete => app(TenantContext::class)->runAsSociete($magasin->societe, fn (): TransmissionPropriete => TransmissionPropriete::query()->sole());

        $this->travelTo(self::enUtc('2026-10-26 00:29:59'));
        $this->assertTrue($transmission()->estValable());
        $this->travelTo(self::enUtc('2026-10-26 00:30:00'));
        $this->assertFalse($transmission()->estValable());
        $this->assertThrows(fn () => app(ConfirmerNouvelleAdresseAction::class)->handle($jeton, $proprietaire->fresh()), AuthorizationException::class);

        $this->travelTo(self::enUtc('2026-10-26 00:29:59'));
        $this->assertSame('nouvelle@boutique.test', app(ConfirmerNouvelleAdresseAction::class)->handle($jeton, $proprietaire->fresh())->email);
    }

    /**
     * Le code d'un courrier envoyé le 20 octobre 2026 à 10 h à Paris vaut
     * trente jours, jusqu'au 19 novembre à 10 h à Paris (décision Q2), le
     * passage à l'heure d'hiver tombant entre les deux.
     */
    public function test_un_code_de_courrier_expire_trente_jours_apres_son_envoi_a_la_meme_heure_de_paris(): void
    {
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $code = fn (): CodeConfirmation => app(TenantContext::class)->runAsSociete($societe, fn (): CodeConfirmation => CodeConfirmation::query()->where('motif', MotifCode::Verification->value)->sole());

        $this->assertMemeInstant(self::aParis('2026-11-19 10:00:00'), $code()->expire_le);
        $this->travelTo(self::aParis('2026-11-19 09:59:59'));
        $this->assertTrue($code()->estActif());
        $this->travelTo(self::aParis('2026-11-19 10:00:00'));
        $this->assertFalse($code()->estActif());
    }

    /**
     * Connecté au premier passage (00:50 UTC, 2 h 50 à l'heure d'été), un
     * vendeur dont l'accès est redonné au second (01:10 UTC, 2 h 10 à
     * l'heure d'hiver) le voit à sa connexion suivante.
     */
    public function test_un_acces_redonne_apres_une_connexion_du_premier_passage_est_signale(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:50:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->membre($magasin, RoleMagasin::Responsable, null, ['name' => 'Julie Morel']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();

        $this->travelTo(self::enUtc('2026-10-25 01:10:00'));
        app(RedonnerLAccesAction::class)->handle($responsable, $vendeur->refresh(), true, 'nouveau-mot-de-passe-1', (string) Str::uuid());
        $this->travelTo(self::enUtc('2026-10-25 01:20:00'));
        $this->seConnecter($identifiant, 'nouveau-mot-de-passe-1')->assertHasNoErrors();

        $this->assertSame(
            ['Votre connexion précédente : le 25/10/2026 à 02:50', 'Votre accès a été redonné le 25/10/2026 à 02:10 par Julie Morel, en votre présence.'],
            collect(session('filament.notifications', []))->pluck('title')->values()->all(),
        );
    }

    /**
     * Un devis émis au premier passage (00:50 UTC) part quand son envoi
     * est demandé au second (01:10 UTC) : il n'a pas été émis depuis la
     * demande.
     */
    public function test_un_devis_emis_au_premier_passage_part_quand_l_envoi_est_demande_au_second(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:50:00'));
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], ['date_validite' => '2026-10-31']);
        $premier = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null);
        (new EnvoyerDocumentJob($magasin->id, $premier->id))->handle();

        $this->travelTo(self::enUtc('2026-10-25 01:10:00'));
        $second = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis->fresh(), 'client@exemple.fr', null);
        (new EnvoyerDocumentJob($magasin->id, $second->id))->handle();

        $this->assertSame([StatutEnvoi::Envoye, StatutEnvoi::Envoye], [$premier->fresh()->statut, $second->fresh()->statut]);
        $this->assertSame([$premier->id, $second->id], Mail::sent(DocumentMail::class)->map(fn (DocumentMail $mail): int => $mail->envoi->id)->all());
    }

    /**
     * L'envoi demandé au premier passage (00:50 UTC) d'un devis modifié et
     * émis de nouveau au second (01:10 UTC) ne part pas : il a été écrit
     * pour la version précédente.
     */
    public function test_une_demande_d_envoi_anterieure_a_une_nouvelle_emission_n_envoie_pas_la_nouvelle_version(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:50:00'));
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        $produit = $this->produit();
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ['date_validite' => '2026-10-31']);
        $premier = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null);

        $this->travelTo(self::enUtc('2026-10-25 01:10:00'));
        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis->fresh(), $devis->client, [['produit_id' => $produit->id, 'quantite' => 5]]);
        $second = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $modifie, 'client@exemple.fr', null);
        (new EnvoyerDocumentJob($magasin->id, $premier->id))->handle();
        (new EnvoyerDocumentJob($magasin->id, $second->id))->handle();

        $this->assertSame([StatutEnvoi::Echec, StatutEnvoi::Envoye], [$premier->fresh()->statut, $second->fresh()->statut]);
        $this->assertSame([$second->id], Mail::sent(DocumentMail::class)->map(fn (DocumentMail $mail): int => $mail->envoi->id)->all());
    }

    /**
     * Une inscription créée au premier passage se clôt sans code à la
     * limite de ce même instant, et son SIREN sert de nouveau.
     */
    public function test_une_inscription_creee_au_premier_passage_se_clot_sans_code_et_libere_son_siren(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        $siren = SocieteFactory::sirenFictif();
        $societe = $this->inscrireUneSociete($siren, 'premiere@exemple.fr');

        $this->assertTrue(app(CloreLInscriptionAction::class)->cloreSansCode($societe, CarbonImmutable::now()));
        $this->assertNotNull($societe->fresh()->close_le);
        $this->assertSame($siren, $this->inscrireUneSociete($siren, 'seconde@exemple.fr')->siren);
    }

    /**
     * Les échecs de connexion du premier passage comptent dans la fenêtre
     * d'une heure, en temps réel, à partir du premier d'entre eux : 29
     * échecs depuis une même adresse IP à 00:50 UTC (2 h 50, heure d'été),
     * puis le 30e au second passage, à 01:10 UTC (2 h 10, heure d'hiver,
     * plus tôt à l'heure murale), font attendre cette adresse ; à 01:50 UTC,
     * une heure après le premier échec, alors que l'heure murale ne marque
     * encore que 2 h 50, ils ne comptent plus.
     */
    public function test_les_essais_de_connexion_du_premier_passage_comptent_dans_la_fenetre_d_une_heure(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:50:00'));
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $echouer = function (int $rang): void {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter("inconnu-{$rang}@exemple.fr", 'pas-le-bon')), "échec {$rang}");
        };

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_ADRESSE - 1) as $rang) {
            $echouer($rang);
        }

        $this->travelTo(self::enUtc('2026-10-25 01:10:00'));
        $echouer(LimitationDesConnexions::ECHECS_PAR_ADRESSE);
        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)));

        $this->travelTo(self::enUtc('2026-10-25 01:50:00'));
        $echouer(LimitationDesConnexions::ECHECS_PAR_ADRESSE + 1);
        $this->assertNull($this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)));
        $this->assertAuthenticatedAs($vendeur);
    }

    /**
     * Une vente au premier passage et une au second signent la même heure
     * murale avec leurs décalages (+02:00, +01:00) et leurs horodatages UTC
     * distincts, et le vérificateur de la caisse sécurisée ne signale rien.
     */
    public function test_une_vente_au_premier_passage_et_une_au_second_signent_leur_decalage_et_le_verificateur_ne_signale_rien(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        $this->ouvrirUneSociete(['nom' => 'Boutique Lefèvre']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $premiere = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $this->travelTo(self::enUtc('2026-10-25 01:30:00'));
        $seconde = self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);

        $signe = fn (Facture $ticket): array => EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->where('document_id', $ticket->id)->sole()->contenuSigne();

        $this->assertSame(
            [['20261025003000Z', '2026-10-25T02:30:00+02:00'], ['20261025013000Z', '2026-10-25T02:30:00+01:00']],
            [[$signe($premiere)['horodatage'], $signe($premiere)['donnees']['document']['date_emission']], [$signe($seconde)['horodatage'], $signe($seconde)['donnees']['document']['date_emission']]],
        );
        $this->artisan('caisse:verifier')
            ->expectsOutputToContain('Intégrité de la caisse sécurisée vérifiée : aucune anomalie.')
            ->assertSuccessful();
    }

    /**
     * Les relevés de caisse fermés aux deux passages se lisent dans leur
     * ordre réel, le plus récent d'abord : celui de 01:10 UTC (2 h 10 à
     * l'heure d'hiver) avant celui de 00:50 UTC (2 h 50 à l'heure d'été).
     */
    public function test_les_releves_et_les_journaux_se_trient_dans_l_ordre_reel_des_deux_passages(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:50:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $paul = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $julie = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Julie']);
        self::vendreAuComptant($paul, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        self::vendreAuComptant($julie, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        app(FermerSaCaisseAction::class)->handle($paul, $paul, (string) Str::uuid());

        $this->travelTo(self::enUtc('2026-10-25 01:10:00'));
        app(FermerSaCaisseAction::class)->handle($julie, $julie, (string) Str::uuid());

        [$caisses] = app(CaissesDeLaSociete::class)->pour($this->proprietaireDe($magasin), '2026-10-25');
        $this->assertSame(['Julie', 'Paul'], $caisses['releves']->pluck('vendeur_nom')->all());
        $this->assertSame(
            ['25/10/2026 02:10', '25/10/2026 02:50'],
            $caisses['releves']->map(fn ($releve): string => Calendrier::deParis()->afficher($releve->cloturee_a, 'd/m/Y H:i'))->all(),
        );
    }

    /**
     * Le journal des événements dit, pendant l'heure répétée, si un
     * horodatage est de l'heure d'été ou de l'heure d'hiver (Q3) : la
     * liste, dans l'ordre réel du plus récent au plus ancien, la fiche et
     * la colonne lisible de l'export CSV ; la colonne signée de l'export
     * reste l'horodatage UTC, et le widget de conformité, qui n'est pas le
     * journal, n'ajoute rien.
     */
    public function test_le_journal_des_evenements_dit_l_heure_d_ete_ou_d_hiver_pendant_l_heure_repetee(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 00:30:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);
        $premier = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, ['parametre' => 'regime_tva', 'ancienne_valeur' => null, 'nouvelle_valeur' => 'reel_simplifie']));
        $this->travelTo(self::enUtc('2026-10-25 01:30:00'));
        $second = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, ['parametre' => 'regime_tva', 'ancienne_valeur' => 'reel_simplifie', 'nouvelle_valeur' => 'reel_normal']));
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        self::vendreAuComptant(self::responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->assertSeeInOrder(["25/10/2026 02:30:00 (heure d'hiver)", "25/10/2026 02:30:00 (heure d'été)"]);
        Livewire::test(ViewEnregistrementFiscal::class, ['record' => $premier->getRouteKey()])
            ->assertSee("25/10/2026 à 02:30:00 (heure d'été)");
        Livewire::test(ConformiteApercu::class)
            ->assertSee('25/10/2026 à 02:30:00')
            ->assertDontSee('(heure d');

        $export = Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => '2026-10-25', 'au' => '2026-10-25']);
        $lignes = array_values(array_filter($this->lignesDuCsv($export), fn (array $ligne): bool => in_array($ligne[0], [(string) $premier->sequence, (string) $second->sequence], true)));

        $this->assertSame(
            [["25/10/2026 02:30:00 (heure d'été)", '20261025003000Z'], ["25/10/2026 02:30:00 (heure d'hiver)", '20261025013000Z']],
            array_map(fn (array $ligne): array => [$ligne[1], $ligne[2]], $lignes),
        );
    }

    /**
     * Le fichier téléchargé par l'action, lu comme un tableur le lit.
     *
     * @return list<list<string>>
     */
    private function lignesDuCsv(Testable $composant): array
    {
        $contenu = base64_decode($composant->effects['download']['content']);
        $flux = fopen('php://memory', 'r+b');
        fwrite($flux, substr($contenu, 3));
        rewind($flux);
        $lignes = [];

        while (($ligne = fgetcsv($flux, null, ';', '"', '')) !== false) {
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    private function proprietaire(): User
    {
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        return $proprietaire->refresh();
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * Une connexion au panneau du magasin, sans session ouverte ni témoin,
     * depuis 127.0.0.1.
     */
    private function seConnecter(string $saisie, string $motDePasse): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => ''])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse])
            ->call('authenticate');
    }

    private function erreur(Testable $page): ?string
    {
        return $page->errors()->first('data.login') ?: null;
    }
}
