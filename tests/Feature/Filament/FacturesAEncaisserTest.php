<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
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
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Widgets\AFaireApercu;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Point 16 de la série 8, puis « À faire » de la série 9 : les factures à
 * encaisser au tableau de bord du magasin, première chose de l'« À faire »
 * (voir AFaireApercu et ChosesAFaireDuMagasin), selon la portée de
 * chacun ; une facture rouverte par la contre-passation d'un paiement y
 * revient, avec son badge. Tout compte qui encaisse y voit les siennes,
 * même sans contre-passer, et toutes avec « voir celles de toute
 * l'équipe » (règle 4 et précision de l'éditeur du 02/11/2026) ; le
 * propriétaire, qui ne vend jamais, les a à surveiller ; un cabinet lié
 * n'en voit rien (règle 3). Les factures sont adressées à des
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
        Livewire::test(AFaireApercu::class)->assertSee('1 facture à encaisser')->assertDontSee($rouverte->numero);

        app(ContrePasserPaiementAction::class)->handle($proprietaire, $virement, 'Virement jamais reçu');

        $alerte = Livewire::test(AFaireApercu::class);
        $alerte->assertSeeInOrder([
            'À surveiller',
            '2 factures à encaisser', '170,00 € restant dû, dont 1 dont un paiement a été contre-passé.',
            $jamaisReglee->numero, 'Atelier Martin', '50,00 €',
            $rouverte->numero, 'Garage Durand', '120,00 €', 'Paiement contre-passé',
            'Voir toutes les factures à encaisser',
        ]);
        $this->assertSame(1, substr_count($alerte->html(), 'Paiement contre-passé'));
        $this->assertSame(UrgenceAFaire::Rouge, $this->aEncaisser($proprietaire, $magasin)?->urgence);
        $this->assertSame(StatutFacture::Emise, $rouverte->fresh()->statut);
    }

    /**
     * Ni un brouillon ni une facture payée ne sont à encaisser : ni l'« À
     * faire » ni le filtre « À encaisser » de la liste où il mène ne les
     * montrent ; une seule facture due mène à sa fiche.
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

        $alerte = Livewire::test(AFaireApercu::class);

        $alerte->assertSeeInOrder(['1 facture à encaisser', '50,00 € restant dû', $due->numero])
            ->assertDontSee(['Garage Durand', $payee->numero, 'Brasserie Petit'])
            ->assertSee(htmlspecialchars(FactureResource::urlDesFacturesAEncaisser()), escape: false);
        $this->assertSame(FactureResource::getUrl('view', ['record' => $due]), $this->aEncaisser($proprietaire, $magasin)?->url);
        Livewire::test(ListFactures::class)
            ->filterTable(FactureResource::FILTRE_A_ENCAISSER)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$payee, $brouillon]);
    }

    /**
     * Le propriétaire, qui ne vend jamais dans un magasin réel, les a à
     * surveiller, avec « Voir » ; le responsable, qui encaisse, les a à
     * faire, avec « Encaisser » : tous deux voient toutes celles du
     * magasin, menant à la liste filtrée.
     */
    public function test_le_proprietaire_et_le_responsable_voient_toutes_les_factures_du_magasin(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $deLEquipe = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);

        foreach ([[$this->proprietaireDe($magasin), true, 'Voir'], [$this->responsable($magasin), false, 'Encaisser']] as [$compte, $aSurveiller, $bouton]) {
            $this->dansLeMagasin($magasin, $compte);
            $chose = $this->aEncaisser($compte, $magasin);

            $this->assertSame([2, $aSurveiller, $bouton, FactureResource::urlDesFacturesAEncaisser()], [$chose?->nombre, $chose?->aSurveiller, $chose?->bouton, $chose?->url]);
            Livewire::test(AFaireApercu::class)->assertSeeInOrder(['2 factures à encaisser', '170,00 € restant dû', $deLEquipe->numero, $duResponsable->numero]);
        }
    }

    /**
     * Un administrateur sans la vente les a à surveiller s'il voit toutes
     * les factures ; sans « voir celles de toute l'équipe », il ne voit que
     * les siennes, et il n'en a aucune.
     */
    public function test_un_administrateur_ne_voit_l_alerte_que_s_il_voit_toutes_les_factures(): void
    {
        $magasin = $this->magasin();
        $facture = $this->facture($this->responsableDeTest($magasin), 'Atelier Martin', 50);
        $voitTout = $this->administrateur($magasin);
        $neVoitQueLesSiennes = $this->administrateur($magasin, autorisationsMagasin: array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [PermissionRegistry::FACTURE_VIEW_TOUTES])));

        $this->dansLeMagasin($magasin, $voitTout);
        $this->assertTrue($this->aEncaisser($voitTout, $magasin)?->aSurveiller);
        Livewire::test(AFaireApercu::class)->assertSeeInOrder(['À surveiller', '1 facture à encaisser', $facture->numero]);

        $this->dansLeMagasin($magasin, $neVoitQueLesSiennes);
        $this->assertNull($this->aEncaisser($neVoitQueLesSiennes, $magasin));
    }

    /**
     * Un administrateur qui voit celles de toute l'équipe, mais dont la
     * case « Factures : voir (les siennes) » est décochée, ne peut pas
     * ouvrir la liste des factures : l'« À faire », qui y mène, ne les
     * montre pas.
     */
    public function test_un_administrateur_qui_ne_peut_pas_ouvrir_la_liste_des_factures_n_a_pas_l_alerte(): void
    {
        $magasin = $this->magasin();
        $this->facture($this->responsableDeTest($magasin), 'Atelier Martin', 50);
        $administrateur = $this->administrateur($magasin, autorisationsMagasin: array_values(array_diff(PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), [PermissionRegistry::FACTURE_VIEW])));
        $this->dansLeMagasin($magasin, $administrateur);

        $this->assertNull($this->aEncaisser($administrateur, $magasin));
        $this->get(FactureResource::urlDesFacturesAEncaisser())->assertForbidden();
    }

    /**
     * Règle 4 de l'« À faire » (« qui encaisse voit », précision de
     * l'éditeur du 02/11/2026), qui remplace la règle de la série 8 : un
     * vendeur qui encaisse, sans l'autorisation de contre-passer, voit ses
     * factures à encaisser, à faire, avec « Encaisser », et rien de celles
     * des autres ; un observateur, qui n'encaisse pas, n'en voit aucune.
     */
    public function test_un_vendeur_qui_encaisse_voit_ses_factures_a_encaisser_meme_sans_contre_passer(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Atelier Martin', 120);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        $this->dansLeMagasin($magasin, $vendeur);
        $this->assertFalse($vendeur->can(PermissionRegistry::PAIEMENT_CONTRE_PASSER));
        $chose = $this->aEncaisser($vendeur, $magasin);
        $this->assertSame([1, false, 'Encaisser', FactureResource::getUrl('view', ['record' => $sienne])], [$chose?->nombre, $chose?->aSurveiller, $chose?->bouton, $chose?->url]);
        Livewire::test(AFaireApercu::class)->assertSeeInOrder(['1 facture à encaisser', '50,00 € restant dû', $sienne->numero, 'Client du vendeur'])
            ->assertDontSee([$duResponsable->numero, 'Atelier Martin', '170,00 €']);

        $this->dansLeMagasin($magasin, $observateur);
        $this->assertNull($this->aEncaisser($observateur, $magasin));
    }

    /**
     * Un vendeur autorisé à contre-passer ne voit, lui aussi, que les
     * siennes.
     */
    public function test_un_vendeur_autorise_a_contre_passer_ne_voit_que_ses_factures(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::PAIEMENT_CONTRE_PASSER]);
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);
        $this->dansLeMagasin($magasin, $vendeur);

        Livewire::test(AFaireApercu::class)->assertSeeInOrder(['1 facture à encaisser', '50,00 € restant dû', $sienne->numero, 'Client du vendeur'])
            ->assertDontSee([$duResponsable->numero, 'Client du responsable', '170,00 €']);
    }

    /**
     * Un vendeur qui voit celles de toute l'équipe a toutes les factures du
     * magasin dans l'« À faire » ; avec les mêmes cases, sauf « Factures :
     * voir (les siennes) », un autre vendeur ne peut pas ouvrir la liste des
     * factures et n'en voit aucune.
     */
    public function test_un_vendeur_qui_voit_celles_de_toute_l_equipe_les_voit_toutes_s_il_peut_ouvrir_leur_liste(): void
    {
        $magasin = $this->magasin();
        $cases = [...RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce), PermissionRegistry::FACTURE_VIEW_TOUTES];
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, $cases);
        $sansLaListe = $this->membre($magasin, RoleMagasin::Vendeur, array_values(array_diff($cases, [PermissionRegistry::FACTURE_VIEW])));
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);

        $this->dansLeMagasin($magasin, $vendeur);
        Livewire::test(AFaireApercu::class)->assertSeeInOrder(['2 factures à encaisser', '170,00 € restant dû', $sienne->numero, $duResponsable->numero]);

        $this->dansLeMagasin($magasin, $sansLaListe);
        $this->assertNull($this->aEncaisser($sansLaListe, $magasin));
        $this->get(FactureResource::urlDesFacturesAEncaisser())->assertForbidden();
    }

    /**
     * Règle 3 de l'« À faire », qui remplace l'alerte du cabinet de la
     * série 8 : un compte du cabinet lié, qui voit toutes les factures, ne
     * voit rien de l'« À faire », ni les factures à encaisser ni le reste ;
     * il garde la liste des factures, filtre « À encaisser » compris.
     */
    public function test_un_compte_de_cabinet_ne_voit_rien_de_l_a_faire(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $deLEquipe = $this->facture($vendeur, 'Client du vendeur', 50);
        $duResponsable = $this->facture($this->responsableDeTest($magasin), 'Client du responsable', 120);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);
        $this->dansLeMagasin($magasin, $collaborateur);

        $this->assertTrue($collaborateur->can(PermissionRegistry::FACTURE_VIEW_TOUTES));
        $this->assertFalse(AFaireApercu::canView());
        $this->assertSame(['a_faire' => [], 'a_surveiller' => []], ChosesAFaireDuMagasin::pour($collaborateur, $magasin));
        $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('factures à encaisser')->assertDontSee($deLEquipe->numero);
        Livewire::test(ListFactures::class)->filterTable(FactureResource::FILTRE_A_ENCAISSER)->assertCanSeeTableRecords([$deLEquipe, $duResponsable]);
    }

    /**
     * Émise le 1er octobre, la facture de 50,00 € tombe le 31 ; émise le
     * 20 octobre, celle de 120,00 € tombe le 19 novembre. Le 2 novembre, la
     * première est en retard, et se compte à part, en rouge ; la seconde
     * ne l'est pas.
     */
    public function test_le_retard_se_compte_a_part(): void
    {
        $this->travelTo(self::aParis('2026-10-01 10:00:00'));
        $magasin = $this->magasin();
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->responsableDeTest($magasin);
        $enRetard = $this->facture($vendeur, 'Atelier Martin', 50);
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $aLHeure = $this->facture($vendeur, 'Garage Durand', 120);
        $this->travelTo(self::aParis('2026-11-02 10:00:00'));
        $this->dansLeMagasin($magasin, $proprietaire);

        $alerte = Livewire::test(AFaireApercu::class);

        $alerte->assertSeeInOrder([
            '2 factures à encaisser', '170,00 € restant dû, dont 1 en retard (50,00 €).',
            $enRetard->numero, '31/10/2026',
            $aLHeure->numero, '19/11/2026',
        ]);
        $chose = $this->aEncaisser($proprietaire, $magasin);
        $this->assertSame([UrgenceAFaire::Rouge, '2026-10-31'], [$chose?->urgence, $chose?->date]);
    }

    /**
     * 10,10 € et 20,20 € dus, puis 0,30 € réglés 0,10 € : 30,50 € restent
     * dus, sommés en centimes, sans qu'aucune requête ne fasse de somme
     * d'un montant (seules des quantités entières se somment en base, pour
     * les lignes encore à créditer des ventes hors de France).
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

        $alerte = Livewire::test(AFaireApercu::class);

        $alerte->assertSeeInOrder(['3 factures à encaisser', '30,50 € restant dû']);
        $this->assertNotEmpty($requetes);
        foreach ($requetes as $sql) {
            $this->assertDoesNotMatchRegularExpression('/sum\((?![^)]*quantite)/i', $sql);
        }
    }

    /**
     * Dans un magasin suspendu, plus rien ne s'encaisse, mais la facture
     * reste due : le vendeur la garde dans son « À faire », lue sur sa case
     * cochée, avec « Voir » pour bouton et le motif de la lecture seule.
     */
    public function test_l_alerte_reste_visible_dans_un_magasin_en_lecture_seule(): void
    {
        $magasin = $this->magasin();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $sienne = $this->facture($vendeur, 'Client du vendeur', 50);
        $magasin->forceFill(['suspendu_le' => now()])->save();
        $this->dansLeMagasin($magasin->fresh(), $vendeur);

        $chose = $this->aEncaisser($vendeur, $magasin->fresh());

        $this->assertFalse($vendeur->can(PermissionRegistry::PAIEMENT_CREER));
        $this->assertSame([false, 'Voir'], [$chose?->aSurveiller, $chose?->bouton]);
        $this->assertStringContainsString((string) $magasin->fresh()->motifDeLectureSeule(), (string) $chose?->precision);
        Livewire::test(AFaireApercu::class)->assertSeeInOrder(['1 facture à encaisser', $sienne->numero]);
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
            'recu_le' => Calendrier::deParis()->aujourdhui(),
        ], [['facture_id' => $facture->id, 'montant' => $montant]]);
    }
}
