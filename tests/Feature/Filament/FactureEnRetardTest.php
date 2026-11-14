<?php

namespace Tests\Feature\Filament;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lot DEMO-V (constat de l'éditeur du 14/11/2026) : un ticket payé
 * montrait « En retard » dans la colonne « À faire » de la liste des
 * factures dès que son échéance était passée. Une facture en retard le
 * reste tant qu'un montant reste dû après l'échéance (CLAUDE.md,
 * « Paiements et avoirs ») : réglée ou soldée par un avoir après son
 * échéance, elle ne l'est plus, ni dans la colonne, ni dans le filtre du
 * statut, ni dans l'« À faire » du tableau de bord ; payée en partie,
 * elle le reste. Les factures sont adressées à des professionnels, à
 * 20 %, sans délai propre au client : émises le 1er octobre 2026, elles
 * tombent le 31.
 */
class FactureEnRetardTest extends TestCase
{
    use RefreshDatabase;

    public function test_une_facture_payee_en_entier_apres_son_echeance_n_est_plus_en_retard(): void
    {
        [$magasin, $responsable, $facture] = $this->factureDe120EurosEchueLe31Octobre();

        $this->payer($responsable, $facture, '120.00');

        $this->assertSame(StatutFacture::Payee, $facture->fresh()?->statut);
        $this->assertPlusEnRetard($magasin, $responsable, $facture);
    }

    public function test_une_facture_soldee_par_un_avoir_apres_son_echeance_n_est_plus_en_retard(): void
    {
        [$magasin, $responsable, $facture] = $this->factureDe120EurosEchueLe31Octobre();

        app(EnregistrerAvoirAction::class)->handle($responsable, $facture->client, $facture->lignes->map(fn (LigneFacture $ligne): array => [
            'ligne_facture_id' => $ligne->id,
            'quantite' => $ligne->quantite,
        ])->all(), 'Article défectueux');

        $this->assertSame(StatutFacture::Payee, $facture->fresh()?->statut);
        $this->assertPlusEnRetard($magasin, $responsable, $facture);
    }

    public function test_une_facture_payee_en_partie_apres_son_echeance_reste_en_retard(): void
    {
        [$magasin, $responsable, $facture] = $this->factureDe120EurosEchueLe31Octobre();

        $this->payer($responsable, $facture, '50.00');

        $this->assertSame(StatutFacture::EnRetard, $facture->fresh()?->statut);
        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListFactures::class)
            ->assertTableColumnStateSet('a_faire', ['À encaisser', 'En retard'], $facture->getKey())
            ->filterTable('statut', StatutFacture::EnRetard->value)
            ->assertCanSeeTableRecords([$facture]);
        $chose = $this->aEncaisser($responsable, $magasin);
        $this->assertSame('70,00 € restant dû, dont 1 en retard (70,00 €).', $chose?->precision);
        $this->assertTrue($chose->lignes[0]['date_en_rouge']);
    }

    /**
     * Le cas vu par l'éditeur : un ticket du client comptoir réglé en
     * entier à la caisse le 1er octobre, dont l'échéance tombe le 31.
     * Une fois l'échéance passée, il n'attend toujours rien.
     */
    public function test_un_ticket_paye_a_la_caisse_n_est_pas_en_retard_apres_son_echeance(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $ticket = self::vendreAuComptant($responsable, Client::query()->where('est_comptoir', true)->sole(), [
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 32.30, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
        ]);

        $this->travelTo(self::aParis('2026-11-02 09:00:00'));

        $this->assertTrue($ticket->fresh()?->estEchue());
        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListFactures::class)->assertTableColumnStateSet('a_faire', null, $ticket->getKey());
    }

    /**
     * Ni la colonne « À faire », ni le filtre « En retard » du statut, ni
     * l'« À faire » du tableau de bord ne la montrent en retard.
     */
    private function assertPlusEnRetard(Magasin $magasin, User $compte, Facture $facture): void
    {
        $this->dansLeMagasin($magasin, $compte);
        Livewire::test(ListFactures::class)
            ->assertTableColumnStateSet('a_faire', null, $facture->getKey())
            ->filterTable('statut', StatutFacture::EnRetard->value)
            ->assertCanNotSeeTableRecords([$facture]);
        $this->assertNull($this->aEncaisser($compte, $magasin));
    }

    /**
     * Une facture de 120,00 € émise le 1er octobre 2026, échue le 31 ; le
     * temps est figé au 2 novembre.
     *
     * @return array{Magasin, User, Facture}
     */
    private function factureDe120EurosEchueLe31Octobre(): array
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->magasin();
        $responsable = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle($responsable, app(CreerFactureAction::class)->handle($responsable, Client::factory()->professionnel()->create(['nom' => 'Garage Durand']), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $this->assertTrue($facture->estEchue());

        return [$magasin, $responsable, $facture];
    }

    /**
     * La chose « factures à encaisser » de l'« À faire » de ce compte dans
     * ce magasin actif, dans l'une ou l'autre partie.
     */
    private function aEncaisser(User $compte, Magasin $magasin): ?ChoseAFaire
    {
        $choses = ChosesAFaireDuMagasin::pour($compte, $magasin);

        return collect([...$choses['a_faire'], ...$choses['a_surveiller']])->firstWhere('cle', 'a_encaisser');
    }

    private function magasin(): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique Lyon 1']));
    }

    /**
     * Un virement reçu aujourd'hui, imputé en entier sur cette facture.
     */
    private function payer(User $auteur, Facture $facture, string $montant): void
    {
        app(EnregistrerPaiementAction::class)->handle($auteur, $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'reference' => null,
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
