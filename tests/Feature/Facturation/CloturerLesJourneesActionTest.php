<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\FermerLesCaissesPersonnellesAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use App\Domain\Facturation\Services\JourneesDeCaisse;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\RecapitulatifDeCloture;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Mockery\MockInterface;
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

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    public function test_un_magasin_qui_n_a_jamais_vendu_n_a_aucune_journee_a_cloturer(): void
    {
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));
        $magasin = $this->magasin();

        $clotures = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, Calendrier::deParis()->aujourdhui());

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
        $this->travelTo(self::aParis('2026-10-07 10:00:00'));
        $venteDu7 = $this->vendreUnTicket();
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        $clotures = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, Calendrier::deParis()->aujourdhui());

        $this->assertSame(
            [['2026-10-07', 'Z-MABOUT-2026-000001', 1, '12.00'], ['2026-10-08', 'Z-MABOUT-2026-000002', 0, '0.00'], ['2026-10-09', 'Z-MABOUT-2026-000003', 0, '0.00']],
            $clotures->map(fn (ClotureCaisse $cloture): array => [$cloture->date_cloture, $cloture->numero, $cloture->nombre_ventes, $cloture->total_ttc])->all(),
        );
        $this->assertSame($clotures->first()->id, $venteDu7->fresh()->cloture_caisse_id);
    }

    public function test_reprend_au_lendemain_de_la_derniere_journee_cloturee(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $this->vendreUnTicket();
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-10-05');
        $this->travelTo(self::aParis('2026-10-08 19:00:00'));

        $journees = app(CloturerLesJourneesAction::class)->journeesEnAttente(Calendrier::deParis()->aujourdhui());

        $this->assertSame(['2026-10-06', '2026-10-07', '2026-10-08'], $journees->all());
    }

    /**
     * Fermé le 06/10 au soir et rouvert le 08/10 : le magasin n'a aucun
     * ticket Z pour le 07/10, ni pour aucun jour hors de ses périodes
     * d'activité ; les jours de fermeture et de réouverture en ont un.
     */
    public function test_les_journees_a_cloturer_ne_sont_que_celles_des_periodes_d_activite(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $this->vendreUnTicket();
        PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole()->forceFill(['fin' => self::aParis('2026-10-06 18:00:00'), 'motif_fin' => MotifFermeture::Temporaire])->save();
        (new PeriodeActivite)->forceFill(['magasin_id' => $magasin->id, 'debut' => self::aParis('2026-10-08 09:00:00')])->save();
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));

        $clotures = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, Calendrier::deParis()->aujourdhui());

        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-08', '2026-10-09'], $clotures->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
        $this->assertTrue(app(CloturerLesJourneesAction::class)->journeesEnAttente(Calendrier::deParis()->aujourdhui())->isEmpty());
    }

    /**
     * C27 : un ticket Z inconnu du registre, daté après la dernière journée
     * qu'il a clôturée, arrête la clôture du magasin au lieu de lui faire
     * sauter des journées : refus qui le nomme, aucun ticket Z produit,
     * l'éditeur alerté au journal de l'application et l'anomalie inscrite
     * au journal des événements (JET 90).
     */
    public function test_un_ticket_z_inconnu_du_registre_arrete_la_cloture_au_lieu_de_la_desorganiser(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $this->vendreUnTicket();
        $this->travelTo(self::aParis('2026-10-06 00:00:30'));
        $premier = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, '2026-10-05')->sole();
        $numero = str_replace('-000001', '-000002', (string) $premier->numero);
        $this->insererUnFauxTicketZ($magasin, '2026-10-07', $numero, self::aParis('2026-10-08 00:00:30'));
        $this->travelTo(self::aParis('2026-10-09 00:00:30'));
        $refus = "Le ticket Z {$numero} du 07/10/2026 n'a pas été produit par la clôture de sa journée : la clôture du magasin s'arrête jusqu'à l'examen de l'éditeur.";
        Log::spy();

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, '2026-10-08'), LogicException::class, $refus);

        $this->assertSame(['2026-10-05', '2026-10-07'], ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
        Log::shouldHaveReceived('critical')->once();
        $this->assertSame(
            [['controle' => 'cloture', 'message' => $refus, 'cloture_id' => DB::table('clotures_caisse')->where('numero', $numero)->value('id'), 'numero' => $numero]],
            EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '90')->get()->map(fn (EnregistrementFiscal $anomalie): array => $anomalie->contenuSigne()['donnees']['details'])->all(),
        );
    }

    /**
     * C27 : la clôture précédente d'une clôture est la dernière clôture
     * signée au registre, jamais la ligne de la table des tickets Z. Le
     * ticket Z de la journée de la fermeture temporaire, dont un
     * administrateur de la base a effacé les séquences (déclencheur
     * retiré), ne fait pas recompter à la clôture de la réouverture le
     * paiement du 05/10 qu'il avait déjà compté : seul celui du 09/10
     * entre dans le ticket Z du 09/10 et dans ce que signe sa clôture.
     */
    public function test_la_precedente_d_une_cloture_est_celle_du_registre(): void
    {
        $magasin = $this->magasin();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $vendre = fn (): Facture => self::vendreAuComptant(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $vendre();
        PeriodeActivite::query()->where('magasin_id', $magasin->id)->sole()->forceFill(['fin' => self::aParis('2026-10-06 18:00:00'), 'motif_fin' => MotifFermeture::Temporaire])->save();
        $this->travelTo(self::aParis('2026-10-07 00:00:30'));
        $fermeture = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, '2026-10-06')->last();
        $this->retirerLeDeclencheur('clotures_caisse', 'clotures_caisse_ajout_seul_update');
        DB::table('clotures_caisse')->where('magasin_id', $magasin->id)->where('id', $fermeture->id)->update(['derniere_sequence_vente' => null, 'derniere_sequence_encaissement' => null]);
        (new PeriodeActivite)->forceFill(['magasin_id' => $magasin->id, 'debut' => self::aParis('2026-10-09 09:00:00')])->save();
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $vendre();
        $this->travelTo(self::aParis('2026-10-10 00:00:30'));

        $reouverture = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, '2026-10-09')->sole();

        $this->assertSame(['especes', 1, 0, 1200], [$reouverture->encaissementsParMode()[0]['mode'], $reouverture->encaissementsParMode()[0]['paiements'], $reouverture->encaissementsParMode()[0]['contre_passations'], $reouverture->encaissementsParMode()[0]['montant']]);
        $this->assertSame(1200, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '50')->orderByDesc('sequence')->first()?->contenuSigne()['donnees']['details']['encaissements']['especes']);
    }

    /**
     * La première erreur arrête le magasin à la journée en échec : les
     * journées d'avant restent clôturées, et la suivante clôture reprend à
     * cette journée.
     */
    public function test_une_erreur_arrete_le_magasin_a_sa_journee_puis_la_cloture_reprend_a_cette_journee(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $this->vendreUnTicket();
        $this->travelTo(self::aParis('2026-10-08 19:00:00'));
        $this->app->instance(CloturerCaisseAction::class, new class(app(DocumentNumberingService::class), app(CalculateurLigne::class), app(RecapitulatifDeCloture::class), app(RegistreFiscal::class), app(PanierDeCaisse::class), app(JourneesDeCaisse::class), app(LecteurDesDroits::class), app(OperateurCourant::class)) extends CloturerCaisseAction
        {
            public function parLeSysteme(Magasin $magasin, string $journee): ClotureCaisse
            {
                if ($journee === '2026-10-07') {
                    throw new RuntimeException('Panne du serveur de base de données.');
                }

                return parent::parLeSysteme($magasin, $journee);
            }
        });

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, Calendrier::deParis()->aujourdhui()), RuntimeException::class, 'Panne du serveur de base de données.');
        $this->assertSame(['2026-10-05', '2026-10-06'], ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());

        $this->app->forgetInstance(CloturerCaisseAction::class);
        $reprise = app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, Calendrier::deParis()->aujourdhui());

        $this->assertSame(['2026-10-07', '2026-10-08'], $reprise->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
    }

    /**
     * Le bouton porte un jeton (R3) : chaque journée se clôture tout de même
     * dans sa propre transaction. Une erreur laisse clôturées celles qui la
     * précèdent ; la même demande, répétée, reprend à la journée en échec,
     * puis, répétée encore, rend la dernière clôture sans rien refaire.
     */
    public function test_une_erreur_a_une_journee_du_bouton_laisse_cloturees_les_precedentes_et_la_demande_repetee_reprend_a_cette_journee(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $this->vendreUnTicket();
        $this->travelTo(self::aParis('2026-10-08 19:00:00'));
        $jeton = (string) Str::uuid();
        $this->app->instance(CloturerCaisseAction::class, new class(app(DocumentNumberingService::class), app(CalculateurLigne::class), app(RecapitulatifDeCloture::class), app(RegistreFiscal::class), app(PanierDeCaisse::class), app(JourneesDeCaisse::class), app(LecteurDesDroits::class), app(OperateurCourant::class)) extends CloturerCaisseAction
        {
            public function handle(User $auteur, Magasin $magasin, string $journee): ClotureCaisse
            {
                if ($journee === '2026-10-07') {
                    throw new RuntimeException('Panne du serveur de base de données.');
                }

                return parent::handle($auteur, $magasin, $journee);
            }
        });
        $journees = fn (): array => ClotureCaisse::query()->orderBy('date_cloture')->get()->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all();

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), $jeton), RuntimeException::class, 'Panne du serveur de base de données.');
        $this->assertSame(['2026-10-05', '2026-10-06'], $journees());

        $this->app->forgetInstance(CloturerCaisseAction::class);
        $reprise = app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), $jeton);
        $repetee = app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), $jeton);

        $this->assertSame(['2026-10-07', '2026-10-08'], $reprise['clotures']->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
        $this->assertSame([$reprise['clotures']->last()->id], $repetee['clotures']->map(fn (ClotureCaisse $cloture): int => $cloture->id)->all());
        $this->assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'], $journees());
    }

    /**
     * Les journées en attente se comptent jusqu'à un jour du calendrier :
     * un instant écrit à la place de ce jour est refusé, jamais comparé
     * tel quel aux journées, et rien ne se clôture.
     */
    public function test_refuse_un_instant_a_la_place_du_jour(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->vendreUnTicket();
        $instant = (string) self::enUtc('2026-10-08 22:30:00');

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, $instant), LogicException::class, "« {$instant} » n'est pas une journée : une journée s'écrit AAAA-MM-JJ.");
        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    /**
     * Les ventes et les clôtures se lisent sous MagasinScope : les journées
     * d'un magasin ne se clôturent que depuis ce magasin, jamais avec les
     * ventes d'un autre.
     */
    public function test_refuse_de_cloturer_un_autre_magasin_que_le_magasin_actif(): void
    {
        $autreMagasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->vendreUnTicket();
        $magasin = $this->magasin();

        $this->assertThrows(fn () => app(CloturerLesJourneesAction::class)->parLeSysteme($autreMagasin, Calendrier::deParis()->aujourdhui()), LogicException::class, 'Les journées d\'un magasin ne se clôturent que depuis ce magasin.');
        $this->assertSame(0, ClotureCaisse::withoutGlobalScopes()->count());
        $this->assertTrue(app(CloturerLesJourneesAction::class)->parLeSysteme($magasin, Calendrier::deParis()->aujourdhui())->isEmpty());
    }

    /**
     * Le bouton d'un magasin qui n'a encore rien vendu ne clôture aucune
     * journée et ne ferme aucune caisse.
     */
    public function test_le_bouton_sans_journee_a_cloturer_ne_cloture_ni_ne_ferme_rien(): void
    {
        $this->travelTo(self::aParis('2026-10-09 19:00:00'));
        $magasin = $this->magasin();

        $resultat = app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), (string) Str::uuid());

        $this->assertSame([[], []], [$resultat['clotures']->all(), $resultat['caisses_fermees']->all()]);
        $this->assertSame(0, ClotureCaisse::query()->count());
    }

    /**
     * Après les clôtures du bouton, un échec de la fermeture d'office des
     * caisses personnelles ne défait aucune clôture : il est signalé au
     * journal de l'application, et la clôture automatique les fermera.
     */
    public function test_un_echec_de_la_fermeture_d_office_des_caisses_ne_defait_aucune_cloture(): void
    {
        $magasin = $this->magasin();
        $this->travelTo(self::aParis('2026-10-08 10:00:00'));
        $this->vendreUnTicket();
        $this->travelTo(self::aParis('2026-10-08 19:00:00'));
        Exceptions::fake();
        $this->mock(FermerLesCaissesPersonnellesAction::class, fn (MockInterface $fermeture) => $fermeture->shouldReceive('handle')->once()->andThrow(new RuntimeException('Panne du serveur de base de données.')));

        $resultat = app(CloturerLesJourneesAction::class)->parUnePersonne(self::auteur(), $magasin, Calendrier::deParis()->aujourdhui(), (string) Str::uuid());

        $this->assertSame(['2026-10-08'], $resultat['clotures']->map(fn (ClotureCaisse $cloture): string => $cloture->date_cloture)->all());
        $this->assertSame([], $resultat['caisses_fermees']->all());
        $this->assertSame(['2026-10-08'], ClotureCaisse::query()->pluck('date_cloture')->all());
        Exceptions::assertReported(fn (RuntimeException $panne): bool => $panne->getMessage() === 'Panne du serveur de base de données.');
    }
}
