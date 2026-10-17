<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\FacturesAEncaisserDeLaSociete;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureResource;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Point 16 de la série 8, dans l'espace de la société : le nombre, le
 * restant dû et le retard des factures à encaisser de chaque magasin (voir
 * FacturesAEncaisserDeLaSociete), lus hors du magasin courant, pour le
 * propriétaire et, dans sa portée, un administrateur qui voit toutes les
 * factures du magasin ; aucun nom de client, aucun numéro.
 */
class FacturesAEncaisserDeLaSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * À Lyon, une facture de 50,00 € émise le 1er octobre (échue le 31) et
     * une de 120,00 € émise le 20 octobre ; à Villeurbanne, aucune. Le
     * 2 novembre, le propriétaire lit une ligne par magasin réel, à zéro
     * pour Villeurbanne, et son tableau de bord les montre, chacune menant
     * à la liste filtrée du magasin.
     */
    public function test_le_proprietaire_voit_une_ligne_par_magasin_reel(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Paris'));
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne Gratte-Ciel']);
        $this->facture($lyon, 'Atelier Martin', 50);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $this->facture($lyon, 'Garage Durand', 120);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Paris'));
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);

        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($proprietaire);

        $this->assertSame([
            $lyon->id => ['nombre' => 2, 'restant_du' => 17000, 'nombre_en_retard' => 1, 'restant_du_en_retard' => 5000],
            $villeurbanne->id => ['nombre' => 0, 'restant_du' => 0, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0],
        ], $totaux);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))
            ->assertSeeInOrder(['À encaisser', 'Restant dû', 'dont en retard', 'Lyon Centre', '2', '170,00 €', '1 · 50,00 €', 'Villeurbanne Gratte-Ciel', '0', '0,00 €', '0 · 0,00 €'])
            ->assertSee(htmlspecialchars(FactureResource::urlDesFacturesAEncaisser($lyon)), escape: false)
            ->assertDontSee(['Atelier Martin', 'Garage Durand']);
    }

    public function test_un_administrateur_d_une_selection_ne_voit_que_ses_magasins(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne Gratte-Ciel']);
        $this->facture($lyon, 'Atelier Martin', 50);
        $this->facture($villeurbanne, 'Garage Durand', 120, $this->responsable($villeurbanne));
        $administrateur = $this->administrateur($lyon, [$lyon->id]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);

        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($administrateur);

        $this->assertSame([$lyon->id => ['nombre' => 1, 'restant_du' => 5000, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $totaux);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))
            ->assertSeeInOrder(['Lyon Centre', '1', '50,00 €'])
            ->assertDontSee(['Villeurbanne Gratte-Ciel', '120,00 €']);
    }

    /**
     * Un administrateur des deux magasins qui ne voit pas toutes les
     * factures de Villeurbanne n'en lit rien : son tableau de bord montre
     * le magasin, sans ses totaux.
     */
    public function test_un_magasin_sans_l_autorisation_de_voir_toutes_les_factures_n_a_pas_de_ligne(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne Gratte-Ciel']);
        $this->facture($lyon, 'Atelier Martin', 50);
        $this->facture($villeurbanne, 'Garage Durand', 120, $this->responsable($villeurbanne));
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [
            $lyon->id => PermissionRegistry::modeleDeLAdministrateur(),
            $villeurbanne->id => array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(), [PermissionRegistry::FACTURE_VIEW_TOUTES])),
        ]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);

        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($administrateur);

        $this->assertSame([$lyon->id => ['nombre' => 1, 'restant_du' => 5000, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $totaux);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))
            ->assertSeeInOrder(['Lyon Centre', '50,00 €', 'Villeurbanne Gratte-Ciel', '—', '—', '—'])
            ->assertDontSee('120,00 €');
    }

    /**
     * Un administrateur des deux magasins qui voit celles de toute l'équipe
     * à Villeurbanne, mais dont la case « Factures : voir (les siennes) »
     * y est décochée, ne peut pas y ouvrir la liste des factures : le
     * magasin n'a pas de ligne, et son tableau de bord ne mène qu'à la
     * liste de Lyon.
     */
    public function test_un_magasin_dont_l_administrateur_ne_peut_pas_ouvrir_la_liste_des_factures_n_a_pas_de_ligne(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $villeurbanne = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Villeurbanne Gratte-Ciel']);
        $this->facture($lyon, 'Atelier Martin', 50);
        $this->facture($villeurbanne, 'Garage Durand', 120, $this->responsable($villeurbanne));
        $administrateur = $this->administrateur($lyon, [$lyon->id, $villeurbanne->id], autorisationsMagasin: [
            $lyon->id => PermissionRegistry::modeleDeLAdministrateur(),
            $villeurbanne->id => array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(), [PermissionRegistry::FACTURE_VIEW])),
        ]);
        $this->dansLEspaceDeLaSociete($lyon->societe, $administrateur);

        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($administrateur);

        $this->assertSame([$lyon->id => ['nombre' => 1, 'restant_du' => 5000, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $totaux);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe))
            ->assertSee(htmlspecialchars(FactureResource::urlDesFacturesAEncaisser($lyon)), escape: false)
            ->assertDontSee(htmlspecialchars(FactureResource::urlDesFacturesAEncaisser($villeurbanne)), escape: false)
            ->assertDontSee('120,00 €');
    }

    /**
     * La lecture ne rend que des totaux, et ne lit des factures et de leurs
     * imputations que les colonnes qu'elle somme : ni le nom du client, ni
     * le numéro, ni aucune autre colonne d'une facture.
     */
    public function test_la_lecture_ne_rend_aucun_nom_de_client(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $facture = $this->facture($lyon, 'Atelier Martin', 50);
        $proprietaire = $this->proprietaireDe($lyon);
        $this->dansLEspaceDeLaSociete($lyon->societe, $proprietaire);
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($proprietaire);

        $this->assertSame([$lyon->id => ['nombre' => 1, 'restant_du' => 5000, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $totaux);
        $surLesFactures = array_values(array_filter($requetes, fn (string $sql): bool => preg_match('/from "(factures|paiement_allocations|avoir_allocations)"/', $sql) === 1));
        $this->assertCount(3, $surLesFactures);
        $this->assertStringStartsWith('select "id", "magasin_id", "total_ttc", "date_echeance" from "factures"', $surLesFactures[0]);
        $this->assertStringStartsWith('select "id", "facture_id", "montant_alloue" from "paiement_allocations"', $surLesFactures[1]);
        $this->assertStringStartsWith('select "id", "facture_id", "montant_alloue" from "avoir_allocations"', $surLesFactures[2]);
        $this->assertStringNotContainsString('Atelier Martin', json_encode($totaux));
        $this->assertStringNotContainsString($facture->numero, json_encode($totaux));
    }

    /**
     * Le magasin de démonstration, qui vend avant la validation de la
     * société, n'a jamais de ligne : seul le magasin réel en a une, à zéro.
     */
    public function test_le_magasin_de_demonstration_n_a_pas_de_ligne(): void
    {
        $societe = $this->inscrireUneSociete();
        $reel = $this->creerLeMagasinDuSiege($societe);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        $vente = app(TenantContext::class)->runAs($demonstration, fn (): Facture => app(EmettreFactureAction::class)->handle($proprietaire, app(CreerFactureAction::class)->handle($proprietaire, Client::factory()->create(), [
            ['produit_id' => Produit::query()->where('reference', 'DEMO-CARNET')->value('id'), 'quantite' => 1],
        ])));
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);

        $totaux = app(FacturesAEncaisserDeLaSociete::class)->pour($proprietaire);

        $this->assertTrue($vente->statut->attendUnReglement());
        $this->assertSame([$reel->id => ['nombre' => 0, 'restant_du' => 0, 'nombre_en_retard' => 0, 'restant_du_en_retard' => 0]], $totaux);
    }

    /**
     * Une facture d'un article au prix TTC donné, à 20 %, émise dans ce
     * magasin pour un nouveau client professionnel de ce nom, par l'auteur
     * donné, sinon par le propriétaire (qui n'encaisse que dans un magasin
     * à la fois) ; la société peut facturer un professionnel.
     */
    private function facture(Magasin $magasin, string $client, float $prixTtc, ?User $auteur = null): Facture
    {
        $this->completerIdentiteLegale($magasin);
        $auteur ??= $this->proprietaireDe($magasin);

        return app(TenantContext::class)->runAs($magasin, function () use ($auteur, $client, $prixTtc): Facture {
            $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

            return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->professionnel()->create(['nom' => $client]), [
                ['produit_id' => $produit->id, 'quantite' => 1],
            ]));
        });
    }
}
