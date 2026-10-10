<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * Les journées de caisse se clôturent chaque jour du calendrier à partir
 * de la première vente, dans l'ordre, une journée oubliée la première.
 */
class CloturerLesJourneesActionTest extends TestCase
{
    use RefreshDatabase;

    private function magasin(): Magasin
    {
        $magasin = Magasin::factory()->create(['nom' => 'Ma Boutique']);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    /**
     * Un ticket de 12,00 € TTC, émis maintenant au client particulier.
     */
    private function vendreUnTicket(): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    public function test_un_magasin_qui_n_a_jamais_vendu_n_a_aucune_journee_a_cloturer(): void
    {
        $this->travelTo('2026-10-09 19:00:00');
        $magasin = $this->magasin();

        $clotures = app(CloturerLesJourneesAction::class)->jusquAu($magasin, now());

        $this->assertTrue($clotures->isEmpty());
        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    /**
     * Le 09/10 au soir, sans clôture depuis la première vente du 07/10 :
     * les journées des 07, 08 et 09/10 se clôturent dans l'ordre, celle du
     * 08, sans vente, à zéro, et leurs numéros suivent l'ordre des
     * journées.
     */
    public function test_cloture_dans_l_ordre_chaque_journee_depuis_la_premiere_vente(): void
    {
        $magasin = $this->magasin();
        $this->travelTo('2026-10-07 10:00:00');
        $venteDu7 = $this->vendreUnTicket();
        $this->travelTo('2026-10-09 19:00:00');

        $clotures = app(CloturerLesJourneesAction::class)->jusquAu($magasin, now());

        $this->assertSame(
            [['2026-10-07', 'Z-MABOUT-2026-000001', 1, '12.00'], ['2026-10-08', 'Z-MABOUT-2026-000002', 0, '0.00'], ['2026-10-09', 'Z-MABOUT-2026-000003', 0, '0.00']],
            $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture->toDateString(), $cloture->numero, $cloture->nombre_ventes, $cloture->total_ttc])->all(),
        );
        $this->assertSame($clotures->first()->id, $venteDu7->fresh()->cloture_caisse_id);
    }

    public function test_reprend_au_lendemain_de_la_derniere_journee_cloturee(): void
    {
        $magasin = $this->magasin();
        $this->travelTo('2026-10-05 10:00:00');
        $this->vendreUnTicket();
        app(CloturerCaisseAction::class)->handle($magasin, '2026-10-05');
        $this->travelTo('2026-10-08 19:00:00');

        $journees = app(CloturerLesJourneesAction::class)->journeesEnAttente(now());

        $this->assertSame(['2026-10-06', '2026-10-07', '2026-10-08'], $journees->all());
    }

    /**
     * La première erreur arrête le magasin à la journée en échec : les
     * journées d'avant restent clôturées, et la suivante clôture reprend à
     * cette journée.
     */
    public function test_une_erreur_arrete_le_magasin_a_sa_journee_puis_la_cloture_reprend_a_cette_journee(): void
    {
        $magasin = $this->magasin();
        $this->travelTo('2026-10-05 10:00:00');
        $this->vendreUnTicket();
        $this->travelTo('2026-10-08 19:00:00');
        $this->app->instance(CloturerCaisseAction::class, new class(app(DocumentNumberingService::class), app(CalculateurLigne::class), app(RegistreFiscal::class), app(PanierDeCaisse::class)) extends CloturerCaisseAction
        {
            public function handle(Magasin $magasin, CarbonInterface|string $date): ClotureCaisse
            {
                if (CarbonImmutable::parse($date)->toDateString() === '2026-10-07') {
                    throw new RuntimeException('Panne du serveur de base de données.');
                }

                return parent::handle($magasin, $date);
            }
        });

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->jusquAu($magasin, now()), RuntimeException::class, 'Panne du serveur de base de données.');
        $this->assertSame(['2026-10-05', '2026-10-06'], ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture->toDateString())->all());

        $this->app->forgetInstance(CloturerCaisseAction::class);
        $reprise = app(CloturerLesJourneesAction::class)->jusquAu($magasin, now());

        $this->assertSame(['2026-10-07', '2026-10-08'], $reprise->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture->toDateString())->all());
    }

    /**
     * Les ventes et les clôtures se lisent sous MagasinScope : les journées
     * d'un magasin ne se clôturent que depuis ce magasin, jamais avec les
     * ventes d'un autre.
     */
    public function test_refuse_de_cloturer_un_autre_magasin_que_le_magasin_actif(): void
    {
        $autreMagasin = $this->magasin();
        $this->travelTo('2026-10-08 10:00:00');
        $this->vendreUnTicket();
        $magasin = $this->magasin();

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->jusquAu($autreMagasin, now()), LogicException::class, 'Les journées d\'un magasin ne se clôturent que depuis ce magasin.');
        $this->assertSame(0, ClotureCaisse::withoutGlobalScopes()->count());
        $this->assertTrue(app(CloturerLesJourneesAction::class)->jusquAu($magasin, now())->isEmpty());
    }
}
