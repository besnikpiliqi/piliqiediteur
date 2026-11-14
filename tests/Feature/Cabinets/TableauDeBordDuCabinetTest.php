<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Services\TableauDeBordDesClients;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use Database\Factories\SocieteFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le tableau de bord multi-clients d'un cabinet d'expertise comptable :
 * les clients liés ouverts au compte, et rien d'autre, avec leurs
 * factures à encaisser quand le client accorde au cabinet les factures de
 * toute l'équipe, en totaux calculés en centimes, sans nom de client final
 * ni numéro de document.
 */
class TableauDeBordDuCabinetTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_tableau_de_bord_reunit_les_clients_ouverts_au_compte_et_rien_d_autre(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $premiere = $this->ouvrirUneSociete(['nom' => 'Première', 'raison_sociale' => 'PREMIERE CLIENTE']);
        $seconde = $this->ouvrirUneSociete(['nom' => 'Seconde', 'raison_sociale' => 'SECONDE CLIENTE']);
        $etrangere = $this->ouvrirUneSociete(['nom' => 'Étrangère', 'raison_sociale' => 'CLIENTE D UN AUTRE']);
        $this->lierLeCabinet($cabinet, $premiere);
        $this->lierLeCabinet($cabinet, $seconde);
        $this->lierLeCabinet($this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux'), $etrangere);
        $collaborateur = $this->confier($this->collaborateur($cabinet), [$premiere]);

        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(TableauDeBordDuCabinet::class)->assertSeeText(['PREMIERE CLIENTE', 'SECONDE CLIENTE'])->assertDontSeeText('CLIENTE D UN AUTRE');

        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);
        Livewire::test(TableauDeBordDuCabinet::class)->assertSeeText('PREMIERE CLIENTE')->assertDontSeeText(['SECONDE CLIENTE', 'CLIENTE D UN AUTRE']);
    }

    public function test_les_factures_a_encaisser_ne_se_montrent_que_pour_les_clients_qui_accordent_les_factures_de_toute_l_equipe(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $avec = $this->ouvrirUneSociete(['nom' => 'Avec', 'raison_sociale' => 'CLIENTE QUI ACCORDE']);
        $sans = $this->ouvrirUneSociete(['nom' => 'Sans', 'raison_sociale' => 'CLIENTE QUI REFUSE']);
        $this->lierLeCabinet($cabinet, $avec);
        $this->lierLeCabinet($cabinet, $sans, [PermissionRegistry::CLIENT_VIEW, PermissionRegistry::PAIEMENT_VIEW]);
        $this->factureAEncaisser($avec, '120.00');
        $this->factureAEncaisser($sans, '80.00');

        $lignes = collect(app(TableauDeBordDesClients::class)->pour($this->proprietaireDuCabinet($cabinet)))->keyBy(fn (array $ligne): int => (int) $ligne['client']->id);

        $this->assertSame(1, $lignes[$avec->societe_id]['factures']['nombre']);
        $this->assertSame(12000, $lignes[$avec->societe_id]['factures']['restant_du']);
        $this->assertNull($lignes[$sans->societe_id]['factures']);
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(TableauDeBordDuCabinet::class)->assertSeeText('—');
    }

    public function test_les_totaux_se_calculent_en_centimes_sans_nom_de_client_ni_numero_de_document(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $premiere = $this->factureAEncaisser($magasin, '0.10', 'Client final secret');
        $seconde = $this->factureAEncaisser($magasin, '0.20', 'Autre client final');

        [$ligne] = app(TableauDeBordDesClients::class)->pour($this->proprietaireDuCabinet($cabinet));

        $this->assertSame(['nombre' => 2, 'restant_du' => 30], array_intersect_key($ligne['factures'], ['nombre' => 0, 'restant_du' => 0]));
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));
        Livewire::test(TableauDeBordDuCabinet::class)->assertDontSeeText(['Client final secret', 'Autre client final', $premiere->numero, $seconde->numero]);
    }

    public function test_la_lecture_ne_rend_que_les_colonnes_permises(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente']);
        $this->lierLeCabinet($cabinet, $magasin);
        $this->factureAEncaisser($magasin, '10.00');
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $requetes = [];
        DB::listen(function ($requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        app(TableauDeBordDesClients::class)->pour($proprietaire);

        $lectures = collect($requetes)->filter(fn (string $sql): bool => str_starts_with($sql, 'select'));
        $this->assertTrue($lectures->contains(fn (string $sql): bool => str_starts_with($sql, 'select "liens_cabinet"."id", "liens_cabinet"."uuid", "liens_cabinet"."societe_id", "liens_cabinet"."actif_depuis" from "liens_cabinet"')));
        $this->assertTrue($lectures->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "uuid", "siren", "raison_sociale", "siege_ville", "mois_fin_exercice", "statut", "validee_le", "close_le" from "societes"')));
        $this->assertTrue($lectures->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "societe_id", "statut", "territoire", "est_demonstration" from "magasins"')));
        $this->assertTrue($lectures->contains(fn (string $sql): bool => str_starts_with($sql, 'select "id", "magasin_id", "total_ttc", "date_echeance" from "factures"')));
        $this->assertFalse($lectures->contains(fn (string $sql): bool => str_contains($sql, 'from "clients"')));
    }

    public function test_la_demonstration_d_un_client_n_entre_jamais_dans_les_totaux(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Boutique cliente', 'a_verifier' => true]);
        app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe);
        $this->lierLeCabinet($cabinet, $magasin);

        [$ligne] = app(TableauDeBordDesClients::class)->pour($this->proprietaireDuCabinet($cabinet));

        $this->assertSame(1, $ligne['magasins']);
    }

    public function test_une_requete_livewire_forgee_ne_montre_aucun_client_hors_des_liens_ouverts(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $etrangere = $this->ouvrirUneSociete(['nom' => 'Étrangère', 'raison_sociale' => 'CLIENTE D UN AUTRE']);
        $lienEtranger = $this->lierLeCabinet($this->ouvrirUnCabinet(SocieteFactory::sirenFictif(), 'Hélène Roux'), $etrangere);
        $this->dansLEspaceDuCabinet($cabinet, $this->proprietaireDuCabinet($cabinet));

        foreach (['getViewData'] as $methode) {
            $this->assertThrows(fn () => Livewire::test(TableauDeBordDuCabinet::class)->call($methode, $lienEtranger->uuid), MethodNotFoundException::class);
        }
        $this->assertThrows(fn () => Livewire::test(TableauDeBordDuCabinet::class)->callAction('mettreFinAuLien', arguments: ['uuid' => $lienEtranger->uuid]), ModelNotFoundException::class);
        Livewire::test(TableauDeBordDuCabinet::class)->assertDontSeeText('CLIENTE D UN AUTRE');
        $this->assertSame(EtatDuLien::Actif, $lienEtranger->fresh()->etat);
    }

    private function factureAEncaisser(Magasin $magasin, string $montant, string $nomDuClient = 'Client professionnel'): Facture
    {
        app(TenantContext::class)->set($magasin);
        $this->completerIdentiteLegale($magasin);
        $vendeur = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => $montant, 'taux_tva' => 20, 'suivi_stock' => false]);
        $client = Client::factory()->create(['nom' => $nomDuClient, 'type_client' => TypeClient::Professionnel, 'siren' => '732829320']);
        $facture = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]));
        app(TenantContext::class)->clear();

        return $facture;
    }
}
