<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les fonctions de garde que lancent les déclencheurs s'exécutent avec les
 * droits de qui écrit : sans chemin de recherche fixé, PostgreSQL cherche
 * d'abord les tables temporaires de la session, et une table temporaire du
 * même nom qu'une table lue par la garde (users, sequences_documents…) la
 * masquerait et lui ferait accepter ce qu'elle refuse. Chaque fonction de
 * garde fixe donc son chemin de recherche, les tables temporaires en
 * dernier (voir DeclencheursDeProtection::CHEMIN_DE_RECHERCHE).
 */
class FonctionsDeGardeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Toute fonction du schéma public que lance un déclencheur (hors
     * déclencheurs internes des clés étrangères), lue dans le catalogue :
     * celles des migrations d'aujourd'hui et de demain.
     */
    public function test_chaque_fonction_de_garde_fixe_son_chemin_de_recherche(): void
    {
        $fonctions = DB::select(<<<'SQL'
            select distinct p.proname as nom, coalesce(array_to_string(p.proconfig, '|'), '') as reglages
              from pg_trigger t
              join pg_proc p on p.oid = t.tgfoid
             where not t.tgisinternal
               and p.pronamespace = 'public'::regnamespace
             order by p.proname
            SQL);
        $sansChemin = array_values(array_map(
            static fn (object $fonction): string => "{$fonction->nom} ({$fonction->reglages})",
            array_filter($fonctions, static fn (object $fonction): bool => $fonction->reglages !== 'search_path=pg_catalog, public, pg_temp'),
        ));

        $this->assertGreaterThan(20, count($fonctions), 'Le catalogue doit décrire les fonctions de garde.');
        $this->assertSame([], $sansChemin, 'Ces fonctions de garde suivent le chemin de recherche de qui écrit.');
    }

    /**
     * Une table temporaire users vide ne fait pas accepter un paiement au
     * nom du propriétaire, qui ne vend jamais : la garde lit toujours la
     * table des comptes. Le même paiement est refusé sans table
     * temporaire.
     */
    public function test_une_table_temporaire_des_comptes_ne_trompe_pas_la_garde_de_la_vente(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        app(TenantContext::class)->set($magasin);
        // Lus avant : sous la table temporaire, toute lecture des comptes
        // par l'application la lirait elle aussi.
        $client = Client::factory()->create()->id;
        $proprietaire = $this->proprietaireDe($magasin)->id;
        $paiement = fn () => DB::table('paiements')->insert([
            'magasin_id' => $magasin->id,
            'client_id' => $client,
            'mode_paiement' => 'especes',
            'montant' => '12.00',
            'recu_le' => '2026-10-09',
            'cree_par' => $proprietaire,
            'created_at' => self::enUtc('2026-10-09 08:00:00'),
            'updated_at' => self::enUtc('2026-10-09 08:00:00'),
        ]);

        $sansOmbre = $this->ecrireDansUnPointDeSauvegarde($paiement);
        $avecOmbre = $this->ecrireDansUnPointDeSauvegarde($paiement, 'CREATE TEMP TABLE users (id bigint, role_societe varchar(30))');

        $this->assertStringContainsString(GardesDeLaVente::MESSAGE, (string) $sansOmbre?->getMessage());
        $this->assertSame('P0001', $avecOmbre?->errorInfo[0], 'Une table temporaire users fait accepter un paiement au nom du propriétaire.');
        $this->assertStringContainsString(GardesDeLaVente::MESSAGE, $avecOmbre->getMessage());
        $this->assertSame(0, DB::table('paiements')->count());
    }

    /**
     * Une table temporaire sequences_documents vide ne fait pas changer le
     * territoire d'un magasin qui a numéroté un document : la colonne
     * figée lit toujours les séquences du magasin.
     */
    public function test_une_table_temporaire_des_sequences_ne_trompe_pas_une_colonne_figee(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        app(TenantContext::class)->set($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
        $autreTerritoire = $magasin->territoire === Territoire::Corse ? Territoire::Metropole : Territoire::Corse;
        $changement = fn () => DB::table('magasins')->where('id', $magasin->id)->update(['territoire' => $autreTerritoire->value]);

        $sansOmbre = $this->ecrireDansUnPointDeSauvegarde($changement);
        $avecOmbre = $this->ecrireDansUnPointDeSauvegarde($changement, 'CREATE TEMP TABLE sequences_documents (magasin_id bigint, type_document varchar(30))');

        $this->assertStringContainsString('magasins.territoire : valeur figée, changement refusé', (string) $sansOmbre?->getMessage());
        $this->assertSame('P0001', $avecOmbre?->errorInfo[0], 'Une table temporaire sequences_documents fait changer le territoire d\'un magasin qui a numéroté.');
        $this->assertStringContainsString('magasins.territoire : valeur figée, changement refusé', $avecOmbre->getMessage());
        $this->assertSame($magasin->territoire?->value, DB::table('magasins')->where('id', $magasin->id)->value('territoire'));
    }

    /**
     * L'écriture, dans un point de sauvegarde toujours annulé (sa table
     * temporaire, qui masquerait sinon la vraie pour la suite du test,
     * disparaît avec lui), après avoir créé cette table temporaire et
     * oublié les plans déjà préparés par la session : rend le refus de la
     * base, ou null si elle a accepté.
     */
    private function ecrireDansUnPointDeSauvegarde(Closure $ecriture, ?string $tableTemporaire = null): ?QueryException
    {
        DB::beginTransaction();

        try {
            if ($tableTemporaire !== null) {
                DB::unprepared('DISCARD PLANS');
                DB::statement($tableTemporaire);
            }

            $ecriture();

            return null;
        } catch (QueryException $refus) {
            return $refus;
        } finally {
            DB::rollBack();
        }
    }
}
