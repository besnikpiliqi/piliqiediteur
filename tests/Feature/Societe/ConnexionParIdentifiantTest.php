<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CloreLInscriptionAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\LimitationDesConnexions;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\IdentifiantDeConnexion;
use App\Filament\App\Pages\Auth\ChoisirSonMotDePasse;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\PosteReconnuResource;
use App\Filament\App\Resources\PosteReconnuResource\Pages\ListPostesReconnus;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La connexion par l'identifiant (voir Connexion et FournisseurDesComptes)
 * : tout compte entre par son identifiant de 8 chiffres, écrit avec ou
 * sans espaces ni tirets ; le propriétaire aussi par son adresse de
 * connexion ; la même réponse, au même coût, pour toute saisie refusée ;
 * une connexion réussie, et elle seule, est notée et montrée à la personne.
 */
class ConnexionParIdentifiantTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_membre_se_connecte_avec_son_identifiant_avec_ou_sans_espaces_ni_tirets(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();

        foreach ([$identifiant, substr($identifiant, 0, 4).' '.substr($identifiant, 4), ' '.substr($identifiant, 0, 4).'-'.substr($identifiant, 4).' ', implode('.', str_split($identifiant, 2))] as $ecriture) {
            $this->seConnecter($ecriture, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
            $this->assertAuthenticatedAs($vendeur, null);
        }
    }

    public function test_le_proprietaire_se_connecte_avec_son_adresse_ou_son_identifiant(): void
    {
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));

        foreach ([$proprietaire->email, strtoupper($proprietaire->email), (string) $proprietaire->identifiantDeConnexion()] as $saisie) {
            $this->seConnecter($saisie, 'password')->assertHasNoErrors();
            $this->assertAuthenticatedAs($proprietaire);
        }
    }

    public function test_l_adresse_de_contact_d_un_autre_compte_n_ouvre_jamais_ce_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'paul@example.test']);

        $this->assertSame([Connexion::MESSAGE_ECHEC], $this->seConnecter('paul@example.test', self::MOT_DE_PASSE_CHOISI)->errors()->get('data.login'));

        $this->assertGuest();
        $this->assertNull($vendeur->refresh()->premiere_connexion_le);
    }

    /**
     * Un identifiant inconnu, un mot de passe faux, un compte suspendu ou
     * retiré au bon mot de passe reçoivent la même réponse, et l'écran ne
     * réaffiche pas la saisie.
     */
    public function test_un_identifiant_inconnu_un_mot_de_passe_faux_un_compte_suspendu_ou_retire_recoivent_la_meme_reponse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $actif = $this->membre($magasin, RoleMagasin::Vendeur);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);
        $inconnu = $this->identifiantInconnu();

        foreach ([[$inconnu, self::MOT_DE_PASSE_CHOISI], [(string) $actif->identifiantDeConnexion(), 'pas-le-bon'], [(string) $suspendu->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI], [(string) $retire->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI]] as [$saisie, $motDePasse]) {
            $page = $this->seConnecter($saisie, $motDePasse);
            $this->assertSame([Connexion::MESSAGE_ECHEC], $page->errors()->get('data.login'), $saisie);
            $this->assertSame([null, null], [$page->get('data.login'), $page->get('data.password')]);
            $this->assertGuest();
        }
    }

    /**
     * Un identifiant inconnu, ou une adresse qui n'est pas celle d'un
     * propriétaire, coûte la même vérification de hachage qu'un compte
     * existant au mauvais mot de passe, contre une empreinte au coût de
     * hachage de l'application, sans jamais hacher pendant la requête
     * (aucun make(), dans aucun processus), et autant de requêtes à la
     * base ; et le délai constant de la connexion vaut par défaut 500 ms,
     * au-dessus du coût d'une vérification au réglage de production (de 230
     * à 260 ms mesurés, une mesure que le test ne refait pas : elle
     * dépendrait de la machine).
     */
    public function test_un_identifiant_inconnu_fait_la_meme_verification_de_hachage(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'paul@example.test']);
        $hachage = $this->compterLesVerifications();
        $requetes = 0;
        DB::listen(function () use (&$requetes): void {
            $requetes++;
        });
        $requetesParCas = [];

        foreach (['inconnu' => $this->identifiantInconnu(), 'adresse de contact' => 'paul@example.test', 'adresse inconnue' => 'personne@example.test', 'mauvais mot de passe' => (string) $vendeur->identifiantDeConnexion()] as $cas => $saisie) {
            $hachage->verifications = 0;
            $hachage->creations = 0;
            $hachage->empreintes = [];
            $requetes = 0;
            $this->seConnecter($saisie, 'pas-le-bon')->assertHasErrors(['data.login']);
            $requetesParCas[$cas] = $requetes;
            $this->assertSame([1, 0], [$hachage->verifications, $hachage->creations], $cas);
            $this->assertSame(['bcrypt', (int) config('hashing.bcrypt.rounds')], [password_get_info($hachage->empreintes[0])['algoName'], password_get_info($hachage->empreintes[0])['options']['cost'] ?? null], $cas);
        }
        $this->assertSame($requetesParCas['inconnu'], $requetesParCas['mauvais mot de passe']);
        $this->assertSame($requetesParCas['adresse inconnue'], $requetesParCas['adresse de contact']);

        $config = file_get_contents(config_path('auth.php'));
        $this->assertStringContainsString("'timebox_duration' => (int) env('AUTH_DELAI_CONSTANT_US', 500000)", (string) $config);
    }

    /**
     * L'adresse qu'a choisie, en saisissant le code du courrier, une autre
     * personne que celle qui a inscrit la société, tant que son lien n'est
     * pas suivi, et l'adresse du propriétaire d'une inscription close ne
     * sont pas des adresses de connexion : un essai avec elles reçoit la
     * même réponse qu'une adresse inconnue, au même coût (une vérification
     * contre l'empreinte factice, aucun hachage) et avec autant de
     * requêtes à la base.
     */
    public function test_une_adresse_en_attente_ou_d_une_inscription_close_coute_comme_une_adresse_inconnue(): void
    {
        $this->saisirLeCodeCommeUneAutrePersonne($this->inscrireUneSociete(null, 'inscrit@example.test'), 'representante@example.test');
        $close = $this->inscrireUneSociete(null, 'jeanne@example.test');
        app(TenantContext::class)->clear();
        app(CloreLInscriptionAction::class)->cloreSansCode($close->fresh(), now()->toImmutable());
        app(TenantContext::class)->clear();
        $hachage = $this->compterLesVerifications();
        $requetes = 0;
        DB::listen(function () use (&$requetes): void {
            $requetes++;
        });
        $requetesParCas = [];

        foreach (['adresse inconnue' => 'personne@example.test', 'adresse en attente' => 'representante@example.test', 'adresse d\'une inscription close' => 'jeanne@example.test'] as $cas => $saisie) {
            $hachage->verifications = 0;
            $hachage->creations = 0;
            $hachage->empreintes = [];
            $requetes = 0;
            $page = $this->seConnecter($saisie, self::MOT_DE_PASSE_CHOISI);
            $requetesParCas[$cas] = $requetes;
            $this->assertSame([Connexion::MESSAGE_ECHEC], $page->errors()->get('data.login'), $cas);
            $this->assertGuest();
            $this->assertSame([1, 0], [$hachage->verifications, $hachage->creations], $cas);
            $this->assertSame(['bcrypt', (int) config('hashing.bcrypt.rounds')], [password_get_info($hachage->empreintes[0])['algoName'], password_get_info($hachage->empreintes[0])['options']['cost'] ?? null], $cas);
        }
        $this->assertSame([$requetesParCas['adresse inconnue'], $requetesParCas['adresse inconnue']], [$requetesParCas['adresse en attente'], $requetesParCas['adresse d\'une inscription close']]);
    }

    public function test_une_saisie_qui_n_a_pas_huit_chiffres_est_signalee_sans_rien_reveler(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $hachage = $this->compterLesVerifications();

        foreach (['1234567', '123456789', '01234567', 'abcdefgh', '1234 567'] as $saisie) {
            $this->assertSame([IdentifiantDeConnexion::MESSAGE_FORME], $this->seConnecter($saisie, 'peu-importe')->errors()->get('data.login'), $saisie);
        }

        $this->assertSame(0, $hachage->verifications);
        $this->assertSame(0, app(LimitationDesConnexions::class)->attente('1234567', '127.0.0.1', false));
        $this->assertGuest();
    }

    /**
     * Un compte dont le mot de passe est encore l'identifiant se connecte
     * depuis un poste reconnu de son magasin, et reçoit à sa première
     * connexion l'alerte qui lui conseille d'en choisir un.
     */
    public function test_un_compte_dont_le_mot_de_passe_est_l_identifiant_se_connecte_et_recoit_l_alerte_a_sa_premiere_connexion(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$compte, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $poste = $this->reconnaitreUnPoste($magasin);

        $this->seConnecter($identifiant, $identifiant, postes: $poste)
            ->assertHasNoErrors()
            ->assertNotified('Changez votre mot de passe');

        $this->assertAuthenticatedAs($compte);
        $this->assertNotNull($compte->refresh()->premiere_connexion_le);
        $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasNoErrors()->assertNotNotified('Changez votre mot de passe');
    }

    /**
     * Le propriétaire est prévenu de la première connexion de chaque
     * compte, une seule fois, inscrite au journal d'audit ; jamais de la
     * sienne.
     */
    public function test_le_proprietaire_est_prevenu_de_la_premiere_connexion_de_chaque_compte(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $administrateur = $this->administrateur($magasin);

        $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->seConnecter((string) $administrateur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI, 'societe')->assertHasNoErrors();
        $this->seConnecter($proprietaire->email, 'password')->assertHasNoErrors();

        $avis = Notification::sent($proprietaire, AvisDeLEquipe::class)->filter(fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Première connexion');
        $this->assertCount(2, $avis);
        $this->assertSame('Paul s\'est connecté à son compte pour la première fois.', $avis->first()->texte);
        $this->assertCount(2, $this->entreesDAudit($magasin->societe, ActionAuditee::PremiereConnexion));
        $this->assertNotNull($proprietaire->refresh()->premiere_connexion_le);
    }

    /**
     * Une connexion refusée (compte suspendu ou retiré au bon mot de passe,
     * membre qui se connecte à l'espace de la société) ne pose ni première
     * ni dernière connexion, ne prévient personne et n'inscrit rien.
     */
    public function test_une_connexion_refusee_ne_compte_ni_comme_premiere_ni_comme_derniere_connexion(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        $this->seConnecter((string) $suspendu->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasErrors(['data.login']);
        $this->seConnecter((string) $retire->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasErrors(['data.login']);
        $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI, 'societe')->assertHasErrors(['data.login']);

        foreach ([$suspendu, $retire, $vendeur] as $compte) {
            $this->assertSame([null, null], [$compte->refresh()->premiere_connexion_le, $compte->derniere_connexion_le]);
        }
        Notification::assertNothingSentTo($proprietaire);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::PremiereConnexion));
        $this->assertGuest();
    }

    public function test_la_personne_voit_sa_connexion_precedente_a_chaque_connexion(): void
    {
        $this->travelTo('2026-10-12 09:15:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();

        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors()->assertNotNotified('Votre connexion précédente : le 12/10/2026 à 09:15');
        $this->travelTo('2026-10-13 14:40:00');
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertNotified('Votre connexion précédente : le 12/10/2026 à 09:15');
        $this->travelTo('2026-10-14 08:05:00');
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertNotified('Votre connexion précédente : le 13/10/2026 à 14:40');
    }

    /**
     * Les compteurs suivent l'adresse de la connexion : un en-tête
     * X-Forwarded-For venu d'un mandataire qui n'est pas déclaré dans
     * PROXIES_DE_CONFIANCE est ignoré, et changer d'adresse forgée à
     * chaque essai ne contourne pas l'attente ; depuis un mandataire
     * déclaré, l'adresse qu'il transmet est suivie.
     */
    public function test_les_compteurs_ignorent_un_x_forwarded_for_venu_d_un_proxy_non_declare(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $instantane = $this->instantaneLivewire($this->get('/app/login')->assertOk()->getContent(), 'connexion');
        $essayer = fn (string $saisie, int $rang): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeaders(['X-Forwarded-For' => "203.0.113.{$rang}"])
            ->requeteLivewire($instantane, ['data.login' => $saisie, 'data.password' => 'pas-le-bon'], 'authenticate');

        $forge = $this->identifiantInconnu();
        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreurDeConnexion($essayer($forge, $rang)));
        }
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreurDeConnexion($essayer($forge, 99)));

        config(['piliqi.proxies_de_confiance' => ['10.0.0.1']]);
        $suivi = $this->identifiantInconnu('5');
        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE + 1) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreurDeConnexion($essayer($suivi, $rang)), "essai {$rang}");
        }
    }

    /**
     * Sans mandataire déclaré, aucun en-tête X-Forwarded-For n'est cru, même
     * sur Laravel Cloud (LARAVEL_CLOUD=1) ou sur un hôte de Forge ou de
     * Vapor, où Laravel croirait sinon tout mandataire ; « * », « ** » et
     * « REMOTE_ADDR » déclarés ne valent rien : changer d'adresse forgée à
     * chaque essai ne contourne pas l'attente.
     */
    public function test_sans_mandataire_declare_aucun_x_forwarded_for_n_est_cru_meme_sur_laravel_cloud_forge_ou_vapor(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $instantane = $this->instantaneLivewire($this->get('/app/login')->assertOk()->getContent(), 'connexion');
        $essayer = fn (string $connexion, string $hote, string $saisie, int $rang): TestResponse => $this->withServerVariables(['REMOTE_ADDR' => $connexion])
            ->withHeaders(['X-Livewire' => 'true', 'X-Forwarded-For' => "203.0.113.{$rang}"])
            ->postJson("http://{$hote}/livewire/update", ['components' => [[
                'snapshot' => $instantane,
                'updates' => ['data.login' => $saisie, 'data.password' => 'pas-le-bon'],
                'calls' => [['path' => '', 'method' => 'authenticate', 'params' => []]],
            ]]]);
        $_SERVER['LARAVEL_CLOUD'] = '1';

        try {
            foreach (['10.0.0.1' => [], '10.0.0.2' => ['*'], '10.0.0.3' => ['**', 'REMOTE_ADDR']] as $connexion => $declares) {
                config(['piliqi.proxies_de_confiance' => $declares]);

                foreach (['localhost', 'piliqi.on-forge.com', 'piliqi.on-vapor.com'] as $hote) {
                    $saisie = $this->identifiantInconnu();
                    foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE) as $rang) {
                        $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreurDeConnexion($essayer($connexion, $hote, $saisie, $rang)), "{$hote}, essai {$rang}");
                    }
                    $this->assertSame(LimitationDesConnexions::message(60), $this->erreurDeConnexion($essayer($connexion, $hote, $saisie, 99)), $hote);
                }
            }
        } finally {
            unset($_SERVER['LARAVEL_CLOUD']);
        }
    }

    /**
     * Un compte de membre n'ouvre que son magasin : la connexion l'y mène,
     * et un autre magasin de la société lui répond 404.
     */
    public function test_un_compte_de_membre_n_ouvre_que_son_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();

        $this->assertSame([(int) $magasin->id], $vendeur->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertFalse($vendeur->canAccessTenant($autre));
        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
        $this->get(FactureResource::getUrl('index', tenant: $autre))->assertNotFound();
    }

    /**
     * « Se souvenir de moi » ne vaut que pour le propriétaire : pour tout
     * autre compte, la case cochée est sans effet.
     */
    public function test_se_souvenir_de_moi_ne_vaut_que_pour_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeur->forceFill(['remember_token' => null])->save();
        $proprietaire->forceFill(['remember_token' => null])->save();
        $rappel = Auth::guard('web')->getRecallerName();

        $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI, seSouvenir: true)->assertHasNoErrors();
        $this->assertNull(Cookie::queued($rappel));
        $this->assertNull($vendeur->refresh()->remember_token);

        $this->seConnecter($proprietaire->email, 'password', seSouvenir: true)->assertHasNoErrors();
        $this->assertNotNull(Cookie::queued($rappel));
        $this->assertNotNull($proprietaire->refresh()->remember_token);
    }

    /**
     * Le cookie « Se souvenir de moi » d'un compte qui n'est pas, ou plus,
     * propriétaire n'ouvre rien ; celui du propriétaire ouvre son compte.
     */
    public function test_le_cookie_de_rappel_d_un_compte_qui_n_est_plus_proprietaire_n_ouvre_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $rappel = Auth::guard('web')->getRecallerName();

        foreach ([[$vendeur, false], [$proprietaire, true]] as [$compte, $ouvre]) {
            $compte->forceFill(['remember_token' => 'jeton-de-rappel-du-compte'])->save();
            $this->flushSession();
            auth()->forgetGuards();
            $reponse = $this->withCookie($rappel, $compte->id.'|jeton-de-rappel-du-compte|'.$compte->getAuthPassword())->get(FactureResource::getUrl('index', tenant: $magasin));
            $ouvre ? $reponse->assertOk() : $reponse->assertRedirect();
            $this->assertSame($ouvre, auth()->check(), $compte->name);
        }
        $this->assertNull(auth()->getProvider()->retrieveByToken($vendeur->id, 'jeton-de-rappel-du-compte'));
    }

    /**
     * Tant que le mot de passe d'un compte est son identifiant, il n'ouvre
     * ce compte que depuis un poste reconnu, non retiré, de son magasin,
     * avec la même réponse qu'un mot de passe faux ailleurs (un poste d'un
     * autre magasin, un poste retiré, aucun poste) ; un mot de passe choisi
     * ouvre le compte de partout. Seuls le propriétaire et un responsable du
     * magasin reconnaissent un poste, depuis les paramètres du magasin.
     */
    public function test_le_mot_de_passe_egal_a_l_identifiant_n_ouvre_le_compte_que_depuis_un_poste_reconnu_du_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        [$vendeur, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $prudent = $this->membre($magasin, RoleMagasin::Vendeur);
        $posteDAilleurs = $this->reconnaitreUnPoste($autre);
        $responsable = $this->responsable($magasin);

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListPostesReconnus::class)->callAction('reconnaitre', ['nom' => 'Caisse 1'])->assertHasNoActionErrors();
        $poste = [PostesReconnus::TEMOIN => (string) Cookie::queued(PostesReconnus::TEMOIN)?->getValue()];
        $reconnu = PosteReconnu::query()->sole();
        $this->assertSame(['Caisse 1', (int) $responsable->id], [$reconnu->nom, (int) $reconnu->reconnu_par]);

        foreach ([[], $posteDAilleurs] as $temoin) {
            $this->assertSame([Connexion::MESSAGE_ECHEC.' '.Connexion::MESSAGE_POSTE], $this->seConnecter($identifiant, $identifiant, postes: $temoin)->errors()->get('data.login'));
            $this->assertGuest();
        }
        $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
        $this->seConnecter((string) $prudent->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($prudent);

        app(PostesReconnus::class)->retirer($this->proprietaireDe($magasin), $reconnu);
        $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasErrors('data.login');
        $this->assertGuest();

        $this->dansLeMagasin($magasin, $prudent);
        $this->get(PosteReconnuResource::getUrl('index', tenant: $magasin))->assertForbidden();
        $this->assertThrows(fn () => app(PostesReconnus::class)->reconnaitre($prudent, $magasin, 'Mon téléphone', request()), AuthorizationException::class);
        $this->assertThrows(fn () => app(PostesReconnus::class)->reconnaitre($this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes()), $magasin, 'Poste', request()), AuthorizationException::class);
        $this->assertSame(2, PosteReconnu::withoutGlobalScopes()->count());
    }

    /**
     * Un responsable, un administrateur ou un expert-comptable dont le mot
     * de passe est encore son identifiant choisit le sien à sa première
     * connexion, avant toute autre page, et aucune requête Livewire d'un
     * autre écran ne passe ; un vendeur garde son identifiant pour mot de
     * passe tant qu'il le veut.
     */
    public function test_un_responsable_un_administrateur_ou_un_expert_comptable_choisit_son_mot_de_passe_a_sa_premiere_connexion(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $poste = $this->reconnaitreUnPoste($magasin);
        $comptes = [
            $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Responsable)[0],
            app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alice Admin'], null, PermissionRegistry::modeleDeLAdministrateur(), []),
            app(CreerExpertComptableAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Cabinet Durand']),
        ];

        foreach ($comptes as $compte) {
            $identifiant = (string) $compte->identifiantDeConnexion();
            $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasNoErrors();
            $this->assertAuthenticatedAs($compte);
            $this->assertTrue($compte->refresh()->doitChoisirSonMotDePasse(), $compte->name);

            $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertRedirect(route('filament.app.choisir-son-mot-de-passe'));
            Livewire::test(ChoisirSonMotDePasse::class)
                ->fillForm(['password' => $identifiant, 'passwordConfirmation' => $identifiant])
                ->call('choisir')
                ->assertHasErrors(['data.password']);
            Livewire::test(ChoisirSonMotDePasse::class)
                ->fillForm(['password' => self::MOT_DE_PASSE_CHOISI, 'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI])
                ->call('choisir')
                ->assertHasNoErrors();

            $this->assertFalse($compte->refresh()->mot_de_passe_est_l_identifiant, $compte->name);
            $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
        }

        [$vendeur, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasNoErrors();
        $this->assertFalse($vendeur->refresh()->doitChoisirSonMotDePasse());
        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
    }

    /**
     * Un vendeur promu responsable de son magasin alors que son mot de
     * passe est encore son identifiant choisit le sien à sa connexion
     * suivante : sa session déjà ouverte continue sans interruption, pages
     * et requêtes Livewire comprises ; à sa connexion suivante, il n'atteint
     * plus aucune page avant d'avoir choisi son mot de passe, et une
     * requête Livewire d'un écran resté ouvert est refusée.
     */
    public function test_un_compte_promu_choisit_son_mot_de_passe_a_sa_connexion_suivante(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $poste = $this->reconnaitreUnPoste($magasin);
        [$vendeur, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasNoErrors();
        $instantane = $this->instantaneLivewire($this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk()->getContent(), 'list-factures');

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, RoleMagasin::Responsable);

        $this->assertTrue($vendeur->refresh()->doitChoisirSonMotDePasse());
        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
        $this->requeteLivewire($instantane, [], 'resetTableSearch')->assertOk();
        $this->flushHeaders();

        $this->seConnecter($identifiant, $identifiant, postes: $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertRedirect(route('filament.app.choisir-son-mot-de-passe'));
        $this->requeteLivewire($instantane, [], 'resetTableSearch')->assertForbidden();
        $this->flushHeaders();
        Livewire::test(ChoisirSonMotDePasse::class)
            ->fillForm(['password' => self::MOT_DE_PASSE_CHOISI, 'passwordConfirmation' => self::MOT_DE_PASSE_CHOISI])
            ->call('choisir')
            ->assertHasNoErrors()
            ->assertRedirect();
        $this->get(FactureResource::getUrl('index', tenant: $magasin))->assertOk();
    }

    /**
     * Une connexion au panneau, sans session ouverte, avec ces témoins de
     * poste.
     *
     * @param  array<string, string>  $postes
     */
    private function seConnecter(string $saisie, string $motDePasse, string $panneau = 'app', array $postes = [], bool $seSouvenir = false): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel($panneau));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => '', ...$postes])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse, 'remember' => $seSouvenir])
            ->call('authenticate');
    }

    /**
     * Le témoin d'un poste que le propriétaire vient de reconnaître dans
     * ce magasin.
     *
     * @return array<string, string>
     */
    private function reconnaitreUnPoste(Magasin $magasin): array
    {
        app(PostesReconnus::class)->reconnaitre($this->proprietaireDe($magasin), $magasin, 'Caisse 1', request());

        return [PostesReconnus::TEMOIN => (string) Cookie::queued(PostesReconnus::TEMOIN)?->getValue()];
    }

    /**
     * Un identifiant de la bonne forme qu'aucun compte n'a.
     */
    private function identifiantInconnu(string $premier = '4'): string
    {
        do {
            $identifiant = $premier.str_pad((string) random_int(0, 9_999_999), 7, '0', STR_PAD_LEFT);
        } while (Identifiant::withoutGlobalScopes()->where('identifiant', $identifiant)->exists());

        return $identifiant;
    }

    /**
     * Le message de la connexion refusée que porte la réponse d'une vraie
     * requête Livewire ; null sans erreur.
     */
    private function erreurDeConnexion(TestResponse $reponse): ?string
    {
        $instantane = json_decode((string) $reponse->json('components.0.snapshot'), true);

        return $instantane['memo']['errors']['data.login'][0] ?? null;
    }

    /**
     * Compte les vérifications de hachage que fait la connexion, en
     * remplaçant le hacheur de l'application par un hacheur qui les compte
     * et délègue au vrai.
     */
    private function compterLesVerifications(): object
    {
        $hacheur = new class(app('hash')) implements Hasher
        {
            public int $verifications = 0;

            public int $creations = 0;

            /**
             * @var list<string>
             */
            public array $empreintes = [];

            public function __construct(private readonly Hasher $vrai) {}

            public function info($hashedValue): array
            {
                return $this->vrai->info($hashedValue);
            }

            public function make($value, array $options = []): string
            {
                $this->creations++;

                return $this->vrai->make($value, $options);
            }

            public function check($value, $hashedValue, array $options = []): bool
            {
                $this->verifications++;
                $this->empreintes[] = (string) $hashedValue;

                return $this->vrai->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->vrai->needsRehash($hashedValue, $options);
            }

            public function isHashed($value): bool
            {
                return $this->vrai->isHashed($value);
            }
        };
        $this->app->instance('hash', $hacheur);
        Auth::forgetGuards();

        return $hacheur;
    }
}
