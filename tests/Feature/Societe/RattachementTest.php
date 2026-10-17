<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\AccepterUnRattachementAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Actions\CreerSaSocieteAvecUnCompteRetireAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\ProposerUnRattachementAction;
use App\Domain\Tenancy\Actions\RefuserSonCompteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Mail\PropositionDeRattachementMail;
use App\Domain\Tenancy\Mail\RattachementImpossibleMail;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\DemandeRattachement;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un compte n'appartient qu'à une seule société : retiré par elle, ou par
 * son propre refus, il en rejoint une autre (ou revient dans la même) par
 * une proposition qu'il accepte en redonnant l'adresse et le mot de passe
 * du compte, ou crée sa propre société avec lui ; jamais avant. La
 * proposition suit les règles d'une création, l'acceptation revérifie
 * tout sous verrou, et rien ne dit à l'auteur ce que désigne l'adresse.
 */
class RattachementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_un_compte_retire_rejoint_une_autre_societe_avec_son_accord_et_son_mot_de_passe(): void
    {
        $compte = $this->compteRetireDUneAutreSociete('paul@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        app(ProposerUnRattachementAction::class)->handle($proprietaire, $magasin->societe, ' PAUL@example.test ', RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]]]);
        $jeton = $this->jetonProposeA('paul@example.test');

        $this->get(route('rattachement.afficher', ['jeton' => $jeton]))
            ->assertOk()
            ->assertSeeText($magasin->societe->getFilamentName())
            ->assertSeeText('vendeur du magasin Nouvelle Boutique');
        $this->assertSame(StatutCompte::Retire, $compte->refresh()->statut);

        $this->post(route('rattachement.accepter', ['jeton' => $jeton]), ['email' => 'paul@example.test', 'password' => self::MOT_DE_PASSE_CHOISI])
            ->assertRedirect(route('filament.app.tenant'));

        $compte->refresh();
        $this->assertAuthenticatedAs($compte);
        $this->assertSame([(int) $magasin->societe_id, RoleSociete::Membre, StatutCompte::Actif, (int) $proprietaire->id], [(int) $compte->societe_id, $compte->role_societe, $compte->statut, (int) $compte->statut_change_par]);
        $this->assertTrue($compte->aConfirmeSonAdresse());
        $this->assertTrue($compte->peutOuvrir($magasin));
        $affectation = Affectation::query()->where('user_id', $compte->id)->sole();
        $this->assertSame([(int) $magasin->id, RoleMagasin::Vendeur], [(int) $affectation->magasin_id, $affectation->role]);
        $this->assertEqualsCanonicalizing([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE], $affectation->autorisations());
        $jet = collect($this->modificationsDesDroits($magasin))->firstWhere('membre.id', $compte->id);
        $this->assertSame([null, RoleMagasin::Vendeur->value], [$jet['avant'], $jet['apres']['role_magasin']]);
        $this->assertNotNull(DemandeRattachement::withoutGlobalScopes()->sole()->acceptee_le);
    }

    /**
     * Un compte encore actif ou suspendu dans sa société n'est jamais
     * rattaché : la personne apprend seule qu'elle doit d'abord en être
     * retirée, et une proposition faite pendant son retrait ne sert plus
     * s'il a été réintégré depuis.
     */
    public function test_un_compte_encore_rattache_ailleurs_ne_peut_pas_etre_rattache(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $actif = $this->membre($ailleurs, RoleMagasin::Vendeur, null, ['email' => 'actif@example.test']);
        $suspendu = $this->membre($ailleurs, RoleMagasin::Vendeur, null, ['email' => 'suspendu@example.test']);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($ailleurs), $suspendu);
        $reintegre = $this->compteRetire($ailleurs, 'reintegre@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        foreach (['actif@example.test', 'suspendu@example.test'] as $adresse) {
            $this->proposerCommeVendeur($proprietaire, $magasin, $adresse);
            Mail::assertSent(RattachementImpossibleMail::class, fn (RattachementImpossibleMail $mail): bool => $mail->hasTo($adresse));
        }
        Mail::assertNotSent(PropositionDeRattachementMail::class);
        $this->assertSame(0, DemandeRattachement::withoutGlobalScopes()->count());

        $this->proposerCommeVendeur($proprietaire, $magasin, 'reintegre@example.test');
        $jeton = $this->jetonProposeA('reintegre@example.test');
        app(TenantContext::class)->runAs($ailleurs, fn () => app(ChangerStatutCompteAction::class)->reintegrer($this->proprietaireDe($ailleurs), $reintegre, [$ailleurs->id => ['role' => RoleMagasin::Vendeur]]));

        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($jeton, 'reintegre@example.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_COMPTE_PAS_LIBRE);
        foreach ([$actif, $suspendu, $reintegre] as $compte) {
            $this->assertSame((int) $ailleurs->societe_id, (int) $compte->refresh()->societe_id);
        }
        $this->assertSame(0, Affectation::query()->where('magasin_id', $magasin->id)->count());
    }

    /**
     * Adresse inconnue, compte encore actif ailleurs ou compte retiré :
     * l'écran répond la même chose à l'administrateur qui propose, et le
     * journal d'audit ne retient que l'adresse tapée.
     */
    public function test_la_reponse_a_l_auteur_ne_revele_jamais_l_etat_du_compte(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $this->membre($ailleurs, RoleMagasin::Vendeur, null, ['email' => 'actif@example.test']);
        $this->compteRetire($ailleurs, 'retire@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]));

        foreach (['inconnu@example.test', 'actif@example.test', 'retire@example.test'] as $adresse) {
            Livewire::test(ListMembres::class)
                ->callAction('proposerUnRattachement', ['email' => $adresse, 'magasin_id' => $magasin->id])
                ->assertHasNoActionErrors()
                ->assertNotified(ProposerUnRattachementAction::REPONSE);
        }

        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::RattachementPropose);
        $this->assertSame(['inconnu@example.test', 'actif@example.test', 'retire@example.test'], array_map(fn ($entree): string => $entree->nouvelles_valeurs['compte']['email'], $entrees));
        foreach ($entrees as $entree) {
            $this->assertSame(['email'], array_keys($entree->nouvelles_valeurs['compte']));
        }
    }

    /**
     * L'acceptation relit l'auteur et les magasins proposés : un auteur
     * suspendu entre-temps, ou un magasin sorti de sa portée, ne rattache
     * plus personne.
     */
    /**
     * Une société ne fait pas de PILIQI un relais d'envoi vers les comptes
     * d'une autre : chaque proposition compte au débit des e-mails de son
     * auteur, qu'un e-mail parte ou non, et la limite atteinte se dit de la
     * même façon pour toute adresse, sans rien écrire ni envoyer ; elle se
     * lève au bout d'une heure.
     */
    public function test_un_auteur_propose_au_plus_60_rattachements_par_heure_quelle_que_soit_l_adresse(): void
    {
        $this->travelTo('2026-10-15 10:00:00');
        $this->compteRetireDUneAutreSociete('paul@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        foreach (range(1, DebitDesEmails::PAR_HEURE_ET_PAR_PERSONNE) as $numero) {
            $this->proposerCommeVendeur($proprietaire, $magasin, "inconnu{$numero}@example.test");
        }

        $this->assertThrows(fn () => $this->proposerCommeVendeur($proprietaire, $magasin, 'paul@example.test'), LogicException::class, 'Trop d\'e-mails envoyés en une heure : réessayez dans 60 minute(s).');
        $this->assertSame(0, DemandeRattachement::withoutGlobalScopes()->count());
        Mail::assertNothingOutgoing();

        $this->travelTo('2026-10-15 11:00:01');
        $this->proposerCommeVendeur($proprietaire, $magasin, 'paul@example.test');

        Mail::assertSent(PropositionDeRattachementMail::class, 1);
    }

    /**
     * Une raison sociale ou un nom écrits en Markdown arrivent tels quels
     * dans les e-mails de rattachement : jamais un lien cliquable, envoyé
     * depuis le domaine de l'éditeur, qui mènerait ailleurs que ce qu'il
     * annonce.
     */
    public function test_les_noms_affiches_par_les_e_mails_de_rattachement_ne_sont_jamais_mis_en_forme(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $this->membre($ailleurs, RoleMagasin::Vendeur, null, ['email' => 'actif@example.test']);
        $this->compteRetire($ailleurs, 'retire@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $magasin->societe->forceFill(['raison_sociale' => 'ACME [Votre compte est bloqué](https://pirate.example/connexion)'])->save();
        $proprietaire = tap($this->proprietaireDe($magasin)->forceFill(['name' => 'Paul [Confirmez votre identité](https://pirate.example/identite)']))->save();

        $this->proposerCommeVendeur($proprietaire, $magasin, 'actif@example.test');
        $this->proposerCommeVendeur($proprietaire, $magasin, 'retire@example.test');

        $rendus = [
            Mail::sent(RattachementImpossibleMail::class)->sole()->render(),
            Mail::sent(PropositionDeRattachementMail::class)->sole()->render(),
        ];
        foreach ($rendus as $html) {
            $this->assertStringNotContainsString('href="https://pirate.example', $html);
            $this->assertStringContainsString('ACME [Votre compte est bloqué](https://pirate.example/connexion)', $html);
        }
        $this->assertStringContainsString('Paul [Confirmez votre identité](https://pirate.example/identite)', $rendus[1]);
    }

    public function test_l_acceptation_reverifie_l_auteur_et_les_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, [$magasin->id, $autre->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $suspendu = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $premier = $this->compteRetireDUneAutreSociete('premier@example.test');
        $second = $this->compteRetireDUneAutreSociete('second@example.test');

        app(ProposerUnRattachementAction::class)->handle($administrateur, $magasin->societe, 'premier@example.test', RoleSociete::Membre, [$autre->id => ['role' => RoleMagasin::Responsable]]);
        app(ProposerUnRattachementAction::class)->handle($suspendu, $magasin->societe, 'second@example.test', RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierAdministrateurAction::class)->modifier($proprietaire, $administrateur, [$magasin->id], PermissionRegistry::modeleDeLAdministrateur(), [AutorisationsSociete::RESPONSABLES_NOMMER]);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);

        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($this->jetonProposeA('premier@example.test'), 'premier@example.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_AUTEUR);
        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($this->jetonProposeA('second@example.test'), 'second@example.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_AUTEUR);

        foreach ([$premier, $second] as $compte) {
            $this->assertSame(StatutCompte::Retire, $compte->refresh()->statut);
            $this->assertNotSame((int) $magasin->societe_id, (int) $compte->societe_id);
        }
        $this->assertSame(0, Affectation::query()->where('role', RoleMagasin::Responsable->value)->count());
    }

    public function test_le_lien_expire_et_ne_sert_qu_une_fois(): void
    {
        $this->compteRetireDUneAutreSociete('expire@example.test');
        $this->compteRetireDUneAutreSociete('servi@example.test');
        $this->compteRetireDUneAutreSociete('refuse@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        foreach (['expire@example.test', 'servi@example.test', 'refuse@example.test'] as $adresse) {
            $this->proposerCommeVendeur($proprietaire, $magasin, $adresse);
        }
        $servi = $this->jetonProposeA('servi@example.test');
        $refuse = $this->jetonProposeA('refuse@example.test');
        $expire = $this->jetonProposeA('expire@example.test');

        app(AccepterUnRattachementAction::class)->handle($servi, 'servi@example.test', self::MOT_DE_PASSE_CHOISI);
        $this->post(route('rattachement.refuser', ['jeton' => $refuse]))->assertOk()->assertSeeText('Votre refus est enregistré');

        $this->get(route('rattachement.afficher', ['jeton' => $servi]))->assertStatus(410);
        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($servi, 'servi@example.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_LIEN_INVALIDE);
        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($refuse, 'refuse@example.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_LIEN_INVALIDE);
        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->refuser($refuse), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_LIEN_INVALIDE);

        $this->travel(DemandeRattachement::DUREE_DE_VALIDITE_EN_JOURS)->days();
        $this->travel(1)->minute();

        $this->get(route('rattachement.afficher', ['jeton' => $expire]))->assertStatus(410)->assertSeeText(AccepterUnRattachementAction::MESSAGE_LIEN_INVALIDE);
        $this->post(route('rattachement.accepter', ['jeton' => $expire]), ['email' => 'expire@example.test', 'password' => self::MOT_DE_PASSE_CHOISI])->assertSeeText(AccepterUnRattachementAction::MESSAGE_LIEN_INVALIDE);
        $this->assertGuest();
        $this->assertSame(StatutCompte::Retire, User::query()->where('email', 'expire@example.test')->sole()->statut);
        $this->assertSame(StatutCompte::Retire, User::query()->where('email', 'refuse@example.test')->sole()->statut);
    }

    /**
     * Une nouvelle proposition de la même société remplace la précédente ;
     * accepter annule celles des autres sociétés, dont le lien ne sert
     * plus.
     */
    public function test_accepter_annule_les_autres_demandes(): void
    {
        $compte = $this->compteRetireDUneAutreSociete('paul@example.test');
        $premiere = $this->ouvrirUneSociete(['nom' => 'Première']);
        $seconde = $this->ouvrirUneSociete(['nom' => 'Seconde']);

        $this->proposerCommeVendeur($this->proprietaireDe($premiere), $premiere, 'paul@example.test');
        $remplacee = $this->jetonProposeA('paul@example.test');
        Mail::fake();
        $this->proposerCommeVendeur($this->proprietaireDe($premiere), $premiere, 'paul@example.test');
        $acceptee = $this->jetonProposeA('paul@example.test');
        Mail::fake();
        $this->proposerCommeVendeur($this->proprietaireDe($seconde), $seconde, 'paul@example.test');
        $annulee = $this->jetonProposeA('paul@example.test');

        $this->assertNull(AccepterUnRattachementAction::demandeEnAttente($remplacee));
        app(AccepterUnRattachementAction::class)->handle($acceptee, 'paul@example.test', self::MOT_DE_PASSE_CHOISI);

        $this->assertNull(AccepterUnRattachementAction::demandeEnAttente($annulee));
        $this->assertNotNull(DemandeRattachement::withoutGlobalScopes()->where('societe_id', $seconde->societe_id)->sole()->annulee_le);
        $this->assertSame((int) $premiere->societe_id, (int) $compte->refresh()->societe_id);
    }

    /**
     * La proposition et les droits donnés s'inscrivent au journal de la
     * société qui accueille ; le départ, au journal de celle que le compte
     * quitte, sans rien dire de la nouvelle.
     */
    public function test_l_audit_s_inscrit_dans_les_deux_societes(): void
    {
        $quittee = $this->ouvrirUneSociete(['nom' => 'Quittée']);
        $compte = $this->compteRetire($quittee, 'paul@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $this->proposerCommeVendeur($this->proprietaireDe($magasin), $magasin, 'paul@example.test');

        app(AccepterUnRattachementAction::class)->handle($this->jetonProposeA('paul@example.test'), 'paul@example.test', self::MOT_DE_PASSE_CHOISI);

        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::RattachementPropose));
        [$accueil] = $this->entreesDAudit($magasin->societe, ActionAuditee::RattachementAccepte);
        $this->assertSame(RoleSociete::Membre->value, $accueil->nouvelles_valeurs['droits'][$compte->id]['role_societe']);
        $this->assertSame(StatutCompte::Actif->value, $accueil->nouvelles_valeurs['droits'][$compte->id]['statut']);
        [$depart] = $this->entreesDAudit($quittee->societe, ActionAuditee::RattachementAccepte);
        $this->assertSame([$compte->id => null], $depart->nouvelles_valeurs['droits']);
        $this->assertSame(StatutCompte::Retire->value, $depart->anciennes_valeurs['droits'][$compte->id]['statut']);
    }

    /**
     * Le propriétaire et un administrateur restent dans leur société : ni
     * une proposition, ni une demande glissée en base, ne les en fait
     * sortir.
     */
    public function test_ni_le_proprietaire_ni_un_administrateur_ne_se_rattache_ailleurs(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $proprietaireAilleurs = tap($this->proprietaireDe($ailleurs)->forceFill(['password' => self::MOT_DE_PASSE_CHOISI]))->save();
        $administrateurAilleurs = $this->administrateur($ailleurs, null, AutorisationsSociete::toutes());
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);

        foreach ([$proprietaireAilleurs, $administrateurAilleurs] as $compte) {
            $this->proposerCommeVendeur($proprietaire, $magasin, $compte->email);
            Mail::assertSent(RattachementImpossibleMail::class, fn (RattachementImpossibleMail $mail): bool => $mail->hasTo($compte->email));

            $jeton = Str::random(64);
            app(TenantContext::class)->runAsSociete($magasin->societe, fn (): bool => (new DemandeRattachement)->forceFill([
                'user_id' => $compte->id,
                'demande_par' => $proprietaire->id,
                'role_societe' => RoleSociete::Membre,
                'affectations' => [['magasin_id' => $magasin->id, 'role' => RoleMagasin::Vendeur->value, 'autorisations' => []]],
                'jeton_hache' => DemandeRattachement::empreinte($jeton),
                'expire_le' => now()->addDay(),
            ])->save());
            $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($jeton, $compte->email, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_COMPTE_PAS_LIBRE);
        }

        $this->assertSame([(int) $ailleurs->societe_id, RoleSociete::Proprietaire], [(int) $proprietaireAilleurs->refresh()->societe_id, $proprietaireAilleurs->role_societe]);
        $this->assertSame([(int) $ailleurs->societe_id, RoleSociete::Administrateur], [(int) $administrateurAilleurs->refresh()->societe_id, $administrateurAilleurs->role_societe]);
        Mail::assertNotSent(PropositionDeRattachementMail::class);
    }

    /**
     * La demande se cherche par l'empreinte du jeton, hors de toute
     * société, et seule la demande de ce jeton est lue : dans le contexte
     * d'une autre société, les demandes restent cachées.
     */
    public function test_la_route_de_rattachement_contourne_explicitement_le_scope_de_societe(): void
    {
        $this->compteRetireDUneAutreSociete('paul@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $this->proposerCommeVendeur($this->proprietaireDe($magasin), $magasin, 'paul@example.test');
        $jeton = $this->jetonProposeA('paul@example.test');
        $autre = $this->ouvrirUneSociete(['nom' => 'Autre société']);

        app(TenantContext::class)->runAsSociete($autre->societe, function () use ($jeton, $magasin): void {
            $this->assertSame(0, DemandeRattachement::query()->count());
            $this->assertSame((int) $magasin->societe_id, (int) AccepterUnRattachementAction::demandeEnAttente($jeton)?->societe_id);
        });
        app(TenantContext::class)->clear();
        $this->get(route('rattachement.afficher', ['jeton' => $jeton]))->assertOk()->assertSeeText('Nouvelle Boutique');
        $this->get(route('rattachement.afficher', ['jeton' => Str::random(64)]))->assertStatus(410);
    }

    /**
     * Retiré par sa société, le compte crée sa propre société, sans
     * magasin comme toute société qui s'inscrit, dont il devient le
     * propriétaire, après avoir confirmé son adresse en choisissant son mot
     * de passe par un lien ; il arrive dans l'espace de sa société.
     */
    public function test_un_compte_retire_cree_sa_propre_societe_avec_le_meme_compte(): void
    {
        $quittee = $this->ouvrirUneSociete(['nom' => 'Quittée']);
        $compte = $this->compteRetire($quittee, 'paul@example.test');
        $siren = SocieteFactory::sirenFictif();
        $creation = ['email' => 'paul@example.test', 'siren' => $siren];

        $this->post(route('societe.creer.enregistrer'), [...$creation, 'password' => self::MOT_DE_PASSE_CHOISI])
            ->assertStatus(422)
            ->assertSeeText(CreerSaSocieteAvecUnCompteRetireAction::MESSAGE_ADRESSE_A_CONFIRMER);
        $this->assertSame((int) $quittee->societe_id, (int) $compte->refresh()->societe_id);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(PageDuLien::class, ['email' => $compte->email, 'token' => Password::broker()->createToken($compte)])
            ->fillForm(['password' => 'mon-propre-mot-de-passe', 'passwordConfirmation' => 'mon-propre-mot-de-passe'])
            ->call('choisir')
            ->assertRedirect(route('societe.creer'));

        $reponse = $this->post(route('societe.creer.enregistrer'), [...$creation, 'password' => 'mon-propre-mot-de-passe']);

        $compte->refresh();
        $nouvelle = Societe::query()->where('siren', $siren)->sole();
        $reponse->assertRedirect(TableauDeBord::getUrl(panel: 'societe', tenant: $nouvelle));
        $this->assertAuthenticatedAs($compte);
        $this->assertSame([(int) $nouvelle->id, RoleSociete::Proprietaire, StatutCompte::Actif], [(int) $compte->societe_id, $compte->role_societe, $compte->statut]);
        $this->assertNull($compte->qualite_verifiee_le);
        $this->assertSame(0, Magasin::query()->where('societe_id', $nouvelle->id)->count());
        $this->assertCount(1, $this->entreesDAudit($nouvelle, ActionAuditee::SocieteCreeeParUnCompteRetire));
        [$depart] = $this->entreesDAudit($quittee->societe, ActionAuditee::SocieteCreeeParUnCompteRetire);
        $this->assertArrayNotHasKey('siren', $depart->nouvelles_valeurs);
    }

    /**
     * Un compte encore dans sa société, propriétaire compris, ne crée pas
     * de seconde société.
     */
    public function test_un_compte_actif_ne_cree_pas_de_seconde_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $proprietaire = $this->proprietaireDe($magasin)->forceFill(['password' => self::MOT_DE_PASSE_CHOISI, 'email_verified_at' => now()]);
        $proprietaire->save();
        $societes = Societe::query()->count();

        foreach ([$vendeur, $proprietaire] as $compte) {
            $siren = SocieteFactory::sirenFictif();
            $this->assertThrows(
                fn () => app(CreerSaSocieteAvecUnCompteRetireAction::class)->handle($compte->email, self::MOT_DE_PASSE_CHOISI, ['siren' => $siren]),
                ValidationException::class,
                CreerSaSocieteAvecUnCompteRetireAction::MESSAGE_PAS_RETIRE,
            );
            $this->assertSame((int) $magasin->societe_id, (int) $compte->refresh()->societe_id);
        }
        $this->assertSame($societes, Societe::query()->count());
    }

    /**
     * Un responsable retiré du magasin, un administrateur privé de
     * l'autorisation de nommer les responsables, ou rétrogradé, entre la
     * proposition et l'acceptation : sa proposition ne rattache plus
     * personne.
     */
    public function test_l_acceptation_apres_la_perte_de_ses_droits_par_l_auteur_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $prive = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $retrograde = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        foreach (['un', 'deux', 'trois'] as $nom) {
            $this->compteRetireDUneAutreSociete("{$nom}@example.test");
        }

        $this->proposerCommeVendeur($responsable, $magasin, 'un@example.test');
        app(ProposerUnRattachementAction::class)->handle($prive, $magasin->societe, 'deux@example.test', RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(ProposerUnRattachementAction::class)->handle($retrograde, $magasin->societe, 'trois@example.test', RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        app(ModifierMembreAction::class)->handle($proprietaire, $responsable, [$magasin->id => null, $autre->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierAdministrateurAction::class)->modifier($proprietaire, $prive, null, PermissionRegistry::modeleDeLAdministrateur(), []);
        app(ModifierAdministrateurAction::class)->retrograder($proprietaire, $retrograde, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        foreach (['un', 'deux', 'trois'] as $nom) {
            $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($this->jetonProposeA("{$nom}@example.test"), "{$nom}@example.test", self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_AUTEUR);
            $this->assertSame(StatutCompte::Retire, User::query()->where('email', "{$nom}@example.test")->sole()->statut);
        }
    }

    /**
     * Comme pour une création : un responsable propose seulement le rôle
     * de vendeur ou d'observateur, dans un magasin dont il est responsable,
     * un seul à la fois, et jamais comme expert-comptable.
     */
    public function test_un_responsable_ne_propose_que_des_affectations_de_vendeur_ou_d_observateur_de_ses_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $this->compteRetireDUneAutreSociete('paul@example.test');
        $proposer = fn (RoleSociete $role, array $affectations) => app(ProposerUnRattachementAction::class)->handle($responsable, $magasin->societe, 'paul@example.test', $role, $affectations);

        $this->assertThrows(fn () => $proposer(RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        $this->assertThrows(fn () => $proposer(RoleSociete::Membre, [$autre->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        $this->assertThrows(fn () => $proposer(RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        $this->assertThrows(fn () => $proposer(RoleSociete::ExpertComptable, []), AuthorizationException::class);
        $this->assertThrows(fn () => $proposer(RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => ['autorisation.inventee']]]), LogicException::class);
        $this->assertSame(0, DemandeRattachement::withoutGlobalScopes()->count());

        $proposer(RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Observateur]]);
        $proposer(RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $this->assertSame([RoleMagasin::Vendeur->value], array_column(DemandeRattachement::withoutGlobalScopes()->whereNull('annulee_le')->sole()->affectations, 'role'));
    }

    /**
     * Depuis l'équipe de son magasin, un responsable propose à un compte
     * retiré de rejoindre le magasin comme vendeur, avec ses cases ; la
     * réponse est la même que pour une adresse inconnue.
     */
    public function test_un_responsable_propose_un_compte_existant_depuis_l_equipe_de_son_magasin(): void
    {
        $this->compteRetireDUneAutreSociete('paul@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $this->dansLeMagasin($magasin, $this->responsable($magasin));

        Livewire::test(ListEquipeDuMagasin::class)
            ->assertActionVisible('proposerUnRattachement')
            ->mountAction('proposerUnRattachement')
            ->setActionData(['email' => 'paul@example.test', 'role' => RoleMagasin::Vendeur->value])
            ->set('mountedActionsData.0.autorisations', [PermissionRegistry::FACTURE_VIEW])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(ProposerUnRattachementAction::REPONSE);

        $demande = DemandeRattachement::withoutGlobalScopes()->sole();
        $this->assertSame([['magasin_id' => (int) $magasin->id, 'role' => RoleMagasin::Vendeur->value, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]]], $demande->affectations);
        Mail::assertSent(PropositionDeRattachementMail::class, fn (PropositionDeRattachementMail $mail): bool => $mail->hasTo('paul@example.test'));
    }

    /**
     * Un compte refusé ne sert plus avec son ancien mot de passe : la
     * personne choisit le sien par un lien, puis accepte.
     */
    public function test_un_compte_refuse_accepte_un_rattachement_apres_avoir_choisi_son_mot_de_passe_par_le_lien(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $compte = $this->membre($ailleurs, RoleMagasin::Vendeur, null, ['email' => 'paul@example.test']);
        app(RefuserSonCompteAction::class)->handle($compte);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $this->proposerCommeVendeur($this->proprietaireDe($magasin), $magasin, 'paul@example.test');
        $jeton = $this->jetonProposeA('paul@example.test');

        $this->assertThrows(fn () => app(AccepterUnRattachementAction::class)->handle($jeton, 'paul@example.test', self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, AccepterUnRattachementAction::MESSAGE_IDENTIFIANTS);

        app(ChoisirSonMotDePasseParLeLienAction::class)->handle($compte->refresh(), Password::broker()->createToken($compte), 'mon-nouveau-mot-de-passe');
        app(AccepterUnRattachementAction::class)->handle($jeton, 'paul@example.test', 'mon-nouveau-mot-de-passe');

        $compte->refresh();
        $this->assertSame([(int) $magasin->societe_id, StatutCompte::Actif], [(int) $compte->societe_id, $compte->statut]);
        $this->assertFalse($compte->peutRefuserSonCompte());
    }

    /**
     * Accepter redemande l'adresse et le mot de passe du compte dans la
     * requête même : une session ouverte du compte ne suffit pas.
     */
    public function test_l_acceptation_redemande_le_mot_de_passe_et_une_session_ancienne_ne_suffit_pas(): void
    {
        $compte = $this->compteRetireDUneAutreSociete('paul@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Nouvelle Boutique']);
        $this->proposerCommeVendeur($this->proprietaireDe($magasin), $magasin, 'paul@example.test');
        $jeton = $this->jetonProposeA('paul@example.test');
        $this->actingAs($compte);

        $this->post(route('rattachement.accepter', ['jeton' => $jeton]), ['email' => 'paul@example.test'])->assertSessionHasErrors('password');
        $this->post(route('rattachement.accepter', ['jeton' => $jeton]), ['email' => 'paul@example.test', 'password' => 'devine-au-hasard'])->assertSeeText(AccepterUnRattachementAction::MESSAGE_IDENTIFIANTS);
        $this->post(route('rattachement.accepter', ['jeton' => $jeton]), ['email' => 'autre@example.test', 'password' => self::MOT_DE_PASSE_CHOISI])->assertSeeText(AccepterUnRattachementAction::MESSAGE_IDENTIFIANTS);

        $this->assertSame(StatutCompte::Retire, $compte->refresh()->statut);
        $this->assertNotNull(AccepterUnRattachementAction::demandeEnAttente($jeton));
    }

    /**
     * Un vendeur d'une société, son mot de passe choisi, retiré par son
     * propriétaire : son adresse n'a jamais été confirmée. Aucune société
     * ne reste active ensuite : chaque geste du test se fait dans la
     * sienne.
     */
    private function compteRetire(Magasin $magasin, string $email): User
    {
        $compte = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => $email]);
        app(TenantContext::class)->runAs($magasin, fn () => app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $compte));
        app(TenantContext::class)->clear();

        return $compte->refresh();
    }

    private function compteRetireDUneAutreSociete(string $email): User
    {
        return $this->compteRetire($this->ouvrirUneSociete(['nom' => 'Ancienne société']), $email);
    }

    private function proposerCommeVendeur(User $auteur, Magasin $magasin, string $email): void
    {
        app(TenantContext::class)->clear();
        app(ProposerUnRattachementAction::class)->handle($auteur, $magasin->societe, $email, RoleSociete::Membre, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);
    }

    /**
     * Le jeton du dernier lien de proposition envoyé à cette adresse.
     */
    private function jetonProposeA(string $adresse): string
    {
        $lien = Mail::sent(PropositionDeRattachementMail::class, fn (PropositionDeRattachementMail $mail): bool => $mail->hasTo($adresse))->last()?->lien;
        $this->assertNotNull($lien, "Aucune proposition n'est partie vers {$adresse}.");

        return Str::afterLast($lien, '/');
    }
}
