<?php

namespace Tests\Feature\Calendrier;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\EReportingDesParticuliers;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Facturation\Services\PreuvesRequises;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\Territoire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Livewire\SelecteurDeClients;
use App\Filament\App\Resources\AvoirResource\Pages\CreateAvoir;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\EnregistrementFiscalResource\Pages\ListEnregistrementsFiscaux;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use App\Filament\App\Resources\PaiementResource\Pages\CreatePaiement;
use App\Filament\App\Widgets\FacturesApercu;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Chaque magasin vit à l'heure locale de son territoire (décision de
 * l'éditeur, Q1 du lot T2) : sa journée de caisse et sa clôture finissent
 * à son minuit, ses documents sont datés de son jour, ses numéros prennent
 * son année, ses échéances, ses devis, ses règlements, ses consultations
 * VIES, ses aperçus, ses relevés et son journal des événements comptent
 * ses jours. La Martinique et la Guadeloupe vivent à UTC−4, La Réunion à
 * UTC+4, toute l'année ; Paris, à UTC+1 l'hiver et UTC+2 l'été.
 */
class HeureLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * En Martinique, le ticket de 20:00 le 16 novembre (minuit UTC, déjà
     * 01:00 le 17 à Paris) est du 16 : il porte un numéro de 2026, son PDF,
     * sa fiche et l'avoir qui le cherche le datent du 16 ; le passage de la
     * clôture au minuit de Paris ne clôt rien, celui du minuit de la
     * Martinique clôt le 16 avec ses trois tickets et ferme la caisse de
     * Paul à 23:59:59, heure locale ; le ticket d'après est du 17, et le
     * vérificateur ne signale rien. L'horloge ne fait qu'avancer.
     */
    public function test_un_magasin_de_la_martinique_vend_a_20_heures_au_jour_local_et_cloture_a_minuit_local(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 18:30'));
        $magasin = $this->magasin(Territoire::Martinique, 'FDF1');
        $paul = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $premier = $this->vendre(12, $paul);

        $this->travelTo(self::enUtc('2026-11-16 23:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        app(TenantContext::class)->set($magasin);
        $this->assertSame([0, 0], [ClotureCaisse::query()->count(), ClotureCaissePersonnelle::query()->count()]);

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $deuxieme = $this->vendre(30, $paul);
        $this->assertSame(['2026-11-16', 'TK-FDF1-2026-000002'], [$magasin->calendrier()->jourDe($deuxieme->date_emission), $deuxieme->numero]);
        $this->assertStringContainsString('Émise le 16/11/2026', app(FacturePdfGenerator::class)->html($deuxieme->load('client', 'lignes', 'magasin')));
        $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));
        Livewire::test(ViewFacture::class, ['record' => $deuxieme->getRouteKey()])->assertSee('16/11/2026')->assertDontSee('17/11/2026');
        Livewire::test(CreateAvoir::class)
            ->fillForm(['recherche' => $deuxieme->numero])
            ->callFormComponentAction('recherche', 'chercher')
            ->assertSee('ticket '.$deuxieme->numero.' du 16/11/2026 à 20 h 00');

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 23:59:30'));
        $troisieme = $this->vendre(8, $paul);

        $this->travelTo(self::enUtc('2026-11-17 04:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        app(TenantContext::class)->set($magasin);
        $cloture = ClotureCaisse::query()->sole();
        $releve = ClotureCaissePersonnelle::query()->where('vendeur_id', $paul->id)->sole();
        $this->assertSame(['2026-11-16', 'Z-FDF1-2026-000001', 3], [$cloture->date_cloture, $cloture->numero, $cloture->nombre_ventes]);
        $this->assertSame(array_fill(0, 3, $cloture->id), [$premier->fresh()->cloture_caisse_id, $deuxieme->fresh()->cloture_caisse_id, $troisieme->fresh()->cloture_caisse_id]);
        $this->assertSame([ModeFermetureCaisse::Automatique, 3], [$releve->mode_fermeture, $releve->nombre_ventes]);
        $this->assertMemeInstant(self::enUtc('2026-11-17 03:59:59'), $releve->jusqu_au);
        $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));
        Livewire::test(ListClotureCaissePersonnelles::class)->assertTableColumnFormattedStateSet('jusqu_au', '16/11/2026 23:59', $releve);

        $this->travelTo(self::enUtc('2026-11-17 04:00:01'));
        $lendemain = $this->vendre(5, $paul);
        $this->assertSame(['2026-11-17', null], [$magasin->calendrier()->jourDe($lendemain->date_emission), $lendemain->fresh()->cloture_caisse_id]);
        $this->assertLeVerificateurNeSignaleRien();
    }

    /**
     * La journée de la Martinique clôturée à 21:00, heure locale, la caisse
     * refuse toute vente jusqu'au minuit de la Martinique (à 23:30, il est
     * déjà le lendemain à Paris), et vend de nouveau dès 00:00:01, au jour
     * suivant.
     */
    public function test_la_caisse_de_la_martinique_refuse_toute_vente_apres_sa_cloture_jusqu_a_minuit_local(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 18:00'));
        $magasin = $this->magasin(Territoire::Martinique, 'FDF1');
        $this->vendre(12);
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 21:00'));
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-11-16');

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 23:30'));
        $this->assertThrows(fn () => $this->vendre(12), LogicException::class, 'La journée du 16/11/2026 est clôturée : les ventes reprennent demain.');

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-17 00:00:01'));
        $this->assertSame('2026-11-17', $magasin->calendrier()->jourDe($this->vendre(12)->date_emission));
    }

    /**
     * À La Réunion, l'année change à 20:00 UTC le 31 décembre : le ticket
     * de 19:59:30 UTC est de 2026, le passage du minuit de La Réunion clôt
     * le 31 décembre, et la facture de 00:30, heure locale, prend un numéro
     * de 2027, la date du 1er janvier et l'échéance du 31 janvier ; au même
     * instant, le magasin de Paris de la même société numérote encore en
     * 2026. Le vérificateur ne signale rien.
     */
    public function test_un_magasin_de_la_reunion_numerote_sur_la_nouvelle_annee_des_minuit_local(): void
    {
        $this->travelTo(self::enUtc('2026-12-31 19:59:30'));
        $reunion = $this->magasin(Territoire::Reunion, 'RUN1');
        $paris = $this->ouvrirUnAutreMagasinSitue($reunion, Territoire::Metropole, ['nom' => 'Boutique de Paris', 'code' => 'PAR1']);
        app(TenantContext::class)->set($reunion);
        $ticket = $this->vendre(21.70);
        $this->assertSame(['TK-RUN1-2026-000001', '2026-12-31'], [$ticket->numero, $reunion->calendrier()->jourDe($ticket->date_emission)]);

        $this->travelTo(self::enUtc('2026-12-31 20:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $cloture = app(TenantContext::class)->runAs($reunion, fn (): ClotureCaisse => ClotureCaisse::query()->sole());
        $this->assertSame(['2026-12-31', 'Z-RUN1-2026-000001', 1], [$cloture->date_cloture, $cloture->numero, $cloture->nombre_ventes]);

        $this->travelTo(self::enUtc('2026-12-31 20:30:00'));
        $facture = app(TenantContext::class)->runAs($reunion, fn (): Facture => $this->emettreUneFacture(Client::factory()->professionnel()->create(['delai_paiement_jours' => 30]), 21.70));
        $deParis = app(TenantContext::class)->runAs($paris, fn (): Facture => $this->emettreUneFacture(Client::factory()->professionnel()->create(['delai_paiement_jours' => 30]), 24));

        $this->assertSame(['FAC-RUN1-2027-000001', '2027-01-01', '2027-01-31'], [$facture->numero, $reunion->calendrier()->jourDe($facture->date_emission), $facture->date_echeance]);
        $this->assertSame(['FAC-PAR1-2026-000001', '2026-12-31', '2027-01-30'], [$deParis->numero, $paris->calendrier()->jourDe($deParis->date_emission), $deParis->date_echeance]);
        $this->assertLeVerificateurNeSignaleRien();
    }

    /**
     * À 01:00 UTC le 1er janvier 2027, il est 21:00 le 31 décembre en
     * Guadeloupe : le ticket et la facture d'achat validée à cet instant
     * prennent un numéro de 2026, comme une autre facture d'achat à 23:30,
     * heure locale ; le vérificateur ne signale rien.
     */
    public function test_une_vente_du_31_decembre_a_21_heures_en_guadeloupe_reste_dans_l_annee_qui_finit(): void
    {
        $this->travelTo(self::enUtc('2027-01-01 01:00:00'));
        $magasin = $this->magasin(Territoire::Guadeloupe, 'PTP1');

        $ticket = $this->vendre(21.70);
        $achat = $this->validerUnAchat();

        $this->assertSame(['TK-PTP1-2026-000001', '2026-12-31', 'FA-PTP1-2026-000001'], [$ticket->numero, $magasin->calendrier()->jourDe($ticket->date_emission), $achat->numero]);
        $this->travelTo(self::enUtc('2027-01-01 03:30:00'));
        $this->assertSame('FA-PTP1-2026-000002', $this->validerUnAchat()->numero);
        $this->assertLeVerificateurNeSignaleRien();
    }

    /**
     * Une société a un magasin à Paris et un en Guadeloupe : le passage du
     * minuit de Paris ne clôt que Paris ; à 23:30 UTC, le ticket de Paris
     * est du 15 juillet, celui de la Guadeloupe du 14 ; le passage du
     * minuit de la Guadeloupe clôt son 14 juillet avec ses deux ventes.
     * Chaque magasin numérote avec son code, et l'e-reporting de chacun
     * compte ses propres journées. L'horloge ne fait qu'avancer.
     */
    public function test_une_societe_avec_un_magasin_a_paris_et_un_en_guadeloupe_suit_deux_calendriers(): void
    {
        $this->travelTo(self::enUtc('2026-07-14 21:30:00'));
        $paris = $this->magasin(Territoire::Metropole, 'PAR1');
        $guadeloupe = $this->ouvrirUnAutreMagasinSitue($paris, Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PTP1']);
        $vendreDans = fn (Magasin $magasin, float $prixTtc): Facture => app(TenantContext::class)->runAs($magasin, fn (): Facture => $this->vendre($prixTtc));
        $parisSoir = $vendreDans($paris, 12);
        $guadeloupeSoir = $vendreDans($guadeloupe, 10.85);

        $this->travelTo(self::enUtc('2026-07-14 22:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->assertSame([['2026-07-14'], []], [$this->journeesCloturees($paris), $this->journeesCloturees($guadeloupe)]);

        $this->travelTo(self::enUtc('2026-07-14 23:30:00'));
        $parisNuit = $vendreDans($paris, 24);
        $guadeloupeNuit = $vendreDans($guadeloupe, 21.70);
        $this->assertSame(['2026-07-15', '2026-07-14'], [$paris->calendrier()->jourDe($parisNuit->date_emission), $guadeloupe->calendrier()->jourDe($guadeloupeNuit->date_emission)]);

        $this->travelTo(self::enUtc('2026-07-15 04:00:00'));
        $this->artisan('app:cloturer-caisses-automatiquement')->assertSuccessful();
        $this->assertSame([['2026-07-14'], ['2026-07-14']], [$this->journeesCloturees($paris), $this->journeesCloturees($guadeloupe)]);

        $clotureDeParis = app(TenantContext::class)->runAs($paris, fn (): ClotureCaisse => ClotureCaisse::query()->sole());
        $clotureDeLaGuadeloupe = app(TenantContext::class)->runAs($guadeloupe, fn (): ClotureCaisse => ClotureCaisse::query()->sole());
        $this->assertSame(
            [[$clotureDeParis->id, null], [$clotureDeLaGuadeloupe->id, $clotureDeLaGuadeloupe->id]],
            [
                app(TenantContext::class)->runAs($paris, fn (): array => [$parisSoir->fresh()->cloture_caisse_id, $parisNuit->fresh()->cloture_caisse_id]),
                app(TenantContext::class)->runAs($guadeloupe, fn (): array => [$guadeloupeSoir->fresh()->cloture_caisse_id, $guadeloupeNuit->fresh()->cloture_caisse_id]),
            ],
        );
        $this->assertSame(
            ['TK-PAR1-2026-000001', 'TK-PAR1-2026-000002', 'TK-PTP1-2026-000001', 'TK-PTP1-2026-000002', 'Z-PAR1-2026-000001', 'Z-PTP1-2026-000001'],
            [$parisSoir->numero, $parisNuit->numero, $guadeloupeSoir->numero, $guadeloupeNuit->numero, $clotureDeParis->numero, $clotureDeLaGuadeloupe->numero],
        );
        $apercuDeParis = app(TenantContext::class)->runAs($paris, fn () => app(EReportingDesParticuliers::class)->apercu($clotureDeParis));
        $apercuDeLaGuadeloupe = app(TenantContext::class)->runAs($guadeloupe, fn () => app(EReportingDesParticuliers::class)->apercu($clotureDeLaGuadeloupe));
        $this->assertSame([['2026-07-14', 1000], ['2026-07-14', 3000]], [[$apercuDeParis->journee, $apercuDeParis->totalHt()], [$apercuDeLaGuadeloupe->journee, $apercuDeLaGuadeloupe->totalHt()]]);
    }

    /**
     * Une facture émise en Martinique le 16 novembre à 20:00, heure locale
     * (déjà le 17 à Paris), payable à 30 jours, échoit le 16 décembre ; elle
     * ne passe pas en retard à 20:15 le 16, heure locale, mais au passage
     * qui suit le minuit de la Martinique. Sa relance, due un jour après
     * l'échéance, ne part pas à 05:00, heure locale, part à 09:00 et pas
     * une seconde fois à 10:00 ; un autre magasin de la Martinique, dont
     * les relances s'activent après le passage de 09:00, est relancé à
     * 10:00 ; un troisième, dont elles s'activent après celui de 11:00,
     * n'est pas relancé à 12:00.
     */
    public function test_l_echeance_le_retard_et_les_relances_suivent_le_jour_local(): void
    {
        Mail::fake();
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $premier = $this->magasin(Territoire::Martinique, 'FDF1');
        $deuxieme = $this->ouvrirUnAutreMagasinSitue($premier, Territoire::Martinique, ['nom' => 'Boutique FDF2', 'code' => 'FDF2']);
        $troisieme = $this->ouvrirUnAutreMagasinSitue($premier, Territoire::Martinique, ['nom' => 'Boutique FDF3', 'code' => 'FDF3']);
        $premier->update(['relances_automatiques' => true, 'delais_relance' => [1, 15, 30]]);
        $deuxieme->update(['relances_automatiques' => false, 'delais_relance' => [1, 15, 30]]);
        $troisieme->update(['relances_automatiques' => false, 'delais_relance' => [1, 15, 30]]);
        [$facture, $deLaDeuxieme, $deLaTroisieme] = array_map(fn (Magasin $magasin): Facture => app(TenantContext::class)->runAs($magasin, fn (): Facture => $this->emettreUneFacture(Client::factory()->professionnel()->create(['delai_paiement_jours' => 30, 'email' => strtolower($magasin->code).'@exemple.fr']), 21.70)), [$premier, $deuxieme, $troisieme]);
        $this->assertSame('2026-12-16', $facture->date_echeance);

        $this->travelTo(self::enUtc('2026-12-17 00:15:00'));
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->assertSame(StatutFacture::Emise, app(TenantContext::class)->runAs($premier, fn (): StatutFacture => $facture->fresh()->statut));

        $this->travelTo(self::enUtc('2026-12-17 04:15:00'));
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $this->assertSame(StatutFacture::EnRetard, app(TenantContext::class)->runAs($premier, fn (): StatutFacture => $facture->fresh()->statut));

        $this->relancerA(self::enUtc('2026-12-17 09:00:00'));
        $this->assertSame([[], [], []], $this->relancesDe($facture, $deLaDeuxieme, $deLaTroisieme));
        $this->relancerA(self::enUtc('2026-12-17 13:00:00'));
        $this->assertSame([[1], [], []], $this->relancesDe($facture, $deLaDeuxieme, $deLaTroisieme));

        $this->travelTo(self::enUtc('2026-12-17 13:30:00'));
        $deuxieme->update(['relances_automatiques' => true]);
        $this->relancerA(self::enUtc('2026-12-17 14:00:00'));
        $this->assertSame([[1], [1], []], $this->relancesDe($facture, $deLaDeuxieme, $deLaTroisieme));

        $this->travelTo(self::enUtc('2026-12-17 15:30:00'));
        $troisieme->update(['relances_automatiques' => true]);
        $this->relancerA(self::enUtc('2026-12-17 16:00:00'));
        $this->assertSame([[1], [1], []], $this->relancesDe($facture, $deLaDeuxieme, $deLaTroisieme));
    }

    /**
     * Deux devis valables jusqu'au 31 décembre 2026 : à 20:30 UTC, celui de
     * La Réunion, déjà au 1er janvier, expire, pas celui de la Guadeloupe,
     * encore au 31 ; celui-ci expire au passage qui suit son minuit.
     */
    public function test_un_devis_expire_le_lendemain_de_sa_validite_au_jour_local(): void
    {
        $this->travelTo(self::aParis('2026-12-15 10:00:00'));
        $reunion = $this->magasin(Territoire::Reunion, 'RUN1');
        $guadeloupe = $this->ouvrirUnAutreMagasinSitue($reunion, Territoire::Guadeloupe, ['nom' => 'Boutique de Pointe-à-Pitre', 'code' => 'PTP1']);
        $devisDe = fn (Magasin $magasin): Devis => app(TenantContext::class)->runAs($magasin, fn (): Devis => app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(
            self::auteur(),
            Client::factory()->create(),
            [['produit_id' => $this->produit(21.70)->id, 'quantite' => 1]],
            ['date_validite' => '2026-12-31'],
        )));
        $statuts = fn (Devis ...$devis): array => array_map(fn (Devis $un): StatutDevis => app(TenantContext::class)->runAs($un->magasin, fn (): StatutDevis => $un->fresh()->statut), $devis);
        $deLaReunion = $devisDe($reunion);
        $deLaGuadeloupe = $devisDe($guadeloupe);

        $this->travelTo(self::enUtc('2026-12-31 20:30:00'));
        $this->artisan('app:expirer-devis')->assertSuccessful();
        $this->assertSame([StatutDevis::Expire, StatutDevis::Envoye], $statuts($deLaReunion, $deLaGuadeloupe));

        $this->travelTo(self::enUtc('2027-01-01 04:30:00'));
        $this->artisan('app:expirer-devis')->assertSuccessful();
        $this->assertSame([StatutDevis::Expire, StatutDevis::Expire], $statuts($deLaReunion, $deLaGuadeloupe));
    }

    /**
     * À 21:00 UTC le 14 juillet, il est 01:00 le 15 à La Réunion et 17:00
     * le 14 en Martinique : un règlement reçu le 15 est accepté à La
     * Réunion et refusé en Martinique ; le choix de la date propose le jour
     * de chaque magasin et refuse le lendemain.
     */
    public function test_un_reglement_recu_le_jour_local_est_accepte_et_le_lendemain_local_refuse(): void
    {
        $this->travelTo(self::aParis('2026-07-14 10:00:00'));
        $reunion = $this->magasin(Territoire::Reunion, 'RUN1');
        $martinique = $this->ouvrirUnAutreMagasinSitue($reunion, Territoire::Martinique, ['nom' => 'Boutique de Fort-de-France', 'code' => 'FDF1']);
        $factureDe = fn (Magasin $magasin): Facture => app(TenantContext::class)->runAs($magasin, fn (): Facture => $this->emettreUneFacture(Client::factory()->professionnel()->create(), 21.70));
        $payer = fn (Facture $facture): Closure => fn () => app(TenantContext::class)->runAs($facture->magasin, fn () => app(EnregistrerPaiementAction::class)->handle(self::auteur(), $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => '10.00',
            'recu_le' => '2026-07-15',
        ], [['facture_id' => $facture->id, 'montant' => '10.00']]));
        $deLaReunion = $factureDe($reunion);
        $deLaMartinique = $factureDe($martinique);

        $this->travelTo(self::enUtc('2026-07-14 21:00:00'));

        $this->assertSame('2026-07-15', $payer($deLaReunion)()->recu_le);
        $this->assertThrows($payer($deLaMartinique), LogicException::class, 'Un paiement ne peut pas être reçu à une date future.');
        foreach ([[$reunion, '2026-07-15'], [$martinique, '2026-07-14']] as [$magasin, $jour]) {
            $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));
            Livewire::test(CreatePaiement::class)
                ->assertFormSet(['recu_le' => $jour])
                ->fillForm(['recu_le' => Calendrier::ajouterJours($jour, 1)])
                ->call('create')
                ->assertHasFormErrors(['recu_le' => 'before_or_equal']);
        }
    }

    /**
     * En Martinique, l'attestation de réception d'une livraison du
     * 12 octobre est attendue au plus tard le 10 novembre : le délai n'est
     * dépassé qu'au minuit de la Martinique, quand Paris est déjà le 11
     * depuis cinq heures. Une vérification VIES faite à 22:00 le
     * 16 novembre, heure locale (le 17 à Paris), compte pour le 16 : une
     * livraison intracommunautaire émise le soir même la retient.
     */
    public function test_la_consultation_vies_et_le_delai_des_preuves_comptent_le_jour_local(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-10-12 10:00'));
        $magasin = $this->magasin(Territoire::Martinique, 'FDF1');
        $allemand = Client::factory()->etranger('DE', 'DE123456789')->create(['code_postal' => '10115', 'ville' => 'Berlin']);
        $this->consulterVies($allemand);
        $livraison = $this->emettreUneLivraisonIntracommunautaire($allemand, '2026-10-12');
        $attestation = static fn (): string => collect(app(PreuvesRequises::class)->manquantes($livraison->fresh()))->sole(static fn (string $preuve): bool => str_contains($preuve, 'attendue au plus tard'));

        $this->travelTo(self::enUtc('2026-11-11 03:30:00'));
        $this->assertStringEndsWith('attendue au plus tard le 10/11/2026', $attestation());
        $this->travelTo(self::enUtc('2026-11-11 04:30:00'));
        $this->assertStringEndsWith('attendue au plus tard le 10/11/2026 : délai dépassé', $attestation());

        $this->travelTo(self::enUtc('2026-11-17 02:00:00'));
        $consultation = $this->consulterVies($allemand);
        $this->assertSame(
            [[$consultation->id], []],
            [ConsultationVies::query()->duJour($magasin->calendrier(), '2026-11-16')->whereKey($consultation->id)->pluck('id')->all(), ConsultationVies::query()->duJour($magasin->calendrier(), '2026-11-17')->whereKey($consultation->id)->pluck('id')->all()],
        );
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 23:00'));
        $this->assertSame($consultation->id, $this->emettreUneLivraisonIntracommunautaire($allemand, '2026-11-16')->consultation_vies_id);
    }

    /**
     * Le journal des événements d'un magasin de la Martinique nomme son
     * heure dans sa colonne et dans l'en-tête de son export, et s'y lit à
     * l'heure locale, la colonne signée restant en UTC ; 02:30 le
     * 25 octobre n'y porte aucune mention de l'heure répétée, que la
     * Martinique ne connaît pas. Les libellés d'un magasin de Paris restent
     * « (heure de Paris) ».
     */
    public function test_le_journal_des_evenements_et_son_export_nomment_l_heure_du_territoire(): void
    {
        $this->travelTo(self::enUtc('2026-10-25 06:30:00'));
        $martinique = $this->magasin(Territoire::Martinique, 'FDF1');
        $evenement = DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ParametreDeConformite, ['parametre' => 'regime_tva', 'ancienne_valeur' => null, 'nouvelle_valeur' => 'reel_simplifie']));
        $this->travelTo(self::enUtc('2026-10-26 12:00:00'));
        $this->dansLeMagasin($martinique, $this->proprietaireDe($martinique));

        Livewire::test(ListEnregistrementsFiscaux::class)
            ->assertSee('Horodatage (heure de la Martinique)')
            ->assertTableColumnFormattedStateSet('horodatage', '25/10/2026 02:30:00', $evenement);
        $lignes = $this->lignesDuCsv(Livewire::test(ListEnregistrementsFiscaux::class)->callAction('exporter_journal', ['du' => '2026-10-25', 'au' => '2026-10-26']));
        $ligneDeLEvenement = collect($lignes)->sole(fn (array $ligne): bool => $ligne[0] === (string) $evenement->sequence);
        $this->assertSame(['Horodatage (heure de la Martinique)', '25/10/2026 02:30:00', '20261025063000Z'], [$lignes[0][1], $ligneDeLEvenement[1], $ligneDeLEvenement[2]]);

        $paris = $this->magasin(Territoire::Metropole, 'PAR1');
        DB::transaction(fn (): EnregistrementFiscal => app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees));
        $this->dansLeMagasin($paris, $this->proprietaireDe($paris));
        Livewire::test(ListEnregistrementsFiscaux::class)->assertSee('Horodatage (heure de Paris)')->assertDontSee('heure de la Martinique');
        $this->assertSame('Horodatage (heure de Paris)', ExporterJournalDesEvenementsAction::colonnes($paris->calendrier())[1]);
    }

    /**
     * En Martinique, le 1er décembre commence à 04:00 UTC : la vente de
     * 23:30 le 30 novembre, heure locale (déjà le 1er à Paris), n'entre pas
     * dans le mois qui commence, et le dernier achat de son client
     * s'affiche au 30 novembre ; la caisse que Paul ferme à 22:00, heure
     * locale, part du minuit de la Martinique.
     */
    public function test_les_apercus_le_dernier_achat_et_les_releves_comptent_le_jour_local(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-30 21:00'));
        $magasin = $this->magasin(Territoire::Martinique, 'FDF1');
        $paul = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $this->vendre(10.85, $paul);
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-30 22:00'));
        $releve = app(FermerSaCaisseAction::class)->handle($paul, $paul);
        $this->assertMemeInstant(self::aLHeureDe(Territoire::Martinique, '2026-11-30 00:00'), $releve->periode_debut);

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-30 23:30'));
        $client = Client::factory()->create();
        self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $this->produit(10.85)->id, 'quantite' => 1]]);
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-12-01 00:30'));
        $this->vendre(21.70);
        $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));

        Livewire::test(FacturesApercu::class)->assertSeeInOrder(['Ce mois-ci', '20,00 € HT', '21,70 € TTC · 1 facture']);
        Livewire::test(SelecteurDeClients::class)->assertTableColumnFormattedStateSet('factures_max_date_emission', '30/11/2026', $client->getKey());
        Livewire::test(ListClotureCaissePersonnelles::class)->assertTableColumnFormattedStateSet('periode_debut', '30/11/2026 00:00', $releve);
    }

    /**
     * La connexion précédente s'affiche dans le calendrier du compte : à
     * l'heure de la Martinique pour un vendeur de son magasin, à l'heure de
     * Paris pour le propriétaire, sans mention.
     */
    public function test_la_connexion_precedente_s_affiche_a_l_heure_du_compte(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 20:00'));
        $magasin = $this->magasin(Territoire::Martinique, 'FDF1');
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $proprietaire = $this->proprietaireDe($magasin);
        $connexionPrecedente = function (User $compte): ?string {
            $this->flushSession();
            app(SuiviDesConnexions::class)->noterLaConnexion($compte);

            return collect(session('filament.notifications', []))->pluck('title')->first(fn (string $titre): bool => str_starts_with($titre, 'Votre connexion précédente'));
        };
        $connexionPrecedente($vendeur);
        $connexionPrecedente($proprietaire);

        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-17 09:00'));

        $this->assertSame(
            ['Votre connexion précédente : le 16/11/2026 à 20:00', 'Votre connexion précédente : le 17/11/2026 à 01:00'],
            [$connexionPrecedente($vendeur->refresh()), $connexionPrecedente($proprietaire->refresh())],
        );
    }

    /**
     * La liste des factures d'un magasin lit son calendrier une fois, pas
     * à chaque ligne : elle lit les magasins autant de fois pour une
     * facture que pour douze.
     */
    public function test_les_listes_d_un_magasin_ne_relisent_pas_son_territoire_a_chaque_ligne(): void
    {
        $this->travelTo(self::aLHeureDe(Territoire::Martinique, '2026-11-16 10:00'));
        $magasin = $this->magasin(Territoire::Martinique, 'FDF1');
        $this->dansLeMagasin($magasin, self::responsableDeTest($magasin));
        $this->emettreUneFacture(Client::factory()->professionnel()->create(), 21.70);
        $pourUne = $this->lecturesDesMagasins(fn (): Testable => Livewire::test(ListFactures::class));

        foreach (range(1, 11) as $rang) {
            $this->emettreUneFacture(Client::factory()->professionnel()->create(), 21.70);
        }

        $this->assertSame($pourUne, $this->lecturesDesMagasins(fn (): Testable => Livewire::test(ListFactures::class)->assertCountTableRecords(12)));
    }

    /**
     * Le magasin de ce code, d'une société validée à l'identité légale
     * complète, sur un établissement que le répertoire Sirene factice situe
     * dans ce territoire, magasin actif.
     */
    private function magasin(Territoire $territoire, string $code): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSocieteSituee($territoire, ['nom' => 'Boutique '.$code, 'code' => $code]));
    }

    /**
     * Un produit de ce prix TTC, sans suivi de stock, au taux de TVA par
     * défaut du magasin actif : 20 % à Paris, 8,5 % aux Antilles et à La
     * Réunion.
     */
    private function produit(float $prixTtc): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => app(TenantContext::class)->magasin()->tauxTvaParDefaut(), 'suivi_stock' => false]);
    }

    /**
     * Un ticket de ce montant à un particulier, réglé en espèces à la
     * caisse du magasin actif, par ce vendeur ou par l'auteur des actions.
     */
    private function vendre(float $prixTtc, ?User $vendeur = null): Facture
    {
        return self::vendreAuComptant($vendeur ?? self::auteur(), Client::factory()->create(), [['produit_id' => $this->produit($prixTtc)->id, 'quantite' => 1]]);
    }

    private function emettreUneFacture(Client $client, float $prixTtc): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit($prixTtc)->id, 'quantite' => 1]]));
    }

    private function emettreUneLivraisonIntracommunautaire(Client $client, string $livraison): Facture
    {
        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $this->produit(25)->id, 'quantite' => 12]], [
            'nature_operation' => NatureOperation::LivraisonIntracommunautaire,
            'livraison_adresse' => 'Musterstraße 1',
            'livraison_code_postal' => '10115',
            'livraison_ville' => 'Berlin',
            'livraison_pays' => 'DE',
            'livraison_date' => $livraison,
            'transport_par' => TransportPar::Acquereur,
        ]));
    }

    /**
     * Une vérification VIES valide du numéro de TVA du client, à l'instant
     * présent.
     */
    private function consulterVies(Client $client): ConsultationVies
    {
        return ConsultationVies::forceCreate(['client_id' => $client->id, 'numero_tva' => $client->numero_tva, 'valide' => true, 'identifiant_consultation' => 'WAPIAAAAW1234567', 'consultee_le' => now(), 'reponse' => '{"valid":true}']);
    }

    /**
     * Une facture d'achat de cinq articles, validée dans le magasin actif.
     */
    private function validerUnAchat(): FactureAchat
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 24, 'taux_tva' => app(TenantContext::class)->magasin()->tauxTvaParDefaut(), 'suivi_stock' => true]);

        return app(ValiderFactureAchatAction::class)->handle(self::auteur(), app(CreerFactureAchatAction::class)->handle(self::auteur(), Fournisseur::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 10],
        ]));
    }

    /**
     * Le passage des relances automatiques à cet instant.
     */
    private function relancerA(CarbonImmutable $instant): void
    {
        $this->travelTo($instant);
        app(TenantContext::class)->clear();
        $this->artisan('app:relancer-factures-impayees')->assertSuccessful();
    }

    /**
     * Les niveaux des relances envoyées de chaque facture, dans l'ordre.
     *
     * @return list<list<int>>
     */
    private function relancesDe(Facture ...$factures): array
    {
        return array_map(static fn (Facture $facture): array => EnvoiDocument::withoutGlobalScopes()
            ->where('magasin_id', $facture->magasin_id)
            ->where('document_type', $facture->getMorphClass())
            ->where('document_id', $facture->id)
            ->where('nature', NatureEnvoi::Relance)
            ->orderBy('niveau_relance')
            ->pluck('niveau_relance')
            ->all(), $factures);
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
     * Le vérificateur de la caisse sécurisée, sur chaque magasin qui a des
     * données fiscales, ne signale aucune anomalie.
     */
    private function assertLeVerificateurNeSignaleRien(): void
    {
        app(TenantContext::class)->clear();
        $this->artisan('caisse:verifier', ['--complet' => true])
            ->expectsOutputToContain('Intégrité de la caisse sécurisée vérifiée : aucune anomalie.')
            ->assertSuccessful();
    }

    /**
     * Le nombre de requêtes qui lisent la table des magasins pendant ce
     * rendu.
     */
    private function lecturesDesMagasins(Closure $rendu): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rendu();
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($requetes, static fn (array $requete): bool => str_contains($requete['query'], '"magasins"')));
    }

    /**
     * Les lignes du fichier CSV téléchargé par le composant, sans sa marque
     * d'ordre des octets.
     *
     * @return list<list<string>>
     */
    private function lignesDuCsv(Testable $composant): array
    {
        $contenu = base64_decode($composant->effects['download']['content']);
        $this->assertStringStartsWith("\u{FEFF}", $contenu);
        $flux = fopen('php://memory', 'r+b');
        fwrite($flux, substr($contenu, 3));
        rewind($flux);
        $lignes = [];

        while (($ligne = fgetcsv($flux, null, ';', '"', '')) !== false) {
            $lignes[] = $ligne;
        }

        return $lignes;
    }
}
