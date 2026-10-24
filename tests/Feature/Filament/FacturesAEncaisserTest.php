<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Widgets\FacturesAEncaisserApercu;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Point 16 de la série 8 : l'alerte des factures à encaisser, en tête du
 * tableau de bord du magasin (voir FacturesAEncaisserApercu), selon la
 * portée de chacun ; une facture rouverte par la contre-passation d'un
 * paiement y revient, avec son badge. Les factures sont adressées à des
 * professionnels, à 20 %, sans délai propre au client : leur échéance
 * tombe 30 jours après leur émission.
 */
class FacturesAEncaisserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une facture réglée par virement, puis rouverte par la contre-passation
     * de ce virement : elle redevient due, avec le badge « Paiement
     * contre-passé », que n'a pas une facture jamais réglée.
     */
    public function test_une_facture_rouverte_par_une_contre_passation_apparait_avec_son_badge(): void
    {
        $magasin = $this->magasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->responsableDeTest($magasin);
        $jamaisReglee = $this->facture($vendeur, 'Atelier Martin', 50);
        $rouverte = $this->facture($vendeur, 'Garage Durand', 120);
        $virement = $this->payer($vendeur, $rouverte, '120.00');
        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(FacturesAEncaisserApercu::class)->assertSee('1 facture à encaisser')->assertDontSee($rouverte->numero);

        app(ContrePasserPaiementAction::class)->handle($proprietaire, $virement, 'Virement jamais reçu');

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);
        $alerte->assertSeeInOrder([
            '2 factures à encaisser', '170,00 € restant dû',
            $jamaisReglee->numero, 'Atelier Martin', '50,00 €',
            $rouverte->numero, 'Garage Durand', '120,00 €', 'Paiement contre-passé',
            'Voir toutes les factures à encaisser',
        ]);
        $this->assertSame(1, substr_count($alerte->html(), 'Paiement contre-passé'));
        $this->assertSame(StatutFacture::Emise, $rouverte->fresh()->statut);
    }

    /**
     * Ni un brouillon ni une facture payée ne sont à encaisser : ni l'alerte
     * ni le filtre « À encaisser » de la liste où elle mène ne les montrent.
     */
    public function test_une_facture_payee_ou_un_brouillon_n_apparait_pas(): void
    {
        $magasin = $this->magasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->responsableDeTest($magasin);
        $due = $this->facture($vendeur, 'Atelier Martin', 50);
        $payee = $this->facture($vendeur, 'Garage Durand', 120);
        $this->payer($vendeur, $payee, '120.00');
        $brouillon = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->professionnel()->create(['nom' => 'Brasserie Petit']), [
            ['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1],
        ]);
        $this->dansLeMagasin($magasin, $proprietaire);

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);

        $alerte->assertSeeInOrder(['1 facture à encaisser', '50,00 € restant dû', $due->numero])
            ->assertDontSee(['Garage Durand', $payee->numero, 'Brasserie Petit'])
            ->assertSee(htmlspecialchars(FactureResource::urlDesFacturesAEncaisser()), escape: false);
        Livewire::test(ListFactures::class)
            ->filterTable(FactureResource::FILTRE_A_ENCAISSER)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$payee, $brouillon]);
    }

    public function test_le_proprietaire_et_le_responsable_voient_toutes_les_factures_du_magasin(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $deLEquipe = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);

        foreach ([$this->proprietaireDe($magasin), $this->responsable($magasin)] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            $this->assertTrue(FacturesAEncaisserApercu::canView());
            Livewire::test(FacturesAEncaisserApercu::class)->assertSeeInOrder(['2 factures à encaisser', '170,00 € restant dû', $deLEquipe->numero, $duResponsable->numero]);
        }
    }

    public function test_un_administrateur_ne_voit_l_alerte_que_s_il_voit_toutes_les_factures(): void
    {
        $magasin = $this->magasin();
        $facture = $this->facture($this->responsableDeTest($magasin), 'Atelier Martin', 50);
        $voitTout = $this->administrateur($magasin);
        $neVoitQueLesSiennes = $this->administrateur($magasin, autorisationsMagasin: array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(), [PermissionRegistry::FACTURE_VIEW_TOUTES])));

        $this->dansLeMagasin($magasin, $voitTout);
        $this->assertTrue(FacturesAEncaisserApercu::canView());
        Livewire::test(FacturesAEncaisserApercu::class)->assertSeeInOrder(['1 facture à encaisser', $facture->numero]);

        $this->dansLeMagasin($magasin, $neVoitQueLesSiennes);
        $this->assertFalse(FacturesAEncaisserApercu::canView());
    }

    /**
     * Un administrateur qui voit celles de toute l'équipe, mais dont la
     * case « Factures : voir (les siennes) » est décochée, ne peut pas
     * ouvrir la liste des factures : l'alerte, qui y mène, ne se montre
     * pas.
     */
    public function test_un_administrateur_qui_ne_peut_pas_ouvrir_la_liste_des_factures_n_a_pas_l_alerte(): void
    {
        $magasin = $this->magasin();
        $this->facture($this->responsableDeTest($magasin), 'Atelier Martin', 50);
        $administrateur = $this->administrateur($magasin, autorisationsMagasin: array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(), [PermissionRegistry::FACTURE_VIEW])));
        $this->dansLeMagasin($magasin, $administrateur);

        $this->assertFalse(FacturesAEncaisserApercu::canView());
        $this->get(FactureResource::urlDesFacturesAEncaisser())->assertForbidden();
    }

    /**
     * Un vendeur qui n'est pas autorisé à contre-passer ne voit pas
     * l'alerte, même s'il voit les factures de toute l'équipe ; un
     * observateur suit la même règle.
     */
    public function test_un_vendeur_sans_l_autorisation_de_contre_passer_ne_voit_pas_l_alerte(): void
    {
        $magasin = $this->magasin();
        $this->facture($this->responsableDeTest($magasin), 'Atelier Martin', 50);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        foreach ([$vendeur, $observateur] as $compte) {
            $this->dansLeMagasin($magasin, $compte);

            $this->assertFalse(FacturesAEncaisserApercu::canView());
        }
    }

    public function test_un_vendeur_autorise_a_contre_passer_ne_voit_que_ses_factures(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PAIEMENT_CONTRE_PASSER]);
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);
        $this->dansLeMagasin($magasin, $vendeur);

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);

        $this->assertTrue(FacturesAEncaisserApercu::canView());
        $alerte->assertSeeInOrder(['1 facture à encaisser', '50,00 € restant dû', $sienne->numero, 'Client du vendeur'])
            ->assertDontSee([$duResponsable->numero, 'Client du responsable', '170,00 €']);
    }

    /**
     * Un vendeur autorisé à contre-passer qui voit celles de toute l'équipe
     * a toutes les factures du magasin dans l'alerte ; avec les mêmes
     * cases, sauf « Factures : voir (les siennes) », un autre vendeur ne
     * peut pas ouvrir la liste des factures et n'a pas l'alerte.
     */
    public function test_un_vendeur_qui_contre_passe_et_voit_celles_de_toute_l_equipe_les_voit_toutes_s_il_peut_ouvrir_leur_liste(): void
    {
        $magasin = $this->magasin();
        $cases = [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PAIEMENT_CONTRE_PASSER, PermissionRegistry::FACTURE_VIEW_TOUTES];
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, $cases);
        $sansLaListe = $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff($cases, [PermissionRegistry::FACTURE_VIEW])));
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);

        $this->dansLeMagasin($magasin, $vendeur);
        $this->assertTrue(FacturesAEncaisserApercu::canView());
        Livewire::test(FacturesAEncaisserApercu::class)->assertSeeInOrder(['2 factures à encaisser', '170,00 € restant dû', $sienne->numero, $duResponsable->numero]);

        $this->dansLeMagasin($magasin, $sansLaListe);
        $this->assertFalse(FacturesAEncaisserApercu::canView());
        $this->get(FactureResource::urlDesFacturesAEncaisser())->assertForbidden();
    }

    /**
     * L'expert-comptable, qui a toutes les consultations, voit l'alerte de
     * chaque magasin réel, toutes les factures comprises, sans pouvoir
     * contre-passer ni encaisser.
     */
    public function test_l_expert_comptable_voit_l_alerte_en_lecture(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $deLEquipe = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);
        $expertComptable = $this->expertComptable($magasin);
        $this->dansLeMagasin($magasin, $expertComptable);

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);

        $this->assertTrue(FacturesAEncaisserApercu::canView());
        $alerte->assertSeeInOrder(['2 factures à encaisser', '170,00 € restant dû', $deLEquipe->numero, $duResponsable->numero]);
        $this->assertSame([false, false], [$expertComptable->can(PermissionRegistry::PAIEMENT_CONTRE_PASSER), $expertComptable->can(PermissionRegistry::PAIEMENT_CREER)]);
    }

    /**
     * Émise le 1er octobre, la facture de 50,00 € tombe le 31 ; émise le
     * 20 octobre, celle de 120,00 € tombe le 19 novembre. Le 2 novembre, la
     * première est en retard, et se compte à part, en rouge ; la seconde
     * ne l'est pas.
     */
    public function test_le_retard_se_compte_a_part(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Paris'));
        $magasin = $this->magasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->responsableDeTest($magasin);
        $enRetard = $this->facture($vendeur, 'Atelier Martin', 50);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Paris'));
        $aLHeure = $this->facture($vendeur, 'Garage Durand', 120);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 10:00:00', 'Europe/Paris'));
        $this->dansLeMagasin($magasin, $proprietaire);

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);

        $alerte->assertSeeInOrder([
            '2 factures à encaisser', '170,00 € restant dû',
            'dont 1 en retard', '50,00 €',
            $enRetard->numero, '31/10/2026',
            $aLHeure->numero, '19/11/2026',
        ]);
        $this->assertSame(['nombre' => 2, 'restant_du' => 17000, 'nombre_en_retard' => 1, 'restant_du_en_retard' => 5000], $alerte->viewData('totaux'));
    }

    /**
     * 10,10 € et 20,20 € dus, puis 0,30 € réglés 0,10 € : 30,50 € restent
     * dus, sommés en centimes, sans qu'aucune requête ne fasse de somme.
     */
    public function test_le_restant_du_se_calcule_en_centimes_sans_somme_sql(): void
    {
        $magasin = $this->magasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->responsableDeTest($magasin);
        $this->facture($vendeur, 'Atelier Martin', 10.10);
        $this->facture($vendeur, 'Garage Durand', 20.20);
        $partielle = $this->facture($vendeur, 'Brasserie Petit', 0.30);
        $this->payer($vendeur, $partielle, '0.10');
        $this->dansLeMagasin($magasin, $proprietaire);
        $requetes = [];
        DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
            $requetes[] = $requete->sql;
        });

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);

        $alerte->assertSeeInOrder(['3 factures à encaisser', '30,50 € restant dû']);
        $this->assertSame(3050, $alerte->viewData('totaux')['restant_du']);
        $this->assertNotEmpty($requetes);
        foreach ($requetes as $sql) {
            $this->assertStringNotContainsStringIgnoringCase('sum(', $sql);
        }
    }

    /**
     * Dans un magasin suspendu, plus rien ne se contre-passe, mais la
     * facture reste due : le vendeur autorisé à contre-passer garde
     * l'alerte, lue sur sa case cochée.
     */
    public function test_l_alerte_reste_visible_dans_un_magasin_en_lecture_seule(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(), PermissionRegistry::PAIEMENT_CONTRE_PASSER]);
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->dansLeMagasin($magasin->fresh(), $vendeur);

        $alerte = Livewire::test(FacturesAEncaisserApercu::class);

        $this->assertFalse($vendeur->can(PermissionRegistry::PAIEMENT_CONTRE_PASSER));
        $this->assertTrue(FacturesAEncaisserApercu::canView());
        $alerte->assertSeeInOrder(['1 facture à encaisser', $sienne->numero]);
    }

    /**
     * Un magasin dont la société peut facturer un professionnel.
     */
    private function magasin(): Magasin
    {
        return $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Boutique Lyon 1']));
    }

    /**
     * Une facture d'un article au prix TTC donné, à 20 %, émise par cet
     * auteur pour un nouveau client professionnel de ce nom.
     */
    private function facture(User $auteur, string $client, float $prixTtc): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, Client::factory()->professionnel()->create(['nom' => $client]), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));
    }

    /**
     * Un virement reçu aujourd'hui, imputé en entier sur cette facture.
     */
    private function payer(User $auteur, Facture $facture, string $montant): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle($auteur, $facture->client, [
            'mode_paiement' => ModePaiement::Virement->value,
            'montant' => $montant,
            'reference' => null,
            'recu_le' => today()->toDateString(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
