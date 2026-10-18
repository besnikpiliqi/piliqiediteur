<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\TransfererProprieteAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le propriétaire, et lui seul, transmet la propriété de sa société, en
 * redonnant son mot de passe, à un administrateur actif dont la qualité de
 * représentant est prouvée à son nom ; il en devient administrateur de
 * tous les magasins, avec toutes les autorisations sauf celles de la
 * vente, que le nouveau propriétaire peut lui cocher, et la société garde
 * toujours un seul propriétaire, qui ne vend jamais.
 */
class TransfererProprieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le mot de passe que la fabrique donne au propriétaire.
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    public function test_le_proprietaire_transmet_la_propriete_a_un_administrateur_dont_la_qualite_est_prouvee(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->assertActionVisible('transmettreLaPropriete')
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertHasNoActionErrors()
            ->assertRedirect(MembreResource::getUrl('index'));

        $this->assertSame(RoleSociete::Proprietaire, $administrateur->refresh()->role_societe);
        $this->assertSame(RoleSociete::Administrateur, $proprietaire->refresh()->role_societe);
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
        foreach ([$magasin, $autre] as $chacun) {
            $siens = collect($this->modificationsDesDroits($chacun))->where('membre.id', $administrateur->id)->last();
            $this->assertSame(RoleSociete::Proprietaire->value, $siens['apres']['role_societe'], $chacun->nom);
            $anciens = collect($this->modificationsDesDroits($chacun))->where('membre.id', $proprietaire->id)->last();
            $this->assertSame(RoleSociete::Administrateur->value, $anciens['apres']['role_societe'], $chacun->nom);
        }
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee);
        $this->assertSame(RoleSociete::Proprietaire->value, $entree->nouvelles_valeurs['droits'][$administrateur->id]['role_societe']);
        $this->assertSame(RoleSociete::Administrateur->value, $entree->nouvelles_valeurs['droits'][$proprietaire->id]['role_societe']);
        Notification::assertSentTo($administrateur, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Vous êtes propriétaire'
            && str_ends_with($avis->texte, 'vous avez désormais tous les droits dans la société et dans chacun de ses magasins, sauf celui de vendre : le propriétaire supervise et contrôle.'));
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Propriété transmise'
            && str_ends_with($avis->texte, 'vous en êtes désormais administrateur de tous les magasins, avec toutes les autorisations de magasin sauf celles de la vente, que le nouveau propriétaire peut vous cocher, et toutes celles de la société.'));
    }

    public function test_le_transfert_a_un_administrateur_sans_qualite_prouvee_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());

        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, TransfererProprieteAction::MESSAGE_QUALITE);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee));
    }

    /**
     * Le mot de passe du propriétaire se redonne à chaque transfert : sans
     * lui, ou faux, rien ne change.
     */
    public function test_le_mot_de_passe_est_redemande(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => ''])
            ->assertHasActionErrors(['mot_de_passe' => 'required']);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => 'devine-au-hasard'])
            ->assertNotified(MotDePasseRedemande::MESSAGE);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
    }

    /**
     * Après un transfert réussi comme après chaque transfert refusé, la
     * société a exactement un propriétaire actif ; la base refuse d'en
     * poser un second.
     */
    public function test_chaque_societe_a_exactement_un_proprietaire_actif_apres_chaque_action_et_procedure(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = tap($this->proprietaireDe($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();
        $premier = $this->administrateurQualifie($magasin);
        $sansQualite = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $unSeulProprietaireActif = fn (): bool => User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->where('statut', 'actif')->count() === 1;

        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $sansQualite, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class);
        $this->assertTrue($unSeulProprietaireActif());
        app(TransfererProprieteAction::class)->handle($proprietaire, $premier, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->assertTrue($unSeulProprietaireActif());
        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire->refresh(), $sansQualite, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, TransfererProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        app(TransfererProprieteAction::class)->handle($premier->refresh(), $proprietaire, self::MOT_DE_PASSE_CHOISI);
        $this->assertTrue($unSeulProprietaireActif());
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));

        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire->refresh(), $retire);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $retire->id)->update(['role_societe' => RoleSociete::Proprietaire->value])), UniqueConstraintViolationException::class);
        $this->assertTrue($unSeulProprietaireActif());
    }

    public function test_un_administrateur_ne_transmet_pas_la_propriete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);
        $autre = $this->administrateurQualifie($magasin);

        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($administrateur, $autre, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, TransfererProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($administrateur, $administrateur, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, TransfererProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Administrateur], [$administrateur->refresh()->role_societe, $autre->refresh()->role_societe]);
    }

    /**
     * La propriété ne va qu'à un administrateur actif de la société : ni à
     * l'administrateur d'une autre société, ni à un administrateur
     * suspendu, ni à un membre, même responsable, ni au propriétaire
     * lui-même.
     */
    public function test_la_propriete_ne_va_ni_a_un_compte_d_une_autre_societe_ni_a_un_compte_suspendu_ni_a_un_simple_membre(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $administrateurAilleurs = $this->administrateurQualifie($ailleurs);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->administrateurQualifie($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        $responsable = tap($this->responsable($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();

        foreach ([$administrateurAilleurs, $suspendu->refresh(), $responsable, $proprietaire] as $cible) {
            $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $cible, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, TransfererProprieteAction::MESSAGE_ADMINISTRATEUR);
        }

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateurAilleurs->refresh()->role_societe);
        $this->assertSame(RoleSociete::Membre, $responsable->refresh()->role_societe);
    }

    /**
     * Le nouveau propriétaire perd sa sélection, ses cases et ses
     * autorisations de société ; l'ancien reçoit tous les magasins,
     * présents et futurs, toutes les cases sauf celles de la vente, celles
     * qu'il avait comme propriétaire (précision 1), et toutes les
     * autorisations de société.
     */
    public function test_le_nouveau_proprietaire_perd_ses_affectations_et_l_ancien_devient_administrateur_de_tous_les_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = tap($this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER], [PermissionRegistry::FACTURE_VIEW])->forceFill(['qualite_verifiee_le' => now()]))->save();

        app(TransfererProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->count());
        $this->assertSame(0, DB::table('autorisations_societe')->where('user_id', $administrateur->id)->count());
        $globale = Affectation::query()->where('user_id', $proprietaire->id)->sole();
        $this->assertNull($globale->magasin_id);
        $this->assertNull($globale->role);
        $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(), $globale->autorisations());
        $proprietaire->refresh();
        $this->assertEqualsCanonicalizing(AutorisationsSociete::toutes(), $proprietaire->autorisationsDeSociete());
        $this->assertTrue($proprietaire->administreTousLesMagasins());

        $futur = app(TenantContext::class)->runAs($magasin, fn (): Magasin => $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Magasin futur']));
        $this->assertTrue($proprietaire->refresh()->peutOuvrir($futur));
        $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(), $proprietaire->autorisationsDans($futur));
    }

    /**
     * L'ancien propriétaire, devenu administrateur, ne vend pas plus
     * qu'avant, dans aucun magasin : ni caisse, ni facture, ni caisse
     * personnelle ; le nouveau propriétaire peut lui cocher la vente.
     */
    public function test_l_ancien_proprietaire_devenu_administrateur_n_a_aucune_autorisation_de_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);

        app(TransfererProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $ancien = $proprietaire->refresh();
        foreach ([$magasin, $autre] as $chacun) {
            $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), $ancien->autorisationsDans($chacun))), $chacun->nom);
            $this->assertFalse($ancien->tientUneCaisseDans($chacun), $chacun->nom);
        }
        $this->assertThrows(fn () => app(CreerFactureAction::class)->handle($ancien, Client::factory()->create(), $this->lignes()), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, Facture::query()->count());
        $this->dansLeMagasin($magasin, $ancien);
        $this->get(Caisse::getUrl(tenant: $magasin))->assertForbidden();

        app(ModifierAdministrateurAction::class)->modifier($administrateur->refresh(), $ancien, null, PermissionRegistry::toutes(), AutorisationsSociete::toutes());

        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $ancien->refresh()->autorisationsDans($autre));
        $this->assertTrue($ancien->tientUneCaisseDans($autre));
    }

    /**
     * Un administrateur à qui le propriétaire avait coché la vente a vendu
     * ce jour-là, puis reçoit la propriété : il ne vend plus, sa caisse
     * reste ouverte, et la clôture du jour la ferme d'office, comme celle
     * de tout vendeur, avec sa vente.
     */
    public function test_le_nouveau_proprietaire_qui_avait_vendu_voit_sa_caisse_fermee_par_la_cloture_du_jour(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
        self::vendreAuComptant($vendeur, Client::factory()->create(), $this->lignes(), ModePaiement::Especes);

        app(TransfererProprieteAction::class)->handle($proprietaire, $vendeur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $nouveau = $vendeur->refresh();
        $this->assertFalse($nouveau->tientUneCaisseDans($magasin));
        $this->assertThrows(fn () => self::vendreAuComptant($nouveau, Client::factory()->create(), $this->lignes(), ModePaiement::Especes), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame([$nouveau->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys());

        $this->dansLeMagasin($magasin, $nouveau);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer')->assertHasNoActionErrors();

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([$nouveau->id, ModeFermetureCaisse::Automatique, 1, '12.00'], [$releve->vendeur_id, $releve->mode_fermeture, $releve->nombre_ventes, $releve->total_ttc]);
        $this->assertSame([], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->all());
    }

    /**
     * Un administrateur à qui le propriétaire avait coché la vente a vendu
     * ce jour-là à Ma Boutique, puis reçoit la propriété. Tant que cette
     * caisse reste ouverte, il ne contre-passe rien dans un autre magasin
     * (une caisse à la fois) ; il la ferme lui-même, par « Fermer ma
     * caisse », bien qu'il ne vende plus, puis contre-passe dans l'autre
     * magasin, où la contre-passation revient à la caisse du vendeur du
     * paiement corrigé, jamais à la sienne.
     */
    public function test_le_nouveau_proprietaire_ferme_lui_meme_sa_caisse_puis_contre_passe_dans_un_autre_magasin(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
        app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, Client::factory()->create(), $this->lignes(), ModePaiement::Especes));
        $responsableAilleurs = self::responsableDeTest($autre);
        $paiement = app(TenantContext::class)->runAs($autre, function () use ($responsableAilleurs): Paiement {
            self::vendreAuComptant($responsableAilleurs, Client::factory()->create(), $this->lignes(), ModePaiement::Especes);

            return Paiement::query()->sole();
        });

        app(TransfererProprieteAction::class)->handle($proprietaire, $vendeur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $nouveau = $vendeur->refresh();
        $contrePasser = fn (): Paiement => app(TenantContext::class)->runAs($autre, fn (): Paiement => app(ContrePasserPaiementAction::class)->handle($nouveau, $paiement, 'Saisi par erreur'));
        $this->assertThrows($contrePasser, LogicException::class, 'Fermez d\'abord votre caisse de Ma Boutique : vous n\'encaissez que dans un magasin à la fois.');

        $this->dansLeMagasin($magasin, $nouveau);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->callAction('fermer_ma_caisse')
            ->assertHasNoActionErrors();

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([$nouveau->id, ModeFermetureCaisse::Vendeur, $nouveau->id, 1, '12.00'], [$releve->vendeur_id, $releve->mode_fermeture, $releve->fermee_par, $releve->nombre_ventes, $releve->total_ttc]);
        Livewire::test(ListClotureCaissePersonnelles::class)->assertActionHidden('fermer_ma_caisse');

        $contrePassation = $contrePasser();

        $this->assertSame([$nouveau->id, $paiement->id, '-12.00'], [$contrePassation->cree_par, $contrePassation->contre_passe_paiement_id, $contrePassation->montant]);
        $this->assertSame([$responsableAilleurs->id], app(TenantContext::class)->runAs($autre, fn (): array => app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys()));
        $this->assertSame([], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->all());
    }

    /**
     * Un administrateur à qui le propriétaire avait coché la vente émet un
     * ticket resté dû et crée un brouillon, puis reçoit la propriété. Le
     * brouillon reste à son nom et se modifie toujours, mais la base refuse
     * de l'émettre à son nom ; émis par le responsable, il devient la vente
     * de celui-ci (auteur, nom figé, contenu signé, caisse personnelle), et
     * la caisse du propriétaire ne garde que la vente qu'il avait faite
     * comme administrateur, qui reste la sienne et se règle toujours.
     */
    public function test_un_brouillon_de_l_administrateur_devenu_proprietaire_devient_a_son_emission_la_vente_de_qui_l_emet(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
        $responsable = self::responsableDeTest($magasin);
        $ticketDu = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes()));
        $brouillon = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes());

        app(TransfererProprieteAction::class)->handle($proprietaire, $vendeur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $nouveau = $vendeur->refresh();
        app(ModifierFactureAction::class)->handle($responsable, $brouillon->fresh(), $brouillon->client, $this->lignes());
        $this->assertSame([$nouveau->id, StatutFacture::Brouillon], [$brouillon->fresh()->cree_par, $brouillon->fresh()->statut]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['numero' => 'TIC-TEST-2026-000099', 'statut' => StatutFacture::Emise->value])), QueryException::class, GardesDeLaVente::MESSAGE);

        $vente = app(EmettreFactureAction::class)->handle($responsable, $brouillon->fresh());

        $enregistrement = $vente->enregistrementDeVente()->firstOrFail();
        $this->assertSame([$responsable->id, 'Responsable de test', StatutFacture::Emise], [$vente->cree_par, $vente->cree_par_nom, $vente->statut]);
        $this->assertSame([$responsable->id, $responsable->id], [$enregistrement->operateur_id, $enregistrement->contenuSigne()['donnees']['document']['cree_par']]);
        $this->assertSame([1, 1], [app(FermerSaCaisseAction::class)->apercu($nouveau)->nombre_ventes, app(FermerSaCaisseAction::class)->apercu($responsable)->nombre_ventes]);
        $this->assertEqualsCanonicalizing([$nouveau->id, $responsable->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys());

        app(EnregistrerPaiementAction::class)->handle($responsable, $ticketDu->client, ['mode_paiement' => ModePaiement::Especes, 'montant' => 12, 'recu_le' => today()->toDateString()], [['facture_id' => $ticketDu->id, 'montant' => 12]]);

        $this->assertSame([$nouveau->id, StatutFacture::Payee], [$ticketDu->fresh()->cree_par, $ticketDu->fresh()->statut]);
    }

    /**
     * Une requête Livewire forgée par un administrateur, sur la fiche d'un
     * autre administrateur qu'il voit, ne transmet rien : l'action lui est
     * masquée, et l'action du domaine le refuse.
     */
    public function test_un_transfert_force_par_une_requete_livewire_d_un_administrateur_ne_change_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurQualifie($magasin);
        $responsable = tap($this->responsable($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $responsable->getRouteKey()])->assertActionHidden('transmettreLaPropriete'), 'transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_CHOISI]);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee));
    }

    public function test_la_propriete_ne_va_jamais_a_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $expert = tap($this->expertComptable($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->assertActionHidden('transmettreLaPropriete');
        $this->assertThrows(fn () => app(TransfererProprieteAction::class)->handle($proprietaire, $expert, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, TransfererProprieteAction::MESSAGE_ADMINISTRATEUR);

        $this->assertSame(RoleSociete::ExpertComptable, $expert->refresh()->role_societe);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
    }

    /**
     * Une ligne d'un article à 12,00 € TTC, sans suivi de stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    /**
     * Un administrateur de tous les magasins, avec toutes les autorisations
     * de société, dont la qualité de représentant est prouvée à son nom.
     */
    private function administrateurQualifie(Magasin $magasin): User
    {
        return tap($this->administrateur($magasin, null, AutorisationsSociete::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
    }
}
