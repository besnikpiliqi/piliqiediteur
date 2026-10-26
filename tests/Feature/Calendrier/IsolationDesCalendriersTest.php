<?php

namespace Tests\Feature\Calendrier;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Facturation\Jobs\EnvoyerDocumentJob;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CaissesDeLaSociete;
use App\Domain\Facturation\Services\FacturesAEncaisserDeLaSociete;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Societe\Pages\LesCaisses;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le calendrier de chaque magasin ne déborde jamais sur un autre : un
 * passage horaire de la clôture traite chaque magasin à son propre
 * minuit, dans son propre registre ; une lecture de la société ne lit que
 * les magasins de la portée de la personne, quel que soit leur fuseau ; un
 * document se date toujours dans le calendrier de son magasin, jamais
 * dans celui du contexte où on le lit.
 */
class IsolationDesCalendriersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une société à Paris et une autre en Martinique vendent le
     * 16 novembre : le passage du minuit de Paris ne clôt que la première,
     * celui du minuit de la Martinique que la seconde ; leurs registres,
     * leurs séries et leurs clôtures restent séparés.
     */
    public function test_un_passage_horaire_ne_clot_que_les_magasins_dont_le_minuit_est_passe(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 18:00'));
        $paris = $this->magasin(Territoire::Metropole, 'LYON1');
        $deParis = $this->vendreDans($paris);
        $martinique = $this->magasin(Territoire::Martinique, 'FDF1');
        $deLaMartinique = $this->vendreDans($martinique);

        $this->travelTo(self::enUtc('2026-11-16 23:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->assertSame([['2026-11-16'], []], [$this->journeesCloturees($paris), $this->journeesCloturees($martinique)]);

        $this->travelTo(self::enUtc('2026-11-17 04:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->assertSame([['2026-11-16'], ['2026-11-16']], [$this->journeesCloturees($paris), $this->journeesCloturees($martinique)]);

        $clotures = ClotureCaisse::withoutGlobalScope(MagasinScope::class)->whereIn('magasin_id', [$paris->id, $martinique->id])->orderBy('id')->get();
        $this->assertSame([[$paris->id, 'Z-LYON1-2026-000001'], [$martinique->id, 'Z-FDF1-2026-000001']], $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->magasin_id, $cloture->numero])->all());
        $this->assertSame(['TK-LYON1-2026-000001', 'TK-FDF1-2026-000001'], [$deParis->numero, $deLaMartinique->numero]);
        $this->assertSame(
            [$paris->id, $martinique->id],
            EnregistrementFiscal::withoutGlobalScopes()->whereIn('magasin_id', [$paris->id, $martinique->id])->where('type', (string) CodeEvenement::CloturePeriode->value)->orderBy('id')->pluck('magasin_id')->all(),
        );
        $this->assertSame([$clotures[0]->id, $clotures[1]->id], [
            app(TenantContext::class)->runAs($paris, fn (): ?int => $deParis->fresh()->cloture_caisse_id),
            app(TenantContext::class)->runAs($martinique, fn (): ?int => $deLaMartinique->fresh()->cloture_caisse_id),
        ]);
    }

    /**
     * Un administrateur limité au magasin de Paris ne lit, dans « Les
     * caisses » de la société, ni le magasin de la Guadeloupe de sa
     * société ni les magasins d'une autre société, quel que soit leur
     * fuseau.
     */
    public function test_les_caisses_de_la_societe_ne_lisent_que_les_magasins_de_la_portee_quel_que_soit_leur_fuseau(): void
    {
        $this->travelTo(self::enUtc('2026-07-15 02:00:00'));
        $paris = $this->magasin(Territoire::Metropole, 'PAR1', 'Boutique de Paris');
        $guadeloupe = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PTP1']);
        $administrateur = $this->administrateur($paris, [$paris->id]);
        $ailleurs = $this->magasin(Territoire::Martinique, 'FDF1', 'Boutique de Fort-de-France');

        $lus = array_map(fn (array $caisses): int => $caisses['magasin']->id, app(CaissesDeLaSociete::class)->pour($administrateur, null));
        $this->assertSame([$paris->id], $lus);
        $this->assertNotContains($guadeloupe->id, $lus);
        $this->assertNotContains($ailleurs->id, $lus);

        $this->dansLEspaceDeLaSociete($paris->societe, $administrateur);
        Livewire::test(LesCaisses::class)
            ->assertSee('Boutique de Paris (PAR1) — journée du 15/07/2026')
            ->assertDontSee(['Pointe-à-Pitre', 'PTP1', 'Fort-de-France', 'FDF1', 'heure de la Guadeloupe']);
    }

    /**
     * Le 16 novembre à 20:00 en Martinique, il est déjà le 17 à Paris et à
     * La Réunion : une facture de la Martinique échue le 16 n'est pas en
     * retard dans la lecture de la société, qui n'a pas de magasin ; un
     * devis de la Martinique valable jusqu'au 16 n'est pas expiré lu dans
     * le contexte du magasin de La Réunion ; son envoi, traité dans ce
     * contexte, part avec un PDF daté du 16.
     */
    public function test_le_calendrier_d_un_document_est_celui_de_son_magasin_et_jamais_celui_du_contexte(): void
    {
        Mail::fake();
        Queue::fake([EnvoyerDocumentJob::class]);
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $martinique = $this->magasin(Territoire::Martinique, 'FDF1', 'Boutique de Fort-de-France');
        $reunion = $this->ouvrirUnAutreMagasinSitue($martinique, Territoire::Reunion, ['nom' => 'Boutique de Saint-Denis', 'code' => 'RUN1']);
        app(TenantContext::class)->set($martinique);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(['delai_paiement_jours' => 0]), [['produit_id' => $this->produit()->id, 'quantite' => 1]]));
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]], ['date_validite' => '2026-11-16']);
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null);
        $devis = $devis->fresh();
        $this->assertSame('2026-11-16', $facture->date_echeance);

        $this->dansLEspaceDeLaSociete($martinique->societe, $this->proprietaireDe($martinique));
        $this->assertSame(0, app(FacturesAEncaisserDeLaSociete::class)->pour($this->proprietaireDe($martinique))[$martinique->id]['nombre_en_retard']);

        app(TenantContext::class)->set($reunion);
        $this->assertFalse($devis->estExpire());
        (new EnvoyerDocumentJob($martinique->id, $envoi->id))->handle();

        $this->assertSame(StatutEnvoi::Envoye, app(TenantContext::class)->runAs($martinique, fn (): StatutEnvoi => $envoi->fresh()->statut));
        $this->assertStringContainsString('Émis le 16/11/2026', $this->texteDuPdf(Mail::sent(DocumentMail::class)->sole()));
    }

    /**
     * Un passage horaire où aucune journée n'est due, une caisse
     * personnelle encore ouverte au jour même, ne verrouille aucun registre
     * : une vente n'attend jamais, chaque heure, le verrou de la clôture.
     * Sous SQLite, qui verrouille toute la base sans requête de verrou,
     * seul PostgreSQL le montre.
     */
    public function test_un_passage_horaire_sans_journee_due_ne_verrouille_aucun_registre(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite verrouille toute la base sans requête de verrou de ligne : ce garde ne se lit que sous PostgreSQL.');
        }

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 18:00'));
        $martinique = $this->magasin(Territoire::Martinique, 'FDF1');
        $paris = $this->magasin(Territoire::Metropole, 'LYON1');
        $vendeur = $this->membre($martinique, RoleMagasin::Vendeur);
        $this->vendreDans($martinique);
        $this->vendreDans($paris);
        $this->travelTo(self::enUtc('2026-11-17 04:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-17 00:30'));
        app(TenantContext::class)->runAs($martinique, fn (): Facture => self::vendreAuComptant($vendeur, Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]));
        $verrous = [];
        DB::listen(function (QueryExecuted $requete) use (&$verrous): void {
            if (str_contains($requete->sql, '"tetes_registre_fiscal"') && str_contains($requete->sql, 'for update')) {
                $verrous[] = $requete->sql;
            }
        });

        $this->travelTo(self::enUtc('2026-11-17 05:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->expectsOutputToContain('0 clôture(s) automatique(s) effectuée(s).')->assertSuccessful();

        $this->assertSame([], $verrous);
        $this->assertSame([['2026-11-16'], ['2026-11-16']], [$this->journeesCloturees($martinique), $this->journeesCloturees($paris)]);
    }

    /**
     * Une société a un magasin à Paris, un en Martinique et un à La
     * Réunion ; chacun vend toutes les demi-heures, de part et d'autre de
     * son minuit et du nouvel an, du 31 décembre à 19:30 UTC au 1er janvier
     * à 04:30 UTC, et les passages horaires de la clôture le suivent :
     * chacun clôt son 31 décembre à son minuit, numérote sur l'année de son
     * territoire, et le vérificateur ne signale rien. L'horloge ne fait
     * qu'avancer.
     */
    public function test_le_verificateur_ne_signale_rien_pour_trois_magasins_de_trois_fuseaux(): void
    {
        $this->travelTo(self::enUtc('2026-12-31 19:30:00'));
        $paris = $this->magasin(Territoire::Metropole, 'PAR1', 'Boutique de Paris');
        $martinique = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Martinique, ['nom' => 'Boutique de Fort-de-France', 'code' => 'FDF1']);
        $reunion = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Reunion, ['nom' => 'Boutique de Saint-Denis', 'code' => 'RUN1']);
        $magasins = ['PAR1' => $paris, 'FDF1' => $martinique, 'RUN1' => $reunion];
        $numeros = array_fill_keys(array_keys($magasins), []);

        for ($instant = self::enUtc('2026-12-31 19:30:00'); $instant->lessThanOrEqualTo(self::enUtc('2027-01-01 04:30:00')); $instant = $instant->addMinutes(30)) {
            $this->travelTo($instant);

            if ($instant->getTimestamp() % 3600 === 0) {
                $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
            }

            foreach ($magasins as $code => $magasin) {
                $numeros[$code][] = $this->vendreDans($magasin)->numero;
            }
        }

        $this->assertSame(array_fill_keys(array_keys($magasins), ['2026-12-31']), array_map(fn (Magasin $magasin): array => $this->journeesCloturees($magasin), $magasins));
        $this->assertSame(
            ['PAR1' => [7, 12], 'FDF1' => [17, 2], 'RUN1' => [1, 18]],
            array_map(static fn (array $suite): array => [count(preg_grep('/-2026-/', $suite)), count(preg_grep('/-2027-/', $suite))], $numeros),
        );
        app(TenantContext::class)->clear();
        $this->artisan('caisse:verifier', ['--complet' => true])
            ->expectsOutputToContain('Intégrité de la caisse sécurisée vérifiée : aucune anomalie.')
            ->assertExitCode(0);
    }

    /**
     * Le magasin de ce code, d'une nouvelle société validée à l'identité
     * légale complète, sur un établissement que le répertoire Sirene
     * factice situe dans ce territoire, magasin actif.
     */
    private function magasin(Territoire $territoire, string $code, ?string $nom = null): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee($territoire, ['nom' => $nom ?? 'Boutique '.$code, 'code' => $code]));
    }

    /**
     * Un produit à 12 € TTC, sans suivi de stock, au taux de TVA par
     * défaut du magasin actif.
     */
    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => app(TenantContext::class)->magasin()->tauxTvaParDefaut(), 'suivi_stock' => false]);
    }

    /**
     * Un ticket à un particulier, réglé en espèces à la caisse de ce
     * magasin par son responsable de test.
     */
    private function vendreDans(Magasin $magasin): Facture
    {
        return app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant(self::responsableDeTest($magasin), Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]));
    }

    /**
     * Les journées clôturées du magasin, dans l'ordre.
     *
     * @return list<string>
     */
    private function journeesCloturees(Magasin $magasin): array
    {
        return app(TenantContext::class)->runAs($magasin, fn (): array => ClotureCaisse::query()->orderBy('date_cloture')->pluck('date_cloture')->all());
    }

    /**
     * Le texte du PDF joint à l'e-mail, ses morceaux de ligne réunis par
     * des espaces simples.
     */
    private function texteDuPdf(DocumentMail $mail): string
    {
        $pdf = $mail->attachments()[0]->attachWith(static fn (): string => '', static fn (Closure $donnees): string => $donnees());
        $textes = array_merge(...array_map(fn (array $page): array => array_column($page['textes'], 'texte'), $this->pagesDuPdf($pdf)));

        return (string) preg_replace('/\s+/u', ' ', implode(' ', $textes));
    }
}
