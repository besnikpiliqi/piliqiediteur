<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\NoterBordereauDetaxeAction;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * La détaxe d'un voyageur résidant hors de l'Union (I3) : le ticket reste
 * à la TVA française, tel qu'il a été émis, et le magasin y note le n° du
 * bordereau de vente à l'exportation (BVE) émis dans PABLO ; la TVA ne se
 * régularisera qu'après le visa du bordereau. Le magasin de Lyon vend au
 * comptoir le 9 octobre 2026.
 */
class BordereauDetaxeTest extends TestCase
{
    use RefreshDatabase;

    private const AVERTISSEMENT = 'La détaxe exige plus de 100 € d\'achats TTC, éventuellement regroupés sur trois jours consécutifs.';

    /**
     * Le magasin de Lyon à l'identité légale complète et son propriétaire
     * connecté, le 9 octobre 2026 à 10 h 15 à Paris.
     */
    private function magasin(): Magasin
    {
        $this->travelTo('2026-10-09 10:15:00');
        $proprietaire = User::factory()->create();
        $magasin = $this->completerIdentiteLegale(app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire));
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        return $magasin;
    }

    /**
     * Un ticket émis d'un sac à main à ce prix TTC, vendu en France à un
     * particulier ; une facture pour un client professionnel.
     *
     * @param  array<string, mixed>  $nature
     */
    private function venteEmise(string $prixTtc, ?Client $client = null, array $nature = []): Facture
    {
        $produit = Produit::factory()->create(['nom' => 'Sac à main', 'prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client ?? Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], $nature));
    }

    private function noter(Facture $ticket, string $numero): BordereauDetaxe
    {
        return app(NoterBordereauDetaxeAction::class)->handle($ticket, $numero);
    }

    public function test_un_ticket_de_100_euros_est_detaxe_avec_un_avertissement(): void
    {
        $magasin = $this->magasin();
        $ticket = $this->venteEmise('100.00');

        $bordereau = $this->noter($ticket, '2026FR000123456');

        $this->assertSame(['100.00', '16.67'], [$ticket->total_ttc, $ticket->total_tva]);
        $this->assertSame(self::AVERTISSEMENT, $bordereau->avertissement());
        $bordereau->refresh();
        $this->assertSame([$magasin->id, $ticket->id, '2026FR000123456', $this->proprietaireDe($magasin)->id], [$bordereau->magasin_id, $bordereau->facture_id, $bordereau->numero, $bordereau->note_par]);
    }

    public function test_un_ticket_de_100_euros_01_est_detaxe_sans_avertissement(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('100.01');

        $bordereau = $this->noter($ticket, '2026FR000123456');

        $this->assertSame(['100.01', '16.67'], [$ticket->total_ttc, $ticket->total_tva]);
        $this->assertNull($bordereau->avertissement());
    }

    /**
     * Le ticket reste à la TVA française tel qu'il a été émis : ses
     * montants et son statut ne bougent pas.
     */
    public function test_noter_le_bordereau_ne_change_pas_le_ticket(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $avant = $ticket->fresh()->getAttributes();

        $this->noter($ticket, '2026FR000123456');

        $this->assertSame($avant, $ticket->fresh()->getAttributes());
    }

    public function test_le_numero_s_enregistre_sans_espaces_et_en_majuscules(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('250.00');

        $bordereau = $this->noter($ticket, '  2026fr000123456 ');

        $this->assertSame('2026FR000123456', $bordereau->fresh()->numero);
    }

    public function test_un_numero_qui_n_a_pas_la_forme_d_un_bordereau_est_refuse(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('250.00');

        $this->assertThrows(fn () => $this->noter($ticket, '2026 FR 0001'), InvalidArgumentException::class, 'Le n° du bordereau de détaxe compte de 4 à 30 lettres, chiffres ou tirets.');
        $this->assertThrows(fn () => $this->noter($ticket, 'BVE'), InvalidArgumentException::class, 'Le n° du bordereau de détaxe compte de 4 à 30 lettres, chiffres ou tirets.');

        $this->assertSame(0, BordereauDetaxe::query()->count());
    }

    public function test_une_facture_a_un_professionnel_ne_se_detaxe_pas(): void
    {
        $this->magasin();
        $facture = $this->venteEmise('250.00', Client::factory()->professionnel()->create());

        $this->assertThrows(
            fn () => $this->noter($facture, '2026FR000123456'),
            LogicException::class,
            'Le bordereau de détaxe se note sur un ticket de caisse émis : la détaxe rembourse un voyageur, jamais un professionnel.',
        );

        $this->assertStringStartsWith('FAC-', $facture->numero);
        $this->assertSame(0, BordereauDetaxe::query()->count());
    }

    public function test_un_ticket_vendu_en_franchise_en_base_ne_se_detaxe_pas(): void
    {
        $magasin = $this->magasin();
        $magasin->update(['regime_tva' => RegimeTva::FranchiseEnBase]);
        $ticket = $this->venteEmise('250.00');

        $this->assertThrows(
            fn () => $this->noter($ticket, '2026FR000123456'),
            LogicException::class,
            'Ce ticket a été vendu en franchise en base de TVA : sans TVA facturée, la détaxe n\'a rien à rembourser.',
        );

        $this->assertSame(0, BordereauDetaxe::query()->count());
    }

    /**
     * Un export expédié par le magasin est déjà exonéré : il n'a pas de
     * TVA française à rembourser.
     */
    public function test_un_ticket_d_export_ne_se_detaxe_pas(): void
    {
        $this->magasin();
        $export = $this->venteEmise('250.00', nature: ['nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US']);

        $this->assertThrows(
            fn () => $this->noter($export, '2026FR000123456'),
            LogicException::class,
            'Ce ticket, de nature « Export hors UE expédié par le magasin », ne porte aucune TVA française : la détaxe ne s\'y applique pas.',
        );

        $this->assertSame(0, BordereauDetaxe::query()->count());
    }

    public function test_un_brouillon_ne_se_detaxe_pas(): void
    {
        $this->magasin();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => '250.00', 'taux_tva' => 20])->id, 'quantite' => 1]]);

        $this->assertThrows(
            fn () => $this->noter($brouillon, '2026FR000123456'),
            LogicException::class,
            'Le bordereau de détaxe se note sur un ticket de caisse émis : la détaxe rembourse un voyageur, jamais un professionnel.',
        );

        $this->assertSame(0, BordereauDetaxe::query()->count());
    }

    public function test_un_magasin_suspendu_ne_note_plus_de_bordereau(): void
    {
        $magasin = $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $magasin->forceFill(['statut' => StatutMagasin::Suspendu])->save();

        $this->assertThrows(
            fn () => $this->noter($ticket, '2026FR000123456'),
            LogicException::class,
            'Ce magasin est suspendu : seules la consultation et les téléchargements restent possibles.',
        );

        $this->assertSame(0, BordereauDetaxe::query()->count());
    }

    /**
     * Une faute de saisie se corrige en notant le bon n° : le dernier fait
     * foi, et le premier reste.
     */
    public function test_un_second_numero_fait_foi_et_le_premier_reste(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $this->noter($ticket, '2026FR000123465');

        $this->noter($ticket, '2026FR000123456');

        $this->assertSame('2026FR000123456', $ticket->bordereauDetaxe()->sole()->numero);
        $this->assertSame(['2026FR000123465', '2026FR000123456'], $ticket->bordereauxDetaxe()->pluck('numero')->all());
    }

    public function test_noter_de_nouveau_le_numero_qui_fait_foi_est_refuse(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $this->noter($ticket, '2026FR000123456');

        $this->assertThrows(fn () => $this->noter($ticket, '2026fr000123456'), LogicException::class, 'Le bordereau n° 2026FR000123456 est déjà celui de ce ticket.');

        $this->assertSame(1, BordereauDetaxe::query()->count());
    }

    /**
     * Le n° s'affiche sur la fiche, jamais sur le ticket, qui reproduit le
     * document tel qu'émis.
     */
    public function test_le_numero_s_affiche_sur_la_fiche_et_pas_sur_le_pdf_du_ticket(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $this->noter($ticket, '2026FR000123465');
        $this->noter($ticket, '2026FR000123456');

        $fiche = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()]);
        $pdf = app(FacturePdfGenerator::class)->html($ticket->fresh());

        $fiche->assertSeeText('2026FR000123456')->assertSeeText('2026FR000123465, noté le 09/10/2026 à 10:15');
        $this->assertStringContainsString($ticket->numero, $pdf);
        $this->assertStringNotContainsString('2026FR000123456', $pdf);
    }

    /**
     * Depuis la fiche, le bouton note le n° et le dit, en avertissant quand
     * le ticket ne dépasse pas 100 € TTC.
     */
    public function test_noter_le_bordereau_depuis_la_fiche_avertit_sous_le_seuil(): void
    {
        $this->magasin();
        $ticket = $this->venteEmise('100.00');

        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])
            ->callAction('noter_bordereau_detaxe', ['numero' => '2026FR000123456'])
            ->assertHasNoActionErrors()
            ->assertNotified(Notification::make()->title('Bordereau de détaxe n° 2026FR000123456 noté.')->warning()->body(self::AVERTISSEMENT))
            ->assertSeeText('2026FR000123456');

        $this->assertSame('2026FR000123456', BordereauDetaxe::query()->sole()->numero);
    }

    /**
     * Le bouton est réservé à qui peut émettre (FacturePolicy::justifier()),
     * sur un ticket qui se détaxe : ni sur une facture à un professionnel,
     * ni pour un comptable.
     */
    public function test_le_bouton_noter_le_bordereau_est_reserve_a_qui_peut_emettre_un_ticket_detaxable(): void
    {
        $magasin = $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $facture = $this->venteEmise('250.00', Client::factory()->professionnel()->create());

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])->assertActionHidden('noter_bordereau_detaxe');
        $this->actingAs($this->membre($magasin, RoleMagasin::Comptable));

        Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()])->assertActionHidden('noter_bordereau_detaxe');
    }

    /**
     * En production, le compte de l'application n'a pas le droit de
     * modifier une table en ajout seul, que PostgreSQL exige pour un
     * SELECT ... FOR UPDATE : seul le ticket se verrouille.
     */
    public function test_noter_un_bordereau_ne_verrouille_aucun_bordereau(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite n\'écrit aucun verrou de ligne : ce test ne tourne que sous PostgreSQL.');
        }

        $this->magasin();
        $ticket = $this->venteEmise('250.00');
        $verrous = [];
        DB::listen(static function (QueryExecuted $requete) use (&$verrous): void {
            if (preg_match('/\bfor\s+(no\s+key\s+update|update|key\s+share|share)\b/i', $requete->sql) === 1) {
                $verrous[] = $requete->sql;
            }
        });

        $this->noter($ticket, '2026FR000123465');
        $this->noter($ticket, '2026FR000123456');

        $this->assertCount(2, $verrous);
        $this->assertSame([], array_filter($verrous, fn (string $sql): bool => ! str_starts_with($sql, 'select * from "factures"')));
    }

    public function test_un_bordereau_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $this->magasin();
        $bordereau = $this->noter($this->venteEmise('250.00'), '2026FR000123456');

        $this->assertThrows(
            fn () => $bordereau->forceFill(['numero' => '2026FR000999999'])->save(),
            DonneeFiscaleInalterableException::class,
            'Un n° de bordereau de détaxe noté ne se modifie ni ne se supprime : notez le bon n°, le dernier noté fait foi. Modification refusée : numero.',
        );
        $this->assertThrows(
            fn () => $bordereau->delete(),
            DonneeFiscaleInalterableException::class,
            'Un n° de bordereau de détaxe noté ne se modifie ni ne se supprime : notez le bon n°, le dernier noté fait foi. Suppression refusée.',
        );
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('bordereaux_detaxe')->where('id', $bordereau->id)->update(['numero' => 'X'])), QueryException::class, 'bordereaux_detaxe : table en ajout seul, modification refusée');
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('bordereaux_detaxe')->where('id', $bordereau->id)->delete()), QueryException::class, 'bordereaux_detaxe : table en ajout seul, suppression refusée');

        $this->assertSame('2026FR000123456', $bordereau->fresh()->numero);
    }
}
