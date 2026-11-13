<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\GarderUneNominationAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\VerifierUnEcartSireneAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\EcartSirene;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\ChosesAFaireDeLaSociete;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * La section « À faire » de l'espace de la société (voir
 * ChosesAFaireDeLaSociete::delaSociete()) : ce qui attend dans la société
 * elle-même, pour qui peut le faire, dont les trois choses qui n'avaient
 * pas de fin (décision de l'éditeur du 01/11/2026, Q-AF13) : la nomination
 * d'un responsable par un administrateur, que le propriétaire garde ou
 * annule ; l'écart relevé au répertoire Sirene après la validation, que le
 * propriétaire, ou un administrateur qui peut rouvrir ce magasin, dit
 * vérifié. Chaque geste s'inscrit au journal d'audit ; rien d'une autre
 * société ne s'y voit jamais.
 */
class AFaireDeLEspaceDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Exemple posé par l'éditeur : un administrateur nomme Paul responsable
     * de Lyon ; « Paul a été nommé responsable de Lyon par Julie » reste
     * dans l'« À faire » du propriétaire jusqu'à ce qu'il clique « Garder »
     * (ou « Annuler la nomination »). Garder ne change aucun droit : Paul
     * reste responsable, la nomination n'est plus annulable, et le geste
     * s'inscrit au journal d'audit.
     */
    public function test_la_nomination_d_un_responsable_par_un_administrateur_reste_jusqu_a_ce_que_le_proprietaire_la_garde(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $proprietaire = $this->proprietaireDe($magasin);
        $julie = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER], nom: 'Julie');
        $paul = $this->membre($magasin, RoleMagasin::Vendeur, identite: ['name' => 'Paul']);
        app(ModifierMembreAction::class)->handle($julie, $paul, RoleMagasin::Responsable);

        $chose = $this->chose($proprietaire, $magasin->societe, 'nomination');
        $this->assertSame(['Paul a été nommé responsable de Lyon par Julie : gardez ou annulez cette nomination.', UrgenceAFaire::Orange, MembreResource::getUrl('view', ['record' => $paul->id])], [$chose?->phrase, $chose?->urgence, $chose?->url]);
        $this->assertNull($this->chose($julie, $magasin->societe, 'nomination'));

        $this->dansLEspaceDeLaSociete($magasin->societe, $julie);
        Livewire::test(FicheDuMembre::class, ['record' => $paul->getRouteKey()])->assertActionHidden('garderLaNomination');
        $this->assertThrows(fn () => app(GarderUneNominationAction::class)->handle($julie, Affectation::query()->where('user_id', $paul->id)->sole()), AuthorizationException::class, PorteeDesDroits::REFUS);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $paul->getRouteKey()])
            ->assertActionVisible('annulerLaNomination')
            ->callAction('garderLaNomination')
            ->assertHasNoActionErrors()
            ->assertActionHidden('garderLaNomination')
            ->assertActionHidden('annulerLaNomination');

        $affectation = Affectation::query()->where('user_id', $paul->id)->sole();
        $this->assertSame([RoleMagasin::Responsable, null, null], [$affectation->role, $affectation->nomme_par, $affectation->avant_la_nomination]);
        $this->assertTrue($paul->fresh()->estResponsableDe($magasin));
        $this->assertNull($this->chose($proprietaire, $magasin->societe, 'nomination'));
        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::NominationGardee);
        $this->assertCount(1, $entrees);
        $this->assertSame(['id' => $paul->id, 'nom' => 'Paul'], $entrees[0]->nouvelles_valeurs['compte'] ?? null);
        $this->assertSame(['id' => $julie->id, 'nom' => 'Julie'], $entrees[0]->nouvelles_valeurs['nomme_par'] ?? null);
    }

    /**
     * Un écart relevé au répertoire Sirene après la validation s'inscrit une
     * seule fois, même relu par chaque revérification, et reste dans
     * l'« À faire » jusqu'à « C'est vérifié » : du propriétaire, ou d'un
     * administrateur qui peut rouvrir ce magasin ; jamais d'un administrateur
     * qui ne gère pas les magasins. Le geste s'inscrit au journal d'audit ;
     * un second « C'est vérifié » est refusé.
     */
    public function test_l_ecart_au_repertoire_sirene_reste_jusqu_a_c_est_verifie_par_qui_gere_ce_magasin(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $gerant = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER], nom: 'Gérant');
        $sansMagasins = $this->administrateur($magasin, null, [], nom: 'Sans magasins');
        app(RepertoireSirene::class)->deplacer((string) $magasin->siret, '69001', 'LYON', '69381');

        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();
        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();

        $ecart = app(TenantContext::class)->runAsSociete($societe, fn (): EcartSirene => EcartSirene::query()->sole());
        $this->assertSame([(int) $magasin->id, false], [(int) $ecart->magasin_id, $ecart->negatif]);
        $this->assertStringContainsString("Ma Boutique (SIRET {$magasin->siret}) : L'adresse du magasin n'est plus celle que le répertoire donne de son établissement", $ecart->motif);
        $pourLeProprietaire = $this->chose($proprietaire, $societe, 'ecart_sirene');
        $this->assertSame([(int) $ecart->id, 'C\'est vérifié'], [$pourLeProprietaire?->cible, $pourLeProprietaire?->bouton]);
        $this->assertNotNull($this->chose($gerant, $societe, 'ecart_sirene'));
        $this->assertNull($this->chose($sansMagasins, $societe, 'ecart_sirene'));
        $this->assertThrows(fn () => app(VerifierUnEcartSireneAction::class)->handle($sansMagasins, $ecart), AuthorizationException::class, PorteeDesDroits::REFUS);

        $this->dansLEspaceDeLaSociete($societe, $gerant);
        Livewire::test(TableauDeBord::class)
            ->assertSee($ecart->motif)
            ->callAction('verifierLEcart', arguments: ['ecart' => $ecart->id])
            ->assertHasNoActionErrors()
            ->assertDontSee($ecart->motif);

        $this->assertSame((int) $gerant->id, (int) $ecart->fresh()->verifie_par);
        $this->assertSame('Gérant', $ecart->fresh()->verifie_par_nom);
        $this->assertNull($this->chose($proprietaire, $societe, 'ecart_sirene'));
        $entrees = $this->entreesDAudit($societe, ActionAuditee::EcartSireneVerifie);
        $this->assertCount(1, $entrees);
        $this->assertSame($ecart->motif, $entrees[0]->nouvelles_valeurs['motif'] ?? null);
        $this->assertThrows(fn () => app(VerifierUnEcartSireneAction::class)->handle($proprietaire, $ecart), LogicException::class, VerifierUnEcartSireneAction::DEJA_VERIFIE);
    }

    /**
     * Le code du courrier recommandé, avec sa date limite, n'est que dans
     * l'« À faire » du propriétaire : un administrateur n'en voit rien, pas
     * plus que des comptes dont le mot de passe est encore l'identifiant,
     * que seul le propriétaire voit, et dont le nombre est exactement celui
     * de la liste filtrée où il mène.
     */
    public function test_le_code_du_courrier_et_les_mots_de_passe_identifiants_ne_sont_que_pour_le_proprietaire(): void
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique', 'a_verifier' => true]);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);
        $vendeur = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Vendeur'], RoleMagasin::Vendeur));
        app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Observateur'], RoleMagasin::Observateur));
        $this->membre($magasin, RoleMagasin::Vendeur);

        $code = $this->chose($proprietaire, $societe, 'code_du_courrier');
        $this->assertSame([UrgenceAFaire::Rouge, 'Saisir le code'], [$code?->urgence, $code?->bouton]);
        $this->assertStringContainsString('avant le 04/11/2026', (string) $code?->phrase);
        $motsDePasse = $this->chose($proprietaire, $societe, 'mots_de_passe_identifiants');
        $this->assertSame([2, MembreResource::urlDesMotsDePasseIdentifiants(), true], [$motsDePasse?->nombre, $motsDePasse?->url, $motsDePasse?->aSurveiller]);
        Livewire::test(ListMembres::class)
            ->filterTable(MembreResource::FILTRE_MOT_DE_PASSE_IDENTIFIANT)
            ->assertCountTableRecords(2)
            ->assertCanSeeTableRecords([$vendeur]);

        $this->assertNull($this->chose($administrateur, $societe, 'code_du_courrier'));
        $this->assertNull($this->chose($administrateur, $societe, 'mots_de_passe_identifiants'));
    }

    /**
     * Isolation : l'« À faire » d'une société ne montre jamais la
     * nomination ni l'écart d'une autre société, et ne se lit que pour la
     * société de la personne ; un compte d'un cabinet lié n'en voit rien.
     */
    public function test_rien_d_une_autre_societe_et_rien_pour_un_cabinet(): void
    {
        Mail::fake();
        $ici = $this->ouvrirUneSociete(['nom' => 'Ici']);
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $administrateurAilleurs = $this->administrateur($ailleurs, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        app(ModifierMembreAction::class)->handle($administrateurAilleurs, $this->membre($ailleurs, RoleMagasin::Vendeur), RoleMagasin::Responsable);
        app(RepertoireSirene::class)->deplacer((string) $ailleurs->siret, '69001', 'LYON', '69381');
        $this->artisan('app:reverifier-sirene', ['--toutes' => true])->assertSuccessful();
        $proprietaireIci = $this->proprietaireDe($ici);
        $collaborateur = $this->collaborateurDuCabinetLie($ailleurs);

        $this->assertSame([], $this->cles($proprietaireIci, $ici->societe));
        $this->assertSame([], app(ChosesAFaireDeLaSociete::class)->delaSociete($proprietaireIci, $ailleurs->societe));
        $this->assertSame([], app(ChosesAFaireDeLaSociete::class)->delaSociete($collaborateur, $ailleurs->societe));
        $this->assertContains('nomination', $this->cles($this->proprietaireDe($ailleurs), $ailleurs->societe));
        $this->assertContains('ecart_sirene', $this->cles($this->proprietaireDe($ailleurs), $ailleurs->societe));
    }

    /**
     * La chose de cette clé dans l'« À faire » de l'espace de cette société
     * pour ce compte, qui s'y connecte.
     */
    private function chose(User $compte, Societe $societe, string $cle): ?ChoseAFaire
    {
        return collect($this->choses($compte, $societe))->firstWhere('cle', $cle);
    }

    /**
     * @return list<string>
     */
    private function cles(User $compte, Societe $societe): array
    {
        return array_map(fn (ChoseAFaire $chose): string => $chose->cle, $this->choses($compte, $societe));
    }

    /**
     * @return list<ChoseAFaire>
     */
    private function choses(User $compte, Societe $societe): array
    {
        $compte = $compte->fresh() ?? $compte;
        $this->dansLEspaceDeLaSociete($societe, $compte);

        return app(ChosesAFaireDeLaSociete::class)->delaSociete($compte, $societe->fresh() ?? $societe);
    }
}
