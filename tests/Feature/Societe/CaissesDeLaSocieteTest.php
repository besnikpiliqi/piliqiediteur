<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CaissesDeLaSociete;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\Societe\Pages\LesCaisses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La page « Les caisses » de l'espace d'administration de la société (voir
 * LesCaisses et CaissesDeLaSociete) : pour le propriétaire et chaque
 * administrateur, les caisses encore ouvertes et les relevés de caisse du
 * jour de chaque magasin réel de sa portée où il voit les relevés de toute
 * l'équipe ; jamais la démonstration, jamais une autre société, aucun nom
 * de client, aucun document, aucun identifiant de compte.
 */
class CaissesDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_voit_les_releves_et_les_caisses_ouvertes_de_chaque_magasin_reel(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']);
        $paul = $this->vendeurQuiVend($lyon, 'Paul', 12);
        app(TenantContext::class)->runAs($lyon, fn () => app(FermerSaCaisseAction::class)->handle($paul, $paul));
        $this->vendeurQuiVend($paris, 'Jeanne', 30);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $caisses = app(CaissesDeLaSociete::class)->pour($proprietaire, today()->toImmutable());

        $this->assertSame(
            [[$lyon->id, [], [['Paul', '12.00']]], [$paris->id, ['Jeanne'], []]],
            array_map(fn (array $magasin): array => [$magasin['magasin']->id, $magasin['caisses_ouvertes'], $magasin['releves']->map(fn ($releve): array => [$releve->vendeur_nom, $releve->total_ttc])->all()], $caisses),
        );
        Livewire::test(LesCaisses::class)
            ->assertSeeInOrder(['Lyon', 'Caisses encore ouvertes : aucune.', 'Paul', 'Fermée par le vendeur', '12,00 €', 'Paris', 'Caisses encore ouvertes : Jeanne.', 'Aucun relevé de caisse fermé le 09/10/2026.'])
            ->assertSee(ClotureCaissePersonnelleResource::getUrl('index', panel: 'app', tenant: $lyon))
            ->set('jour', '2026-10-08')
            ->assertSee('Aucun relevé de caisse fermé le 08/10/2026.')
            ->assertDontSee('12,00 €');
    }

    public function test_un_administrateur_ne_voit_que_les_magasins_de_sa_selection_ou_il_voit_les_releves_de_toute_l_equipe(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']);
        $nice = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Nice']);
        foreach ([[$lyon, 'Paul'], [$paris, 'Jeanne'], [$nice, 'Luc']] as [$magasin, $nom]) {
            $this->vendeurQuiVend($magasin, $nom, 12);
        }
        $sansLesReleves = array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(), [PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES]));
        $administrateur = $this->administrateur($lyon, [$lyon->id, $paris->id], [], [$lyon->id => PermissionRegistry::modeleDeLAdministrateur(), $paris->id => $sansLesReleves]);
        $aveugle = $this->administrateur($lyon, [$paris->id], [], $sansLesReleves);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);

        $this->assertSame([$lyon->id], array_map(fn (array $magasin): int => $magasin['magasin']->id, app(CaissesDeLaSociete::class)->pour($administrateur, today()->toImmutable())));
        Livewire::test(LesCaisses::class)
            ->assertSee('Caisses encore ouvertes : Paul.')
            ->assertDontSee(['Paris', 'Jeanne', 'Nice', 'Luc']);
        $this->dansLEspaceDeLaSociete($lyon->societe, $aveugle);
        $this->assertFalse(LesCaisses::canAccess());
        $this->get(LesCaisses::getUrl(panel: 'societe', tenant: $lyon->societe))->assertForbidden();
    }

    public function test_la_demonstration_et_les_magasins_d_une_autre_societe_n_y_figurent_jamais(): void
    {
        $reel = $this->ouvrirUneSociete(['nom' => 'Lyon', 'a_verifier' => true]);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($reel), $reel->societe);
        app(TenantContext::class)->runAs($demonstration, fn () => self::vendreAuComptant(self::responsableDeTest($demonstration), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1]], ModePaiement::Especes));
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Bordeaux']);
        $this->vendeurQuiVend($ailleurs, 'Inès', 12);
        $proprietaire = $this->proprietaireDe($reel);
        $this->dansLEspaceDeLaSociete($reel->societe, $proprietaire);

        $this->assertSame([$reel->id], array_map(fn (array $magasin): int => $magasin['magasin']->id, app(CaissesDeLaSociete::class)->pour($proprietaire, today()->toImmutable())));
        Livewire::test(LesCaisses::class)
            ->assertSee('Lyon')
            ->assertDontSee([$demonstration->code, 'Responsable de test', 'Bordeaux', 'Inès']);
    }

    /**
     * Exactement les colonnes du relevé que CLAUDE.md déclare : ni
     * identifiant de compte, ni nom de client, ni document.
     */
    public function test_la_lecture_ne_rend_que_les_champs_du_releve(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $vente = app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, Client::factory()->create(['nom' => 'Atelier Martin']), $this->lignes(12), ModePaiement::CarteBancaire));
        app(TenantContext::class)->runAs($magasin, fn () => app(FermerSaCaisseAction::class)->handle($this->proprietaireDe($magasin), $vendeur));
        app(TenantContext::class)->clear();

        [$caisses] = app(CaissesDeLaSociete::class)->pour($this->proprietaireDe($magasin), today()->toImmutable());
        $releve = $caisses['releves']->sole();

        $this->assertSame(['magasin', 'caisses_ouvertes', 'releves'], array_keys($caisses));
        $this->assertSame(
            ['vendeur_nom', 'periode_debut', 'jusqu_au', 'cloturee_a', 'mode_fermeture', 'fermee_par_nom', 'nombre_ventes', 'total_ht', 'total_tva', 'total_ttc', 'encaissements', 'credit_avoir', 'reste_du'],
            array_keys($releve->getAttributes()),
        );
        $this->assertSame([], $releve->getRelations());
        $lu = json_encode([$caisses['caisses_ouvertes'], $caisses['releves']->toArray()]);
        foreach (['Atelier Martin', $vente->numero, '"vendeur_id"', '"fermee_par"', '"id"'] as $absent) {
            $this->assertStringNotContainsString($absent, $lu);
        }
    }

    public function test_un_membre_ou_un_expert_comptable_n_ouvre_pas_la_page(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);

        foreach ([$this->responsableDeTest($magasin), $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES]), $this->expertComptable($magasin)] as $compte) {
            $this->dansLEspaceDeLaSociete($magasin->societe, $compte);

            $this->assertFalse(LesCaisses::canAccess(), $compte->name);
            $this->get(LesCaisses::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        }
    }

    /**
     * Le navigateur peut appeler toute méthode publique d'une page Livewire
     * avec les paramètres qu'il veut : la page « Les caisses » n'en a
     * aucune qui rende les caisses ou l'adresse d'un magasin qu'il
     * désignerait, et aucun jour saisi ne fait entrer un magasin hors de la
     * portée.
     */
    public function test_une_requete_livewire_forgee_ne_montre_aucun_magasin_hors_de_la_portee(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']);
        $jeanne = $this->vendeurQuiVend($paris, 'Jeanne', 12);
        app(TenantContext::class)->runAs($paris, fn () => app(FermerSaCaisseAction::class)->handle($jeanne, $jeanne));
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Bordeaux']);
        $administrateur = $this->administrateur($lyon, [$lyon->id]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);
        $page = Livewire::test(LesCaisses::class);

        foreach (['2026-10-18', today()->toDateString(), 'pas une date', '2026-02-30'] as $jour) {
            $page->set('jour', $jour)->assertSee('Lyon')->assertDontSee(['Paris', 'Jeanne', $paris->uuid, 'Bordeaux', $ailleurs->uuid]);
        }
        foreach (['caisses', 'urlDesReleves', 'jourChoisi', 'getViewData'] as $methode) {
            $this->assertThrows(fn () => Livewire::test(LesCaisses::class)->call($methode, $paris->getKey()), MethodNotFoundException::class);
        }
    }

    public function test_la_page_reste_consultable_dans_une_societe_en_lecture_seule(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $paul = $this->vendeurQuiVend($magasin, 'Paul', 12);
        app(TenantContext::class)->runAs($magasin, fn () => app(FermerSaCaisseAction::class)->handle($paul, $paul));
        $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        $this->get(LesCaisses::getUrl(panel: 'societe', tenant: $magasin->societe))
            ->assertOk()
            ->assertSeeInOrder(['Lyon', 'Paul', '12,00 €']);
    }

    /**
     * Un vendeur du magasin, de ce nom, qui y vend un ticket de ce prix au
     * comptoir, payé en espèces : sa caisse est ouverte.
     */
    private function vendeurQuiVend(Magasin $magasin, string $nom, float $prixTtc): User
    {
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => $nom]);
        app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, Client::query()->where('est_comptoir', true)->sole(), $this->lignes($prixTtc), ModePaiement::Especes));

        return $vendeur;
    }

    /**
     * Une ligne d'un article de ce prix TTC du magasin actif, sans suivi de
     * stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(float $prixTtc): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }
}
