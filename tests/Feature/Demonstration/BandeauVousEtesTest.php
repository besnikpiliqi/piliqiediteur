<?php

namespace Tests\Feature\Demonstration;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\Demonstration\Actions\ChangerDeCompteAction;
use App\Domain\Demonstration\Archives\FinDesCopies;
use App\Domain\Demonstration\Registre\Models\Copie;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\Visites\OuvertureDUneCopie;
use App\Domain\Demonstration\Visites\TemoinsDesVisites;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Caisse;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\DemonstrationTestCase;

/**
 * Le bandeau « Vous êtes : » et « Choisissez qui vous êtes » (lot
 * DEMO-COPIE, C.8, N20) : seulement les comptes de la société de la copie
 * (jamais un compte d'un client dans la copie d'un cabinet) ; un clic
 * change de compte sans mot de passe, par le vrai chemin d'une connexion
 * (fin de session JET 40 dans le magasin ouvert, ouverture JET 80 au
 * premier accès du compte suivant, entrée au journal d'audit de la société
 * fictive) ; un compte forgé, d'une autre société, retiré ou suspendu est
 * refusé, en français ; « Quitter » retire le témoin.
 */
class BandeauVousEtesTest extends DemonstrationTestCase
{
    public function test_choisissez_qui_vous_etes_montre_les_seuls_comptes_de_la_societe_le_proprietaire_en_premier(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);

        $page = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir');

        $page->assertOk()
            ->assertSeeInOrder(['Commencer en tant que propriétaire', 'La société', 'Propriétaire', 'Les Halles', 'Camille Laurent', 'Inès Garcia', 'La République', 'Sarah Petit'])
            ->assertSee('K7M2QD')
            ->assertSee('Mot de passe de démonstration')
            ->assertSee((string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe)
            ->assertSee('Ne saisissez pas de vraies données de vos clients')
            ->assertSee('changez le régime de TVA dans les paramètres fiscaux de la société');
    }

    public function test_la_copie_d_un_cabinet_ne_montre_jamais_un_compte_d_un_client_et_refuse_d_y_passer(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Cabinet);
        $temoin = $this->entrerParLeLien($secret);
        $client = $this->dansLaCopie($copie, fn (): User => User::query()->whereHas('societe', fn ($societe) => $societe->where('siren', '000000125'))->firstOrFail());

        $page = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir');
        $page->assertOk()->assertSee('Claire Martin')->assertSee('Paul Lefèvre')->assertSee('Le cabinet', false)
            ->assertDontSee($client->name)
            ->assertDontSee('changez le régime de TVA');

