<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ListAvoirsFournisseur;
use App\Filament\App\Resources\AvoirResource\Pages\ListAvoirs;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\FiltreDUnJour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le filtre « Jour » des listes de documents (choix de l'éditeur du
 * 16/11/2026, lot GRAPH-AN, « la liste filtrée des documents de ce jour »),
 * là où mène le clic sur un jour d'un graphique par période : un jour du
 * calendrier du magasin, de minuit à minuit. Un ticket de 23 h 30 à Paris est
 * du jour qui finit, un autre de 0 h 30 est du jour qui commence (les
 * instants s'enregistrent en UTC) ; une facture d'achat est du jour de la
 * facture du fournisseur ; une valeur qui n'est pas un jour réel ne filtre
 * rien.
 */
class FiltreDUnJourDesListesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les factures et les tickets : celui du 9 mars à 23 h 30 n'est pas du
     * 10 mars, celui du 10 mars à 0 h 30 en est.
     */
    public function test_la_liste_des_factures_ne_montre_que_les_documents_du_jour_du_magasin(): void
    {
        [$magasin, $responsable, $client, $lampe] = $this->magasinEtLampe();
        $veille = $this->vendre($magasin, $responsable, $client, $lampe, '2026-03-09 23:30:00');
        $matin = $this->vendre($magasin, $responsable, $client, $lampe, '2026-03-10 00:30:00');
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        Livewire::test(ListFactures::class)
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-10'])
            ->assertCanSeeTableRecords([$matin])
            ->assertCanNotSeeTableRecords([$veille])
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-09'])
            ->assertCanSeeTableRecords([$veille])
            ->assertCanNotSeeTableRecords([$matin]);
    }

    /**
     * Les avoirs émis ce jour-là.
     */
    public function test_la_liste_des_avoirs_ne_montre_que_les_avoirs_du_jour(): void
    {
        [$magasin, $responsable, $client, $lampe] = $this->magasinEtLampe();
        $ticket = $this->vendre($magasin, $responsable, $client, $lampe, '2026-03-09 10:00:00', 2);
        $premier = $this->rendre($magasin, $responsable, $client, $ticket, '2026-03-09 23:45:00');
        $second = $this->rendre($magasin, $responsable, $client, $ticket, '2026-03-10 00:15:00');
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        Livewire::test(ListAvoirs::class)
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-10'])
            ->assertCanSeeTableRecords([$second])
            ->assertCanNotSeeTableRecords([$premier]);
    }

    /**
     * Les factures d'achat sont du jour de la facture du fournisseur, quel
     * que soit le jour où elles sont validées ; les avoirs fournisseur sont
     * du jour de leur émission.
     */
    public function test_les_listes_des_achats_se_filtrent_sur_le_jour_de_la_facture_et_celui_de_l_avoir(): void
    {
        [$magasin, $responsable] = $this->magasinEtLampe();
        $this->travelTo(self::aParis('2026-03-12 10:00:00'));
        [$ancienne, $recente, $avoir] = app(TenantContext::class)->runAs($magasin, function () use ($responsable): array {
            $cable = Produit::factory()->create(['nom' => 'Câble', 'prix_vente_ttc' => '12.00', 'taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
            $fournisseur = Fournisseur::factory()->create(['nom' => 'Grossiste Est']);
            $acheter = fn (string $jour): FactureAchat => app(ValiderFactureAchatAction::class)->handle($responsable, app(CreerFactureAchatAction::class)->handle($responsable, $fournisseur, [
                ['produit_id' => $cable->id, 'quantite' => 5, 'prix_unitaire_ht' => '5.00', 'taux_tva' => 20],
            ], ['date_facture' => $jour]));
            $ancienne = $acheter('2026-03-05');
            $recente = $acheter('2026-03-11');
            $this->travelTo(self::aParis('2026-03-12 11:00:00'));
            $avoir = app(EnregistrerAvoirFournisseurAction::class)->handle($responsable, $recente, [['ligne_achat_id' => $recente->lignes->first()->id, 'quantite' => 1]], 'Câble cassé', 'AV-EST-1');

            return [$ancienne, $recente, $avoir];
        });
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        Livewire::test(ListFacturesAchat::class)
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-05'])
            ->assertCanSeeTableRecords([$ancienne])
            ->assertCanNotSeeTableRecords([$recente]);
        Livewire::test(ListAvoirsFournisseur::class)
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-12'])
            ->assertCanSeeTableRecords([$avoir])
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-11'])
            ->assertCanNotSeeTableRecords([$avoir]);
        $this->assertInstanceOf(AvoirFournisseur::class, $avoir);
    }

    /**
     * Un jour qui n'en est pas un (« 2026-02-30 », un texte) ne filtre rien :
     * la liste reste entière.
     */
    public function test_une_valeur_qui_n_est_pas_un_jour_reel_ne_filtre_rien(): void
    {
        [$magasin, $responsable, $client, $lampe] = $this->magasinEtLampe();
        $premiere = $this->vendre($magasin, $responsable, $client, $lampe, '2026-03-09 10:00:00');
        $seconde = $this->vendre($magasin, $responsable, $client, $lampe, '2026-03-10 10:00:00');
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        foreach (['2026-02-30', 'demain', ''] as $valeur) {
            Livewire::test(ListFactures::class)
                ->filterTable(FiltreDUnJour::NOM, ['jour' => $valeur])
                ->assertCanSeeTableRecords([$premiere, $seconde]);
        }
    }

    /**
     * Le filtre ne montre que les documents du magasin du panneau : un autre
     * magasin de la société, même un jour où il vend, n'y paraît pas.
     */
    public function test_le_filtre_ne_montre_jamais_les_documents_d_un_autre_magasin(): void
    {
        [$magasin, $responsable, $client, $lampe] = $this->magasinEtLampe();
        $duPort = $this->vendre($magasin, $responsable, $client, $lampe, '2026-03-10 10:00:00');
        $this->travelTo(self::aParis('2026-03-10 09:00:00'));
        $gare = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Gare', 'code' => 'GARE']);
        [$lampeDeLaGare, $clientDeLaGare] = app(TenantContext::class)->runAs($gare, fn (): array => [
            Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]),
            Client::factory()->create(['nom' => 'Julie Petit']),
        ]);
        $this->travelTo(self::aParis('2026-03-10 11:00:00'));
        $deLaGare = $this->vendre($gare, self::responsableDeTest($gare), $clientDeLaGare, $lampeDeLaGare, '2026-03-10 11:00:00');
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        Livewire::test(ListFactures::class)
            ->filterTable(FiltreDUnJour::NOM, ['jour' => '2026-03-10'])
            ->assertCanSeeTableRecords([$duPort])
            ->assertCanNotSeeTableRecords([$deLaGare]);
    }

    /**
     * @return array{0: Magasin, 1: User, 2: Client, 3: Produit}
     */
    private function magasinEtLampe(): array
    {
        $this->travelTo(self::aParis('2026-03-01 09:00:00'));
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']));
        [$lampe, $client] = app(TenantContext::class)->runAs($magasin, fn (): array => [
            Produit::factory()->create(['nom' => 'Lampe', 'prix_vente_ttc' => '24.00', 'taux_tva' => 20, 'suivi_stock' => false]),
            Client::factory()->create(['nom' => 'Julie Petit']),
        ]);
        app(TenantContext::class)->clear();

        return [$magasin, self::responsableDeTest($magasin), $client, $lampe];
    }

    private function vendre(Magasin $magasin, User $responsable, Client $client, Produit $lampe, string $instant, int $lampes = 1): Facture
    {
        $this->travelTo(self::aParis($instant));

        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($responsable, $client, [['produit_id' => $lampe->id, 'quantite' => $lampes]]));
    }

    private function rendre(Magasin $magasin, User $responsable, Client $client, Facture $ticket, string $instant): Avoir
    {
        $this->travelTo(self::aParis($instant));

        return app(TenantContext::class)->runAs($magasin, fn (): Avoir => app(EnregistrerAvoirAction::class)->handle($responsable, $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]], 'Retour'));
    }
}