        $refus = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $client->getKey()]);
        $refus->assertRedirect('/choisir');
        $this->assertSame(ChangerDeCompteAction::REFUS_COMPTE_INCONNU, self::refusDuChangement($refus));
        $this->assertGuest();
    }

    public function test_un_clic_change_de_compte_sans_mot_de_passe_par_le_vrai_chemin_d_une_connexion(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $vendeuse = $this->compte($copie, 'Inès Garcia');
        $responsable = $this->compte($copie, 'Camille Laurent');

        // Chaque requête emporte, comme un navigateur, le témoin de la copie
        // et la session que la réponse précédente a posée : la session de
        // la vendeuse, lue dans la base de la copie, dit le magasin qu'elle
        // a ouvert, que le changement de compte ferme (JET 40).
        $reponse = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $vendeuse->getKey()]);
        $reponse->assertRedirect();
        $this->assertAuthenticatedAs($vendeuse);
        $this->garderLaSession($reponse);
        $accueil = (string) $reponse->headers->get('Location');
        $panneau = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->followingRedirects()->get($accueil);
        $panneau->assertOk()->assertSee('data-bandeau-de-la-demonstration', false)->assertSee('Vous êtes : <strong>Inès Garcia</strong>', false);
        $this->garderLaSession($panneau);

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $responsable->getKey()])->assertRedirect();
        $this->assertAuthenticatedAs($responsable);

        $evenements = $this->dansLaCopie($copie, fn (): array => DB::table('enregistrements_fiscaux')
            ->whereIn('type', [(string) CodeEvenement::OuvertureDeSession->value, (string) CodeEvenement::FinDeSession->value])
            ->where('operateur_id', $vendeuse->getKey())
            ->orderBy('id')
            ->pluck('type')
            ->map(fn (mixed $type): int => (int) $type)
            ->all());
        $this->assertSame([CodeEvenement::OuvertureDeSession->value, CodeEvenement::FinDeSession->value], $evenements, 'La vendeuse ouvre sa session dans son magasin (JET 80), puis le changement de compte la ferme (JET 40).');

        $audit = $this->dansLaCopie($copie, fn (): array => DB::table('journal_audit')->where('action', ActionAuditee::ConnexionDepuisLeBandeau->value)->orderBy('id')->pluck('auteur_id')->map(fn (mixed $id): int => (int) $id)->all());
        $this->assertSame([(int) $vendeuse->getKey(), (int) $responsable->getKey()], $audit, 'Chaque changement s\'inscrit au journal d\'audit de la société fictive, comme une connexion.');
        $this->assertSame((int) $responsable->getKey(), Temoin::on('demo_admin')->where('copie_id', $copie->id)->whereNull('retire_le')->sole()->compte_choisi);
    }

    public function test_un_compte_forge_retire_ou_suspendu_est_refuse_en_francais(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $observatrice = $this->compte($copie, 'Élodie Fabre');
        $vendeuse = $this->compte($copie, 'Sarah Petit');
        $this->dansLaCopie($copie, function () use ($observatrice, $vendeuse): void {
            User::query()->whereKey($observatrice->getKey())->update(['statut' => StatutCompte::Suspendu->value]);
            User::query()->whereKey($vendeuse->getKey())->update(['statut' => StatutCompte::Retire->value]);
        });

        foreach ([999999 => ChangerDeCompteAction::REFUS_COMPTE_INCONNU, $vendeuse->getKey() => ChangerDeCompteAction::REFUS_COMPTE_INCONNU, $observatrice->getKey() => ChangerDeCompteAction::REFUS_COMPTE_SUSPENDU] as $compte => $refus) {
            $reponse = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $compte]);
            $reponse->assertRedirect('/choisir');
            $this->assertSame($refus, self::refusDuChangement($reponse));
            $this->assertGuest();
        }

        $page = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir');
        $page->assertSee('Élodie Fabre')->assertSee('(compte suspendu)')->assertDontSee('Sarah Petit');
    }

    public function test_un_compte_cree_dans_la_copie_entre_au_bandeau_a_la_fin_de_son_groupe(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);

        $cree = $this->dansLaCopie($copie, function (): User {
            $halles = Magasin::query()->withoutGlobalScopes()->where('nom', 'Les Halles')->sole();
            $proprietaire = User::query()->where('societe_id', $halles->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->sole();

            return app(TenantContext::class)->runAs($halles, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $halles, ['name' => 'Nora Benoît'], RoleMagasin::Vendeur));
        });

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertOk()
            ->assertSeeInOrder(['Les Halles', 'Élodie Fabre', 'Nora Benoît', 'Vendeur', 'La République', 'Sarah Petit']);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $cree->getKey()])->assertRedirect();
        $this->assertAuthenticatedAs($cree);
    }

    public function test_sans_temoin_le_bandeau_et_ses_pages_repondent_introuvable_et_quitter_retire_le_temoin(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $responsable = $this->compte($copie, 'Claire Dubois');

        foreach (['/choisir', '/demonstration/courriels'] as $adresse) {
            $this->get($adresse)->assertNotFound();
        }
        $this->post('/demonstration/changer-de-compte', ['compte' => $responsable->getKey()])->assertNotFound();

        $temoin = $this->entrerParLeLien($secret);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $responsable->getKey()])->assertRedirect();
        $quitter = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/quitter');

        $quitter->assertOk()->assertSee('Vous avez quitté la démonstration')->assertSee('Votre lien marche toujours jusqu\'au', false);
        $this->assertGuest();
        $this->assertNotNull(Temoin::on('demo_admin')->where('copie_id', $copie->id)->sole()->retire_le);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')->assertNotFound();
    }

    public function test_bonjour_dit_le_role_choisi_suivi_de_demonstration_et_la_visite_suivante_rouvre_ce_compte(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $vendeuse = $this->compte($copie, 'Inès Garcia');

        $accueil = (string) $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $vendeuse->getKey()])->headers->get('Location');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->followingRedirects()->get($accueil)
            ->assertOk()->assertSee('Bonjour Inès Garcia')->assertSee('Les Halles (démonstration)');

        $this->app['auth']->forgetGuards();
        $this->assertGuest();
        $rouverte = $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir');
        $rouverte->assertRedirect($accueil);
        $this->assertAuthenticatedAs($vendeuse);

        $deNouveauParLeLien = $this->withCredentials()->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)
            ->withHeaders(['Origin' => rtrim((string) config('app.url'), '/')])->postJson('/entrer', ['secret' => $secret]);
        $deNouveauParLeLien->assertOk()->assertJson(['aller' => '/choisir']);
        $this->assertNull($deNouveauParLeLien->getCookie(TemoinsDesVisites::nom(), false), 'Le navigateur garde son témoin de cette copie : la visite suivante rouvre le compte choisi.');
        $this->assertSame(1, Temoin::on('demo_admin')->where('copie_id', $copie->id)->count());

        $this->dansLaCopie($copie, fn () => User::query()->whereKey($vendeuse->getKey())->update(['statut' => StatutCompte::Suspendu->value]));
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')->assertOk()->assertSee('Choisissez qui vous êtes');
        $this->assertGuest();
        $this->assertNull(Temoin::on('demo_admin')->where('copie_id', $copie->id)->sole()->compte_choisi, 'Un compte suspendu depuis ne se rouvre pas : le choix est effacé.');
    }

    public function test_le_message_de_la_copie_ne_se_montre_qu_une_fois_par_temoin(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Services);
        $message = FinDesCopies::message(FinDesCopies::MOTIF_MISE_A_JOUR, self::aParis('2026-11-20 10:00:00'));
        Copie::on('demo_admin')->whereKey($copie->id)->update(['message' => $message, 'motif_du_message' => FinDesCopies::MOTIF_MISE_A_JOUR]);
        $premier = $this->entrerParLeLien($secret);
        $second = $this->entrerParLeLien($secret);

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $premier)->get('/choisir')->assertOk()
            ->assertSee('PILIQI a été mis à jour le 20/11/2026. Votre démonstration repart de la société fictive de départ, avec les nouveautés. Votre lien reste le même.');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $premier)->get('/choisir')->assertOk()->assertDontSee('PILIQI a été mis à jour');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $second)->get('/choisir')->assertOk()->assertSee('PILIQI a été mis à jour le 20/11/2026');
    }

    public function test_les_regles_du_vrai_logiciel_tiennent_pour_chaque_compte_choisi(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $motDePasse = (string) Copie::on('demo_admin')->whereKey($copie->id)->sole()->modele?->mot_de_passe;
        [$proprietaire, $halles, $caisse] = $this->dansLaCopie($copie, function (): array {
            $halles = Magasin::query()->withoutGlobalScopes()->where('nom', 'Les Halles')->sole();

            return [
                User::query()->where('societe_id', $halles->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->sole(),
                $halles,
                Caisse::getUrl(panel: 'app', tenant: $halles),
            ];
        });

        foreach (['Inès Garcia' => 200, 'Élodie Fabre' => 403] as $nom => $statut) {
            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $this->compte($copie, $nom)->getKey()])->assertRedirect();
            $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get($caisse)->assertStatus($statut);
        }

        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $proprietaire->getKey()])->assertRedirect();
        $this->assertAuthenticatedAs($proprietaire);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get($caisse)->assertForbidden();

        $this->dansLaCopie($copie, function () use ($proprietaire, $halles, $motDePasse): void {
            try {
                app(FermerMagasinAction::class)->handle($proprietaire, $halles, MotifFermeture::Temporaire, 'pas-le-bon-mot-de-passe');
                $this->fail('Un geste qui redemande le mot de passe refuse un faux mot de passe, dans la copie comme au réel.');
            } catch (LogicException) {
                $this->assertFalse($halles->fresh()?->estFerme());
            }

            app(FermerMagasinAction::class)->handle($proprietaire, $halles, MotifFermeture::Temporaire, $motDePasse);
            $this->assertTrue($halles->fresh()?->estFerme(), 'Le mot de passe de démonstration du modèle est celui de chaque compte du bandeau.');
        });
    }

    public function test_chaque_role_autorise_cree_des_comptes_comme_en_vrai_et_un_role_non_autorise_est_refuse(): void
    {
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $responsable = $this->compte($copie, 'Camille Laurent');
        $vendeuse = $this->compte($copie, 'Inès Garcia');

        [$cree, $administrateur] = $this->dansLaCopie($copie, function () use ($responsable, $vendeuse): array {
            $halles = Magasin::query()->withoutGlobalScopes()->where('nom', 'Les Halles')->sole();
            $societe = $halles->societe()->sole();
            $proprietaire = User::query()->where('societe_id', $societe->getKey())->where('role_societe', RoleSociete::Proprietaire->value)->sole();
            $contexte = app(TenantContext::class);

            try {
                $contexte->runAs($halles, fn (): User => app(CreerMembreAction::class)->handle($vendeuse, $halles, ['name' => 'Refusé Toujours'], RoleMagasin::Vendeur));
                $this->fail('Une vendeuse ne crée aucun compte, dans la copie comme au réel.');
            } catch (AuthorizationException) {
                $this->assertFalse(User::query()->where('name', 'Refusé Toujours')->exists());
            }

            return [
                $contexte->runAs($halles, fn (): User => app(CreerMembreAction::class)->handle($responsable, $halles, ['name' => 'Léo Martin'], RoleMagasin::Vendeur)),
                $contexte->runAsSociete($societe, fn (): User => app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Agathe Roux'], null, [], [])),
            ];
        });

        $identifiant = $this->dansLaCopie($copie, fn (): ?string => $cree->fresh()?->identifiantDeConnexion());
        $this->assertMatchesRegularExpression('/^\d{8}$/', (string) $identifiant, 'Le compte créé reçoit son identifiant de 8 chiffres, tiré dans la copie.');
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->get('/choisir')
            ->assertOk()
            ->assertSeeInOrder(['La société', 'Agathe Roux', 'Les Halles', 'Léo Martin', 'La République']);
        $this->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)->post('/demonstration/changer-de-compte', ['compte' => $administrateur->getKey()])->assertRedirect();
        $this->assertAuthenticatedAs($administrateur);
    }

    /**
     * Le refus que la redirection vers « Choisissez qui vous êtes » emporte
     * dans la session de sa requête, que la page suivante affiche.
     *
     * @param  TestResponse<Response>  $reponse
     */
    private static function refusDuChangement(TestResponse $reponse): mixed
    {
        $redirection = $reponse->baseResponse;

        return $redirection instanceof RedirectResponse ? $redirection->getSession()?->get('erreur_du_bandeau') : null;
    }

    public function test_ni_adresse_ip_ni_navigateur_ne_s_enregistrent_dans_une_copie(): void
    {
        $this->travelTo(self::aParis('2026-11-20 10:00:00'));
        ['copie' => $copie, 'secret' => $secret] = $this->copieDeTest(TypeDActivite::Commerce);
        $temoin = $this->entrerParLeLien($secret);
        $vendeuse = $this->compte($copie, 'Inès Garcia');
        $navigateur = ['User-Agent' => 'Navigateur de la démonstration', 'REMOTE_ADDR' => '203.0.113.7'];

        $reponse = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders($navigateur)->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)
            ->post('/demonstration/changer-de-compte', ['compte' => $vendeuse->getKey()]);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->withHeaders($navigateur)->withUnencryptedCookie(TemoinsDesVisites::nom(), $temoin)
            ->followingRedirects()->get((string) $reponse->headers->get('Location'))->assertOk();

        $traces = $this->dansLaCopie($copie, fn (): array => [
            'audit' => DB::table('journal_audit')->where('action', ActionAuditee::ConnexionDepuisLeBandeau->value)->count(),
            'jet' => DB::table('enregistrements_fiscaux')->where('type', (string) CodeEvenement::OuvertureDeSession->value)->where('operateur_id', $vendeuse->getKey())->count(),
            'traces_audit' => DB::table('traces_audit')->count(),
            'traces_evenements' => DB::table('traces_evenements')->count(),
            'sessions_tracees' => DB::table('sessions')->where(fn ($session) => $session->whereNotNull('ip_address')->orWhereNotNull('user_agent'))->count(),
            'sessions' => DB::table('sessions')->count(),
        ]);

        $this->assertSame(1, $traces['audit'], 'La connexion depuis le bandeau est au journal d\'audit…');
        $this->assertSame(1, $traces['jet'], '… et l\'ouverture de session au journal des événements…');
        $this->assertSame(0, $traces['traces_audit'], '… sans adresse IP ni navigateur (C.17).');
        $this->assertSame(0, $traces['traces_evenements']);
        $this->assertGreaterThan(0, $traces['sessions'], 'Les sessions de la copie sont dans sa base…');
        $this->assertSame(0, $traces['sessions_tracees'], '… sans adresse IP ni navigateur.');
    }

    /**
     * Les requêtes suivantes du test emportent la session que cette
     * réponse a posée, comme le ferait un navigateur.
     *
     * @param  TestResponse<Response>  $reponse
     */
    private function garderLaSession(TestResponse $reponse): void
    {
        $session = $reponse->getCookie((string) config('session.cookie'));

        if ($session !== null) {
            $this->withCookie((string) config('session.cookie'), (string) $session->getValue());
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $geste
     * @return T
     */
    private function dansLaCopie(Copie $copie, Closure $geste): mixed
    {
        return app(OuvertureDUneCopie::class)->pendant($copie, $geste);
    }

    private function compte(Copie $copie, string $nom): User
    {
        return $this->dansLaCopie($copie, fn (): User => User::query()->where('name', $nom)->sole());
    }
}
