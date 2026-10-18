<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureOperation;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TransportPar;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\FactureResource;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\EditFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Filament\App\Resources\FactureResource\Pages\ViewFacture;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FactureResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le résumé d'un avoir (numéro/motif/total) ne dit pas CE QUI a été
     * retourné : le détail des lignes créditées (produit, quantité,
     * montant) doit être visible directement sur la page de la facture
     * créditée, pas seulement sur la page dédiée de l'avoir.
     */
    public function test_la_facture_affiche_le_detail_des_produits_credites_par_un_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['nom' => 'Écran iPhone 17 Pro', 'prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        $facture = $facture->fresh();

        $ligneFacture = $facture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ], 'Produit défectueux');
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Avoirs émis')
            ->assertSee($avoir->fresh()->numero)
            ->assertSee('Produit défectueux')
            ->assertSee('Écran iPhone 17 Pro');
    }

    /**
     * Le Repeater de lignes à créditer de l'action "Avoir" est
     * volontairement "deletable(true)" (voir son docblock) pour permettre
     * de retirer une ligne à ne pas rembourser : un vendeur peut donc, par
     * erreur, les retirer toutes avant de valider. CreerAvoirAction refuse
     * alors de créer un avoir sans aucune ligne -- avant la correction,
     * cette LogicException remontait telle quelle jusqu'à une page
     * d'erreur brute au lieu d'une notification.
     */
    public function test_laction_avoir_sans_aucune_ligne_affiche_une_notification(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        Livewire::test(ListFactures::class)
            ->callTableAction('avoir', $facture->fresh(), data: ['lignes' => [], 'motif' => 'Oups'])
            ->assertNotified('Un avoir doit porter sur au moins une ligne.');

        $this->assertSame(0, Avoir::query()->count());
    }

    /**
     * Le crédit appliqué automatiquement sur une facture (voir
     * AppliquerAvoirsDisponiblesAction) doit être visible et déclaré
     * directement sur cette facture, pas seulement déductible du calcul.
     */
    public function test_la_facture_affiche_le_credit_applique_automatiquement_par_un_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = self::vendreAuComptant(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);

        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);

        // Nouvel achat du même montant : couvert automatiquement par le crédit.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $nouvelleFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $nouvelleFacture);
        $nouvelleFacture = $nouvelleFacture->fresh();

        $this->assertSame(StatutFacture::Payee, $nouvelleFacture->statut);

        Livewire::test(ViewFacture::class, ['record' => $nouvelleFacture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Crédité par avoir')
            ->assertSee($avoir->fresh()->numero)
            // Filament insère une espace insécable (U+00A0) entre le montant
            // et le symbole monétaire : on vérifie juste le montant.
            ->assertSee('60,00');

        // Le numéro d'avoir doit permettre d'ouvrir sa fiche (date, motif,
        // produits retournés), pas rester un simple texte.
        $html = Livewire::test(ViewFacture::class, ['record' => $nouvelleFacture->getRouteKey()])->html();
        $this->assertStringContainsString(AvoirResource::getUrl('view', ['record' => $avoir->fresh()]), $html);
    }

    /**
     * Les conditions de paiement s'impriment dans le pied de chaque page du
     * PDF : 500 caractères au plus, comme les mentions du magasin, pour que
     * le pied tienne toujours au bas de la page (point 4).
     */
    public function test_des_conditions_de_paiement_de_plus_de_500_caracteres_sont_refusees(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'conditions_paiement' => str_repeat('a', 501),
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['conditions_paiement' => 'max']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Une vente choisit toujours un produit existant (ou un service, par son
     * bouton), jamais une ligne libre.
     */
    public function test_une_ligne_sans_produit_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [['quantite' => 1, 'prix_unitaire_ttc' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['lignes.0.produit_id' => 'required']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le nom vient du catalogue et le prix d'une vente se saisit en TTC :
     * ni la description ni le PU HT d'une ligne ne se modifient ici.
     */
    public function test_la_description_et_le_pu_ht_dune_ligne_ne_se_modifient_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $component = Livewire::test(CreateFacture::class);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertFormFieldIsDisabled("lignes.{$cle}.description")
            ->assertFormFieldIsDisabled("lignes.{$cle}.prix_unitaire_ht")
            ->assertFormFieldIsEnabled("lignes.{$cle}.prix_unitaire_ttc")
            ->assertFormComponentActionDoesNotExist("lignes.{$cle}.produit_id", 'createOption');
    }

    /**
     * Un avoir n'est jamais remboursé par ce logiciel : son crédit réduit le
     * solde dû exactement comme un encaissement, et c'est lui qui explique
     * le statut "Partiellement payée" d'une facture jamais réellement
     * encaissée. La colonne "Payé" de la liste doit donc refléter ce
     * crédit -- sans quoi elle affiche "0,00 €" à côté d'un statut
     * "Partiellement payée", un résultat qui se lit comme une
     * contradiction/un bug plutôt que comme un règlement par avoir.
     */
    public function test_la_colonne_paye_de_la_liste_inclut_le_credit_applique_par_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produitRembourse = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);
        $produitFacture = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 10 € de crédit
        // disponible.
        $premiereFacture = self::vendreAuComptant(self::auteur(), $client, [
            ['produit_id' => $produitRembourse->id, 'quantite' => 1],
        ], ModePaiement::Especes);

        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);

        // Nouvel achat d'un montant supérieur : seulement couvert en partie
        // par le crédit, aucun encaissement réel.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produitFacture->id, 'description' => $produitFacture->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $nouvelleFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $nouvelleFacture);
        $nouvelleFacture = $nouvelleFacture->fresh();

        $this->assertSame(StatutFacture::PartiellementPayee, $nouvelleFacture->statut);
        $this->assertSame(0.0, $nouvelleFacture->montantPaye());
        $this->assertSame(10.0, $nouvelleFacture->montantCrediteParAvoir());

        // On passe la clé, pas le modèle déjà chargé : assertTableColumnStateSet
        // ne re-résout la ligne via la requête de la table (et donc ses
        // imputations chargées avec elle) que si on lui laisse le faire
        // elle-même.
        Livewire::test(ListFactures::class)
            ->assertTableColumnStateSet('montant_paye', '10.00', $nouvelleFacture->getKey());
    }

    /**
     * Même exigence que pour la colonne "Payé" de la liste
     * (test_la_colonne_paye_de_la_liste_inclut_le_credit_applique_par_avoir),
     * mais pour le champ "Payé" de la fiche détail : il doit lui aussi
     * inclure le crédit appliqué par avoir, sans quoi il affiche "0,00 €"
     * à côté d'un statut "Partiellement payée" -- une contradiction.
     */
    public function test_la_fiche_facture_affiche_le_paye_incluant_le_credit_applique_par_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        // Montants choisis pour qu'aucun total légitime de la page ne se
        // termine par "0,00" (voir plus bas pourquoi) : 10,83 € HT + 20 %
        // de TVA = 13,00 € TTC de crédit disponible.
        $produitRembourse = Produit::factory()->create(['prix_vente_ttc' => 13, 'taux_tva' => 20, 'suivi_stock' => false]);
        $produitFacture = Produit::factory()->create(['prix_vente_ttc' => 61.20, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat payé puis intégralement retourné : 13 € de crédit
        // disponible.
        $premiereFacture = self::vendreAuComptant(self::auteur(), $client, [
            ['produit_id' => $produitRembourse->id, 'quantite' => 1],
        ], ModePaiement::Especes);

        $ligneFacture = $premiereFacture->lignes->firstOrFail();
        $avoir = app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ligneFacture->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle(self::auteur(), $avoir);

        // Nouvel achat d'un montant supérieur (61,20 € TTC) : seulement
        // couvert en partie par le crédit, aucun encaissement réel.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produitFacture->id, 'description' => $produitFacture->nom, 'quantite' => 1, 'prix_unitaire_ht' => 51, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $nouvelleFacture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $nouvelleFacture);
        $nouvelleFacture = $nouvelleFacture->fresh();

        $this->assertSame(StatutFacture::PartiellementPayee, $nouvelleFacture->statut);
        $this->assertSame(0.0, $nouvelleFacture->montantPaye());
        $this->assertSame(13.0, $nouvelleFacture->montantCrediteParAvoir());
        $this->assertSame(48.2, $nouvelleFacture->montantRestant());

        // "0,00 €" ne doit apparaître nulle part sur la fiche : avant la
        // correction, c'est précisément la valeur affichée par le champ
        // "Payé" (montantPaye() seul, sans le crédit), alors que le statut
        // "Partiellement payée" prouve qu'une partie du montant est bien
        // réglée. Aucun des autres montants de cette fiche (13,00 / 10,83 /
        // 2,17 / 51,00 / 10,20 / 61,20 / 48,20) ne se termine par "0,00",
        // ce qui permet de n'assertDontSee que la vraie valeur à exclure.
        Livewire::test(ViewFacture::class, ['record' => $nouvelleFacture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('13,00')
            ->assertDontSee('0,00');
    }

    /**
     * La colonne « Payé » de la liste additionne en centimes les paiements
     * et les crédits d'avoir imputés sur chaque facture, jamais par une
     * somme que la base de données ferait sur une colonne décimale : 0,10 €
     * de crédit d'avoir, puis 0,10 € et 0,20 € encaissés font exactement
     * 0,40 € (une somme de nombres à virgule donnerait
     * 0,30000000000000004 pour les deux paiements).
     */
    public function test_le_paye_de_la_liste_se_somme_en_centimes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $bonbon = Produit::factory()->create(['prix_vente_ttc' => '0.10', 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => '10.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $bonbon->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [['ligne_facture_id' => $ticket->lignes->sole()->id, 'quantite' => 1]]));
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $cable->id, 'quantite' => 1]]));

        foreach (['0.10', '0.20'] as $montant) {
            app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $montant, 'recu_le' => today()->toDateString()], [['facture_id' => $facture->id, 'montant' => $montant]]);
        }

        $this->assertSame(['0.10', '0.10', '0.20'], [$facture->allocationsAvoir()->sole()->montant_alloue, ...$facture->allocationsPaiement()->orderBy('id')->pluck('montant_alloue')->all()]);
        Livewire::test(ListFactures::class)
            ->assertTableColumnStateSet('montant_paye', '0.40', $facture->getKey())
            ->assertTableColumnStateSet('montant_paye', '0.10', $ticket->getKey());
    }

    /**
     * La fiche d'une facture montre chaque paiement reçu en entier : sa
     * date, son mode et sa référence, pas seulement son montant.
     */
    public function test_la_fiche_montre_la_date_le_mode_et_la_reference_de_chaque_paiement(): void
    {
        $this->travelTo('2026-10-14 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $cable = Produit::factory()->create(['prix_vente_ttc' => '10.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), Client::query()->where('est_comptoir', true)->sole(), [['produit_id' => $cable->id, 'quantite' => 1]], ModePaiement::CarteBancaire, 'REF-XYZ-123');

        $fiche = Livewire::test(ViewFacture::class, ['record' => $ticket->getRouteKey()]);

        $fiche->assertSeeInOrder(['Paiements reçus', '14/10/2026', 'Carte bancaire', 'REF-XYZ-123']);
    }

    /**
     * La main-d'œuvre se facture comme à la caisse : par le bouton
     * « Ajouter un service ».
     */
    public function test_creer_une_facture_avec_une_ligne_de_service(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $installation = Service::factory()->create(['nom' => 'Installation']);

        $component = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id, 'lignes' => []])
            ->callFormComponentAction('ajouterUnServiceAction', 'ajouterUnService', data: ['services' => [$installation->id]]);
        $cle = array_key_first($component->get('data.lignes'));
        // 72 € TTC à 20 % de TVA = 60 € HT, par 2.
        $component
            ->set("data.lignes.{$cle}.quantite", '2')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '72')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();
        $this->assertSame($installation->id, $ligne->service_id);
        $this->assertSame('Installation', $ligne->description);
        $this->assertSame('120.00', $facture->total_ht);
    }

    /**
     * Ferme la boucle du cas signalé par un magasin en passant par le vrai
     * champ "PU TTC" tapé en direct (comme un vendeur), pas par une valeur
     * HT précalculée dans le test : 40 € TTC à 20 % de TVA et quantité 2
     * doit totaliser exactement 80,00 € TTC, jamais 79,99 €.
     */
    public function test_saisir_le_prix_ttc_dune_ligne_totalise_exactement_a_quantite_2(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 35, 'taux_tva' => 20]);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id]);
        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '2')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '40');

        $component->call('create')->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();
        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('80.00', $facture->total_ttc);
    }

    /**
     * Bug rapporté par un magasin : en tapant "5.5" dans le taux de TVA (ou
     * "12.5" dans un prix), le champ redevenait "5" -- une réponse du
     * serveur arrivée en pleine frappe réécrivait la case. Les champs
     * calculés en direct ne doivent jamais être réécrits tant qu'ils ont le
     * focus (modificateur "unintrusive"), et le script du panneau renvoie
     * ce qu'ils affichent si une réponse l'a fait diverger. Vérifié aussi
     * dans un vrai navigateur, serveur ralenti : voir le message de commit.
     */
    public function test_les_champs_calcules_en_direct_ne_sont_jamais_reecrits_pendant_la_frappe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $html = Livewire::test(CreateFacture::class)->html();

        foreach (['quantite', 'prix_unitaire_ttc', 'taux_tva', 'remise_pourcentage'] as $champ) {
            $this->assertMatchesRegularExpression(
                '/wire:model\.live\.unintrusive="data\.lignes\.[^"]+\.'.$champ.'"/',
                $html,
                "Le champ {$champ} doit rester tel que tapé pendant la frappe.",
            );
        }

        $this->get(FactureResource::getUrl('create'))
            ->assertSuccessful()
            ->assertSee("Livewire.hook('commit'", false)
            ->assertSee("includes('unintrusive')", false);
    }

    /**
     * "5," ou "5." en route vers "5,5" n'est pas encore un nombre : le
     * navigateur l'envoie vide. Le taux d'un service -- seul taux qui se
     * saisit, celui d'un produit étant celui de sa fiche (C15) -- vide en
     * pleine frappe ne fait jamais tomber la ligne à 0 % (12,00 € HT sans
     * TVA) : elle reste au taux du magasin jusqu'au taux tapé, et son PU
     * TTC ne bouge pas.
     */
    public function test_un_taux_de_tva_en_cours_de_frappe_ne_fait_pas_tomber_la_ligne_a_zero(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $service = Service::factory()->create(['nom' => 'Pose écran']);

        $component = Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => Client::factory()->create()->id,
            'lignes' => [['service_id' => $service->id, 'description' => 'Pose écran', 'quantite' => 1, 'prix_unitaire_ttc' => '12', 'taux_tva' => '20', 'remise_pourcentage' => 0]],
        ]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertSee('Total HT 10,00 € · TVA 2,00 € · Total TTC 12,00 €')
            ->set("data.lignes.{$cle}.taux_tva", '')
            ->assertSee('Total HT 10,00 € · TVA 2,00 € · Total TTC 12,00 €')
            ->assertDontSee('Total HT 12,00 € · TVA 0,00 €')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '12')
            ->set("data.lignes.{$cle}.taux_tva", '5.5')
            ->assertSee('Total HT 11,37 € · TVA 0,63 € · Total TTC 12,00 €')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '12');
    }

    /**
     * Un brouillon rouvert après le passage du magasin en franchise en base
     * reprend le régime d'aujourd'hui : ses lignes de produit affichent
     * 0 %, et réenregistré, il s'émet sans TVA au même prix TTC -- ce que
     * demande le refus de l'émission (voir EmettreFactureAction).
     */
    public function test_un_brouillon_rouvert_apres_le_passage_en_franchise_se_recalcule_a_zero_pour_cent(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3]]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);

        $component = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));
        $component
            ->assertSet("data.lignes.{$cle}.taux_tva", '0')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '12.00')
            ->assertSee('Total HT 36,00 € · TVA 0,00 € · Total TTC 36,00 €')
            ->call('save')
            ->assertHasNoFormErrors();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon->fresh());

        $this->assertTrue($facture->franchise_en_base);
        $this->assertSame(['36.00', '0.00', '36.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Dans l'autre sens : enregistré en franchise, un service est à 0 %
     * sans que ce taux ait été saisi. Rouvert après la sortie de la
     * franchise, il propose le taux du territoire du magasin, et
     * réenregistré, il s'émet avec sa TVA au même prix TTC.
     */
    public function test_un_service_enregistre_en_franchise_reprend_le_taux_du_territoire_apres_la_sortie_de_la_franchise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::FranchiseEnBase]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['service_id' => Service::factory()->create(['nom' => 'Pose écran'])->id, 'prix_unitaire_ttc' => '120.00', 'taux_tva' => 20, 'quantite' => 1],
        ]);
        $this->reglerLaSociete($magasin, ['regime_tva' => RegimeTva::ReelNormalMensuel]);

        $component = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()]);
        $component
            ->assertSet('data.lignes.'.array_key_first($component->get('data.lignes')).'.taux_tva', '20')
            ->call('save')
            ->assertHasNoFormErrors();
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $brouillon->fresh());

        $this->assertFalse($facture->franchise_en_base);
        $this->assertSame('20.00', $facture->lignes->sole()->taux_tva);
        $this->assertSame(['100.00', '20.00', '120.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function typesDeClient(): array
    {
        return [
            'particulier' => [false],
            'professionnel' => [true],
        ];
    }

    /**
     * Une frappe dans une ligne calcule l'aperçu de toutes les lignes une
     * seule fois : le client et le catalogue ne se relisent ni pour chaque
     * ligne, ni pour chaque montant affiché -- ni, pour un professionnel,
     * pour savoir si chaque ligne affiche son HT.
     */
    #[DataProvider('typesDeClient')]
    public function test_une_frappe_lit_le_client_et_le_catalogue_autant_de_fois_pour_vingt_lignes_que_pour_une(bool $professionnel): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $lecturesPourUneLigne = $this->lecturesDUneFrappeDansUnBrouillonDe(1, 'Total HT 20,00 € · TVA 4,00 € · Total TTC 24,00 €', $professionnel);
        $lecturesPourVingtLignes = $this->lecturesDUneFrappeDansUnBrouillonDe(20, 'Total HT 210,00 € · TVA 42,00 € · Total TTC 252,00 €', $professionnel);

        $this->assertSame($lecturesPourUneLigne, $lecturesPourVingtLignes);
    }

    /**
     * Les lectures des clients et des produits que fait une frappe dans la
     * quantité de la première ligne (de 1 à 2) d'un brouillon de produits à
     * 12,00 € TTC, qui affiche ensuite ses nouveaux totaux.
     *
     * @return array{clients: int, produits: int}
     */
    private function lecturesDUneFrappeDansUnBrouillonDe(int $nombreDeLignes, string $totauxAttendus, bool $professionnel): array
    {
        $produits = Produit::factory()->count($nombreDeLignes)->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create(), $produits
            ->map(fn (Produit $produit): array => ['produit_id' => $produit->id, 'quantite' => 1])
            ->all());
        $component = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $component
            ->set('data.lignes.'.array_key_first($component->get('data.lignes')).'.quantite', '2')
            ->assertSee($totauxAttendus);

        $requetes = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return [
            'clients' => $requetes->filter(fn (string $requete): bool => preg_match('/from [`"]clients[`"]/', $requete) === 1)->count(),
            'produits' => $requetes->filter(fn (string $requete): bool => preg_match('/from [`"]produits[`"]/', $requete) === 1)->count(),
        ];
    }

    /**
     * Le taux d'un service se saisit librement (B2) : un taux qui n'est
     * pas en vigueur en métropole est signalé sur le champ du taux, plus
     * sous la ligne, qui n'a plus de total (point 12), sans empêcher
     * d'enregistrer (X8).
     */
    public function test_un_taux_de_service_inhabituel_est_signale_sur_le_champ_du_taux(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $service = Service::factory()->create(['nom' => 'Pose écran']);
        $aide = 'Taux inhabituel pour un magasin en métropole (en vigueur : 20 ; 10 ; 5,5 ; 2,1 %) : vérifiez votre saisie.';

        $formulaire = Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => Client::factory()->create()->id,
                'lignes' => [['service_id' => $service->id, 'description' => 'Pose écran', 'quantite' => 1, 'prix_unitaire_ttc' => '11.96', 'taux_tva' => '19.6', 'remise_pourcentage' => 0]],
            ]);
        $ligneSaisie = array_key_first($formulaire->get('data.lignes'));

        $formulaire
            ->assertSee($aide)
            ->assertFormFieldExists("lignes.{$ligneSaisie}.taux_tva", fn (TextInput $taux): bool => $taux->getHelperText() === $aide)
            ->call('create')
            ->assertHasNoFormErrors();

        $ligne = Facture::query()->sole()->lignes->sole();
        $this->assertSame(['19.60', '10.00', '1.96', '11.96'], [$ligne->taux_tva, $ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc]);
    }

    /**
     * Les totaux du bas d'une facture de deux taux gagnent une rangée par
     * taux (point 12), lue dans le même calcul que l'enregistrement : à
     * 20 %, 2 x 25,00 € font 50,00 ÷ 1,2 = 41,67 € HT + 8,33 € de TVA ; à
     * 5,5 %, 10,00 € font 9,48 € HT + 0,52 € de TVA. La facture enregistrée
     * porte les mêmes montants ; avec un seul taux, seul le total reste.
     */
    public function test_les_totaux_du_bas_se_ventilent_par_taux(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $tshirt = Produit::factory()->create(['nom' => 'T-shirt', 'prix_vente_ttc' => '25.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $livre = Produit::factory()->create(['nom' => 'Livre', 'prix_vente_ttc' => '10.00', 'taux_tva' => 5.5, 'suivi_stock' => false]);
        $ligneDuTshirt = ['produit_id' => $tshirt->id, 'description' => 'T-shirt', 'quantite' => 2, 'prix_unitaire_ttc' => '25.00', 'remise_pourcentage' => 0];
        $ligneDuLivre = ['produit_id' => $livre->id, 'description' => 'Livre', 'quantite' => 1, 'prix_unitaire_ttc' => '10.00', 'remise_pourcentage' => 0];

        Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id, 'lignes' => [$ligneDuTshirt]])
            ->assertSee('Total HT 41,67 € · TVA 8,33 € · Total TTC 50,00 €')
            ->assertDontSee('À 20 %')
            ->fillForm(['client_id' => $client->id, 'lignes' => [$ligneDuTshirt, $ligneDuLivre]])
            ->assertSee('À 20 % : 41,67 € HT + 8,33 € de TVA = 50,00 € TTC')
            ->assertSee('À 5,5 % : 9,48 € HT + 0,52 € de TVA = 10,00 € TTC')
            ->assertSee('Total HT 51,15 € · TVA 8,85 € · Total TTC 60,00 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->sole();
        $this->assertSame(['51.15', '8.85', '60.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertEqualsCanonicalizing([
            ['taux_tva' => '20.00', 'montant_ht' => 41.67, 'montant_tva' => 8.33, 'montant_ttc' => 50.0],
            ['taux_tva' => '5.50', 'montant_ht' => 9.48, 'montant_tva' => 0.52, 'montant_ttc' => 10.0],
        ], $facture->ventilation_tva);
    }

    /**
     * Facture signalée par le magasin : 2 écrans à 40 € TTC (20 %), un câble
     * à 10 € sans TVA, une coque à 10 € TTC (20 %). Le HT du taux de 20 %,
     * 90,00 ÷ 1,2 = 75,00 €, se répartit 66,67 € et 8,33 € : le total HT
     * fait 85,00 € (jamais 84,99 €), la TVA 15,00 €, et HT + TVA = TTC. La
     * fiche montre le HT et la TVA de chaque ligne ; le PDF de ce ticket,
     * le TTC de chaque ligne (2 x 40,00 € = 80,00 €, P3) ; tous deux, le
     * récapitulatif par taux et les totaux.
     */
    public function test_la_facture_du_magasin_totalise_85_ht_et_le_montre_ligne_par_ligne(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Ecran Iphone 18 Pro Max', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['nom' => 'Cable ip 17', 'prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $coque = Produit::factory()->create(['nom' => 'Coque ip 17', 'prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
            ['produit_id' => $coque->id, 'quantite' => 1],
        ]);

        $this->assertSame(['85.00', '15.00', '100.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 75.0, 'montant_tva' => 15.0, 'montant_ttc' => 90.0],
            ['taux_tva' => '0.00', 'montant_ht' => 10.0, 'montant_tva' => 0.0, 'montant_ttc' => 10.0],
        ], $facture->ventilation_tva);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('66,67')
            ->assertSee('13,33')
            ->assertSee('85,00')
            ->assertSee('15,00')
            ->assertSee('TVA par taux')
            ->assertSee('75,00')
            ->assertDontSee('84,99');

        $pdf = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
        $this->assertStringContainsString('<td class="montant">80,00 €</td>', $pdf);
        $this->assertStringContainsString('75,00', $pdf);
        $this->assertStringContainsString('85,00', $pdf);
        $this->assertStringNotContainsString('84,99', $pdf);
    }

    /**
     * Le même panier vendu à un professionnel a les mêmes montants : le HT
     * du taux de 20 %, 75,00 €, se répartit 66,67 € et 8,33 €, la TVA par
     * différence, 13,33 € et 1,67 €. Il totalise 85,00 € HT + 15,00 € =
     * 100,00 €, et sa fiche explique le calcul sous les lignes.
     */
    public function test_la_meme_facture_a_un_professionnel_totalise_85_ht_et_explique_son_calcul(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $coque = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
            ['produit_id' => $coque->id, 'quantite' => 1],
        ]);

        $this->assertSame([['66.67', '13.33', '80.00'], ['10.00', '0.00', '10.00'], ['8.33', '1.67', '10.00']], $facture->lignes->map(fn (LigneFacture $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->all());
        $this->assertSame(['85.00', '15.00', '100.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSee('66,67')
            ->assertSee('13,33')
            ->assertSee('85,00')
            ->assertSee('Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence.');
    }

    /**
     * La fiche montre le PU HT de chaque ligne tel qu'il est enregistré,
     * avec ses décimales utiles (Q1-b) : 0,833333 € pour un article à
     * 1,00 € TTC à 20 %, 100,00 € pour un écran à 120,00 € TTC.
     */
    public function test_la_fiche_montre_le_pu_ht_avec_ses_decimales_utiles(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $vis = Produit::factory()->create(['prix_vente_ttc' => 1, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ecran = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20, 'suivi_stock' => false]);

        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => $vis->id, 'quantite' => 1000],
            ['produit_id' => $ecran->id, 'quantite' => 1],
        ]);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSeeInOrder(['0,833333 €', '833,33', '100,00 €', '100,00']);
    }

    /**
     * Capture d'écran du magasin : une quantité s'écrit "2", jamais
     * "2.000", et un taux "20 %" ou "0 %", jamais "20.00 %" -- sur la
     * fiche, sur le PDF remis au client, et dans le formulaire quand on
     * rouvre le brouillon.
     */
    public function test_quantites_et_taux_saffichent_sans_zeros_inutiles_partout(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $ecran = Produit::factory()->create(['nom' => 'Ecran Iphone 18 Pro Max', 'prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $cable = Produit::factory()->create(['nom' => 'Cable ip 17', 'prix_vente_ttc' => 10, 'taux_tva' => 0, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ]);

        $this->assertSame(2, $facture->lignes->firstOrFail()->quantite);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('20 %')
            ->assertSee('0 %')
            ->assertDontSee('2.000')
            ->assertDontSee('1.000')
            ->assertDontSee('20.00 %')
            ->assertDontSee('0.00 %');

        $pdf = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));
        $this->assertStringContainsString('<td class="montant">2</td>', $pdf);
        $this->assertStringContainsString('<td class="montant">20 %</td>', $pdf);
        $this->assertStringContainsString('<td class="montant">0 %</td>', $pdf);
        $this->assertStringNotContainsString('2.000', $pdf);

        $component = Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertSet("data.lignes.{$cle}.quantite", 2)
            ->assertSet("data.lignes.{$cle}.taux_tva", '20')
            ->assertSet("data.lignes.{$cle}.remise_pourcentage", '0');
    }

    public function test_une_quantite_non_entiere_est_refusee_par_le_formulaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '1.5')
            ->call('create')
            ->assertHasFormErrors(["lignes.{$cle}.quantite" => 'integer']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le PU TTC tapé est gardé tel quel, le PU HT en est la conversion au
     * centime, et la ligne vaut quantité x PU TTC : 3 x 10,05 € = 30,15 €
     * TTC, dont 30,15 ÷ 1,2 = 25,125 -> 25,13 € HT, un demi-centime exact
     * arrondi au centime supérieur (réponse Q2).
     */
    public function test_le_pu_ttc_saisi_est_garde_tel_quel(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 10, 'taux_tva' => 20]);

        $component = Livewire::test(CreateFacture::class)->fillForm(['client_id' => $client->id]);
        $cle = array_key_first($component->get('data.lignes'));

        // 10,05 € TTC / 1,20 = 8,375 € HT, au millionième d'euro (Q1-b).
        $component
            ->set("data.lignes.{$cle}.produit_id", $produit->id)
            ->set("data.lignes.{$cle}.quantite", '3')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '10.05')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '8.375')
            ->assertSee('30,15 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $this->assertSame(['25.13', '5.02', '30.15'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Reproduit le cas signalé par un magasin : un produit visé à 40 € TTC,
     * vendu par 2, affichait 79,99 € (33,33 € HT x 2 x 1,20). Le PU TTC de
     * la ligne fait foi : 40 x 2 = 80,00 €.
     */
    public function test_un_produit_vise_a_un_prix_ttc_rond_totalise_exactement_a_quantite_2(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ttc' => 40, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        $ligne = $facture->lignes->firstOrFail();

        $this->assertSame('80.00', $ligne->montant_ttc);
        $this->assertSame('80.00', $facture->total_ttc);
    }

    /**
     * Un client archivé garde ses factures (voir Facture::client()), mais
     * n'est plus proposé pour une nouvelle.
     */
    public function test_un_client_archive_n_est_plus_propose_pour_une_nouvelle_facture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $proposable = Client::factory()->create(['nom' => 'Client proposé']);
        $archive = Client::factory()->create(['nom' => 'Client archivé']);
        $archive->delete();

        Livewire::test(CreateFacture::class)
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => array_key_exists($proposable->id, $champ->getOptions())
                && ! array_key_exists($archive->id, $champ->getOptions()));
    }

    /**
     * Le client d'un brouillon archivé depuis reste affiché sur sa
     * modification, sans être proposé aux autres.
     */
    public function test_le_client_archive_d_un_brouillon_reste_affiche_a_sa_modification(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create(['nom' => 'Client archivé']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->delete();

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->assertFormFieldExists('client_id', fn (Select $champ): bool => $champ->getOptionLabel() === 'Client archivé');
    }

    /**
     * Le crédit d'avoir du client d'un brouillon archivé depuis reste
     * annoncé à sa modification : l'émission l'imputera quand même (voir
     * AppliquerAvoirsDisponiblesAction).
     */
    public function test_le_credit_du_client_archive_d_un_brouillon_reste_annonce_a_sa_modification(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $ticket = self::vendreAuComptant(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
        app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $ticket->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->delete();

        Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertSee('Crédit disponible pour ce client : 12,00 € (sera appliqué automatiquement).');
    }

    /**
     * L'échéance ne se saisit pas : le formulaire annonce qu'elle se fixe à
     * l'émission, avec le délai du client choisi, sinon celui du magasin
     * (B7).
     */
    public function test_l_echeance_s_annonce_fixee_a_l_emission_avec_le_delai_du_client(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->professionnel()->create(['delai_paiement_jours' => 45]);

        Livewire::test(CreateFacture::class)
            ->assertSee('Fixée à l&#039;émission : date d&#039;émission + 30 jours', escape: false)
            ->fillForm(['client_id' => $client->id])
            ->assertSee('Fixée à l&#039;émission : date d&#039;émission + 45 jours', escape: false);
    }

    /**
     * Une facture créée depuis le panneau reste sans échéance tant qu'elle
     * est un brouillon, et prend la sienne le jour de son émission : jamais
     * dans le passé.
     */
    public function test_le_brouillon_cree_depuis_le_panneau_prend_son_echeance_a_son_emission(): void
    {
        $this->travelTo('2026-10-01 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $brouillon = Facture::query()->sole();
        $this->assertNull($brouillon->date_echeance);
        $this->travelTo('2026-10-09 10:00:00');

        Livewire::test(ListFactures::class)->callTableAction('emettre', $brouillon);

        $this->assertSame('2026-11-08', $brouillon->fresh()->date_echeance->toDateString());
    }

    public function test_creer_et_emettre_une_facture_depuis_le_panneau(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->firstOrFail();
        $this->assertSame(StatutFacture::Brouillon, $facture->statut);
        $this->assertSame($responsable->id, $facture->cree_par);

        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        $facture->refresh();
        $this->assertSame(StatutFacture::Emise, $facture->statut);
        $this->assertNotNull($facture->numero);
        $this->assertSame(8, $produit->fresh()->stock_quantite);
    }

    /**
     * Sans clé de signature, le registre fiscal refuse l'émission : la
     * facture reste un brouillon sans numéro, l'utilisateur en lit la
     * raison au lieu d'une erreur serveur, et l'incident est signalé au
     * journal.
     */
    public function test_sans_cle_de_signature_l_emission_est_refusee_avec_un_message(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        config(['caisse_securisee.cle_privee' => null]);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        Livewire::test(ListFactures::class)
            ->callTableAction('emettre', $facture)
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
        $this->assertSame([StatutFacture::Brouillon, null], [$facture->fresh()->statut, $facture->fresh()->numero]);
    }

    /**
     * Même un responsable, qui a toutes les autorisations de son magasin,
     * n'a aucune action pour annuler une facture émise : il la corrige par
     * un avoir, qui rend le stock, et la facture reste émise avec son
     * numéro.
     */
    public function test_une_facture_emise_ne_s_annule_pas_depuis_le_panneau_elle_se_corrige_par_un_avoir(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'stock_quantite' => 10]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create');

        $facture = Facture::query()->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        $numero = $facture->fresh()->numero;
        $this->assertSame(7, $produit->fresh()->stock_quantite);

        Livewire::test(ListFactures::class)
            ->assertTableActionDoesNotExist('annuler')
            ->assertTableActionVisible('avoir', $facture->fresh())
            ->callTableAction('avoir', $facture->fresh(), data: ['motif' => 'Erreur de saisie']);

        $facture->refresh();
        $this->assertSame([StatutFacture::Payee, $numero], [$facture->statut, $facture->numero]);
        $this->assertSame(10, $produit->fresh()->stock_quantite);
    }

    public function test_telecharger_le_pdf_dune_facture_emise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        $telechargement = Livewire::test(ListFactures::class)
            ->callTableAction('telecharger_pdf', $facture->fresh())
            ->assertFileDownloaded("ticket-{$facture->fresh()->numero}.pdf");

        $this->assertStringStartsWith('%PDF', base64_decode($telechargement->effects['download']['content']));
    }

    public function test_un_brouillon_est_supprimable_mais_plus_une_fois_emis(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();

        Livewire::test(ListFactures::class)->assertTableActionVisible('delete', $facture);

        Livewire::test(ListFactures::class)->callTableAction('delete', $facture);
        $this->assertModelMissing($facture);
    }

    /**
     * Supprimer un brouillon inscrit l'abandon de la vente au journal des
     * événements : si le journal ne s'écrit pas, le magasin en lit la
     * raison et le brouillon reste.
     */
    public function test_sans_cle_de_signature_la_suppression_d_un_brouillon_est_refusee_avec_un_message(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create()->id, 'quantite' => 1]]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        Livewire::test(ListFactures::class)
            ->callTableAction('delete', $brouillon)
            ->assertNotified('Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.');

        $this->assertModelExists($brouillon);
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
    }

    public function test_sans_cle_de_signature_la_suppression_groupee_garde_le_brouillon_et_le_signale(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => Produit::factory()->create()->id, 'quantite' => 1]]);
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);
        Exceptions::fake([ModuleFiscalNonConfigureException::class]);

        Livewire::test(ListFactures::class)
            ->callTableBulkAction('delete', [$brouillon])
            ->assertNotified('1 ligne cochée n\'a pas été supprimée');

        $this->assertModelExists($brouillon);
        Exceptions::assertReported(ModuleFiscalNonConfigureException::class);
    }

    /**
     * Un brouillon doit rester modifiable sans devoir être recréé (lignes
     * comprises), pour ne pas obliger l'utilisateur à tout ressaisir en cas
     * d'erreur de saisie -- voir ModifierFactureAction.
     */
    public function test_modifier_un_brouillon_depuis_le_panneau_remplace_les_lignes_et_recalcule_les_totaux(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $autreClient = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm([
                'client_id' => $autreClient->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            // Filament reste par défaut sur le formulaire après sauvegarde :
            // une fois le brouillon corrigé, le vendeur n'a plus rien à y
            // faire et doit revenir directement à la liste.
            ->assertRedirect(FactureResource::getUrl('index'));

        $facture->refresh();
        $this->assertSame($autreClient->id, $facture->client_id);
        $this->assertSame(1, $facture->lignes()->count());
        $this->assertSame('150.00', $facture->total_ht);
    }

    /**
     * Rouvrir un brouillon puis l'enregistrer sans rien toucher ne doit
     * jamais faire bouger son total : le PU TTC enregistré (qui fait
     * référence) est rechargé tel quel dans le formulaire, pas reconstruit
     * à partir du HT.
     */
    public function test_reenregistrer_un_brouillon_sans_modification_garde_exactement_son_total(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);

        $this->assertSame('80.00', $facture->total_ttc);

        $component = Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '40.00')
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ht", '33.333333')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('80.00', $facture->fresh()->total_ttc);
    }

    public function test_laction_modifier_disparait_des_quune_facture_est_emise(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->firstOrFail();
        Livewire::test(ListFactures::class)->assertTableActionVisible('edit', $facture);

        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        Livewire::test(ListFactures::class)->assertTableActionHidden('edit', $facture->fresh());
    }

    public function test_la_suppression_en_masse_ignore_les_factures_deja_emises(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $factureBrouillon = Facture::query()->latest('id')->firstOrFail();

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $factureEmise = Facture::query()->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $factureEmise);

        Livewire::test(ListFactures::class)
            ->callTableBulkAction('delete', [$factureBrouillon->fresh(), $factureEmise->fresh()]);

        $this->assertModelMissing($factureBrouillon);
        $this->assertModelExists($factureEmise);
    }

    public function test_la_page_de_detail_affiche_leg_reporting_pour_un_client_particulier(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create(['type_client' => TypeClient::Particulier]);
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($facture->numero)
            ->assertSee('e-reporting agrégé');
    }

    public function test_la_page_de_detail_affiche_le_statut_pdp_pour_un_client_professionnel(): void
    {
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        MandatPdp::factory()->create(['magasin_id' => $magasin->id]);
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);

        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettreFactureDepuisLePanneau($client);

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Acceptée par le PDP');
    }

    /**
     * @return Facture
     */
    public function test_enregistrer_un_brouillon_garde_le_service_de_ses_lignes_de_service(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $service = Service::factory()->create(['nom' => 'Remplacement écran']);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['service_id' => $service->id, 'prix_unitaire_ttc' => 15, 'taux_tva' => 20, 'quantite' => 1],
        ]);

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $ligne = $facture->fresh()->lignes->sole();
        $this->assertSame($service->id, $ligne->service_id);
        $this->assertSame('15.00', $ligne->montant_ttc);
    }

    /**
     * B8 et point 23 : l'action « Encaisser » préremplit la première part
     * du règlement avec le mode de paiement préféré du client de la
     * facture et ce qui en reste dû.
     */
    public function test_encaisser_preselectionne_le_mode_de_paiement_prefere_du_client(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $facture = $this->emettreFactureDepuisLePanneau(Client::factory()->create(['mode_paiement_prefere' => ModePaiement::CarteBancaire]));

        $liste = Livewire::test(ListFactures::class)->mountTableAction('encaisser', $facture);
        $part = array_key_first($liste->get('mountedTableActionsData.0.parts'));

        $liste->assertTableActionDataSet(["parts.{$part}.mode" => ModePaiement::CarteBancaire->value, "parts.{$part}.montant" => '60.00']);
    }

    private function emettreFactureDepuisLePanneau(Client $client)
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => 60, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);

        return $facture->fresh();
    }

    /**
     * La liste montre le client tel que le document l'imprime : le nom figé
     * à l'émission, la fiche pour un brouillon ; la recherche trouve aussi
     * bien le nom imprimé que celui de la fiche aujourd'hui.
     */
    public function test_la_liste_montre_le_client_fige_et_la_recherche_trouve_aussi_la_fiche_actuelle(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create(['nom' => 'Jean Ancien']);
        $emise = $this->emettreFactureDepuisLePanneau($client);
        $client->update(['nom' => 'Marc Nouveau']);
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client->fresh(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $autre = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(['nom' => 'Paul Tiers']), [['produit_id' => $produit->id, 'quantite' => 1]]);

        $liste = Livewire::test(ListFactures::class);

        $liste->assertTableColumnStateSet('client_nom', 'Jean Ancien', $emise)
            ->assertTableColumnStateSet('client_nom', 'Marc Nouveau', $brouillon);
        $liste->searchTable('Jean Ancien')
            ->assertCanSeeTableRecords([$emise])
            ->assertCanNotSeeTableRecords([$brouillon, $autre]);
        $liste->searchTable('Marc Nouveau')
            ->assertCanSeeTableRecords([$emise, $brouillon])
            ->assertCanNotSeeTableRecords([$autre]);
    }

    /**
     * Un brouillon numéroté directement en base n'a pas de copie du client :
     * la liste et sa fiche s'affichent quand même, sans nom, pour que le
     * responsable voie le document que signale la vérification de
     * l'intégrité.
     */
    public function test_un_document_numerote_en_base_sans_copie_du_client_n_empeche_pas_l_affichage(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(['nom' => 'Jean Ancien']), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $intacte = $this->emettreFactureDepuisLePanneau(Client::factory()->create(['nom' => 'Paul Tiers']));

        DB::table('factures')->where('magasin_id', $magasin->id)->where('id', $brouillon->id)->update(['numero' => 'TK-MABOUT-2026-000009', 'statut' => 'emise', 'date_emission' => '2026-10-09 08:00:00']);
        $numerote = $brouillon->fresh();

        Livewire::test(ListFactures::class)
            ->assertCanSeeTableRecords([$numerote, $intacte])
            ->assertTableColumnStateSet('client_nom', null, $numerote)
            ->assertTableColumnStateSet('client_nom', 'Paul Tiers', $intacte);
        Livewire::test(ViewFacture::class, ['record' => $numerote->getRouteKey()])
            ->assertSee('TK-MABOUT-2026-000009')
            ->assertDontSee('Jean Ancien');
    }

    public function test_la_liste_ne_montre_que_les_factures_du_vendeur_sans_vue_totale(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeurA = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);
        $vendeurB = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE]);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20]);

        $this->actingAs($vendeurA);
        Filament::setTenant($magasin);
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        $this->actingAs($vendeurB);
        Filament::setTenant($magasin);
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 1, 'prix_unitaire_ht' => 10, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');

        Livewire::test(ListFactures::class)
            ->assertCanSeeTableRecords(Facture::where('cree_par', $vendeurB->id)->get())
            ->assertCanNotSeeTableRecords(Facture::where('cree_par', $vendeurA->id)->get());
    }

    public function test_creer_un_nouveau_client_a_la_volee_depuis_le_formulaire_facture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        Livewire::test(CreateFacture::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Client Créé En Direct',
                'type_client' => 'professionnel',
                'siren' => '123456789',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('clients', ['nom' => 'Client Créé En Direct', 'type_client' => 'professionnel', 'siren' => '123456789']);
    }

    /**
     * La fenêtre « Nouveau client » applique les règles de la fiche : un
     * professionnel établi en France a un SIREN.
     */
    public function test_la_creation_rapide_refuse_un_professionnel_etabli_en_france_sans_siren(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        Livewire::test(CreateFacture::class)
            ->callFormComponentAction('client_id', 'createOption', data: [
                'nom' => 'Client Créé En Direct',
                'type_client' => 'professionnel',
            ])
            ->assertHasFormComponentActionErrors(['siren' => 'Le SIREN (ou le SIRET) est obligatoire pour un client professionnel établi en France.']);

        $this->assertDatabaseMissing('clients', ['nom' => 'Client Créé En Direct']);
    }

    /**
     * Un avoir peut couvrir plusieurs factures : la fiche d'une facture ne
     * montre que ce qu'il crédite sur elle (30,00 €), jamais le total de
     * l'avoir (100,00 €) ni les produits d'une autre facture.
     */
    public function test_un_avoir_sur_plusieurs_factures_ne_montre_que_ce_qui_credite_cette_facture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $coque = Produit::factory()->create(['nom' => 'Coque bleue', 'prix_vente_ttc' => 30, 'taux_tva' => 20, 'suivi_stock' => false]);
        $casque = Produit::factory()->create(['nom' => 'Casque sans fil', 'prix_vente_ttc' => 70, 'taux_tva' => 20, 'suivi_stock' => false]);
        $premiere = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $coque->id, 'quantite' => 1]]));
        $seconde = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $casque->id, 'quantite' => 1]]));

        $avoir = app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $client, [
            ['ligne_facture_id' => $premiere->lignes->firstOrFail()->id, 'quantite' => 1],
            ['ligne_facture_id' => $seconde->lignes->firstOrFail()->id, 'quantite' => 1],
        ]));
        $this->assertSame('100.00', $avoir->total_ttc);

        Livewire::test(ViewFacture::class, ['record' => $premiere->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Crédité sur cette facture (TTC)')
            ->assertSee('Coque bleue')
            ->assertDontSee('Casque sans fil')
            ->assertDontSee('100,00');
    }

    /**
     * Un taux de TVA de -100 % diviserait par zéro en passant du TTC au
     * HT : pendant la frappe rien ne plante, et le formulaire le refuse.
     */
    public function test_un_taux_de_tva_hors_bornes_ne_fait_rien_planter_et_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $service = Service::factory()->create(['nom' => 'Réparation']);
        $component = Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => Client::factory()->create()->id,
            'lignes' => [['service_id' => $service->id, 'description' => 'Réparation', 'quantite' => 1, 'taux_tva' => '20', 'remise_pourcentage' => 0]],
        ]);
        $cle = array_key_first($component->get('data.lignes'));

        $component
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '12')
            ->set("data.lignes.{$cle}.taux_tva", '-100')
            ->set("data.lignes.{$cle}.prix_unitaire_ttc", '15')
            ->set("data.lignes.{$cle}.remise_pourcentage", '150')
            ->assertSuccessful()
            ->call('create')
            ->assertHasFormErrors([
                "lignes.{$cle}.taux_tva" => 'min',
                "lignes.{$cle}.remise_pourcentage" => 'max',
            ]);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * La remise est une mention obligatoire et explique pourquoi PU x
     * quantité diffère du total : elle apparaît sur le PDF dès qu'une ligne
     * en a une, et seulement dans ce cas.
     */
    public function test_la_remise_apparait_sur_le_pdf_seulement_si_une_ligne_en_a_une(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $avecRemise = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2, 'remise_pourcentage' => 12.5]]);
        $sansRemise = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 2]]);

        $rendre = fn (Facture $facture): string => app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));

        $pdf = $rendre($avecRemise);
        $this->assertStringContainsString('<th class="montant">Remise</th>', $pdf);
        $this->assertStringContainsString('<td class="montant">12,5 %</td>', $pdf);
        $this->assertStringNotContainsString('<th class="montant">Remise</th>', $rendre($sansRemise));

        Livewire::test(ViewFacture::class, ['record' => $avecRemise->getRouteKey()])->assertSee('12,5 %');
    }

    /**
     * La règle « exists » qu'un Select relationship() ajoute seule
     * interroge la table entière : le client d'un autre magasin, envoyé par
     * une requête forgée, doit être refusé par la limite au magasin.
     */
    public function test_le_client_d_un_autre_magasin_est_refuse_a_la_modification_d_un_brouillon(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Autre boutique']);
        $clientDUnAutreMagasin = Client::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm(['client_id' => $clientDUnAutreMagasin->id])
            ->call('save')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame($client->id, $facture->fresh()->client_id);
    }

    public function test_un_client_archive_ne_peut_pas_etre_choisi_pour_un_brouillon(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $clientArchive = Client::factory()->create();
        $clientArchive->delete();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm(['client_id' => $clientArchive->id])
            ->call('save')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame($client->id, $facture->fresh()->client_id);
    }

    /**
     * Le client déjà rattaché n'est pas choisi dans le formulaire : archivé
     * depuis, il reste celui du brouillon, que l'on peut toujours corriger.
     */
    public function test_le_client_archive_deja_rattache_reste_celui_du_brouillon_modifie(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->delete();

        Livewire::test(EditFacture::class, ['record' => $facture->getRouteKey()])
            ->fillForm(['lignes' => [['produit_id' => $produit->id, 'quantite' => 3, 'taux_tva' => 20, 'remise_pourcentage' => 0]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $facture->refresh();
        $this->assertSame($client->id, $facture->client_id);
        $this->assertSame('120.00', $facture->total_ttc);
    }

    /**
     * Une requête forgée qui envoie malgré tout un client archivé est
     * refusée, et aucune facture n'est créée.
     */
    public function test_un_client_archive_est_refuse_a_la_creation_d_une_facture(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsableDeTest($magasin);
        $this->actingAs($responsable);
        Filament::setTenant($magasin);
        $archive = Client::factory()->trashed()->create();
        $produit = Produit::factory()->create(['prix_vente_ttc' => 40, 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $archive->id,
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
            ])
            ->call('create')
            ->assertHasFormErrors(['client_id' => 'exists']);

        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Le magasin de Lyon à l'identité légale complète, n° de TVA
     * FR88100000009, et son responsable de test connecté, le 9 octobre
     * 2026 à 10 h 15 à Paris.
     */
    private function magasinQuiVendHorsDeFrance(): Magasin
    {
        $this->travelTo('2026-10-09 10:15:00');
        $magasin = $this->completerIdentiteLegale($this->ouvrirUneSociete(['nom' => 'Ma Boutique']));
        $this->actingAs($this->responsableDeTest($magasin));
        Filament::setTenant($magasin);

        return $magasin;
    }

    private function brouillonDeLivraisonIntracommunautaire(Client $client): Facture
    {
        return app(CreerFactureAction::class)->handle(self::auteur(), $client, [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => '25.00', 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 12]], [
            'nature_operation' => NatureOperation::LivraisonIntracommunautaire,
            'livraison_adresse' => 'Musterstraße 1',
            'livraison_code_postal' => '10115',
            'livraison_ville' => 'Berlin',
            'livraison_pays' => 'DE',
            'livraison_date' => '2026-10-12',
            'transport_par' => TransportPar::Magasin,
        ]);
    }

    /**
     * « Émettre » une livraison intracommunautaire vérifie d'abord sur VIES
     * le n° de TVA du client, faute de vérification valide du jour, puis
     * émet la facture avec cette vérification.
     */
    public function test_emettre_une_livraison_intracommunautaire_verifie_d_abord_le_numero_sur_vies(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $brouillon = $this->brouillonDeLivraisonIntracommunautaire(Client::factory()->etranger('DE', 'DE123456789')->create());
        Http::preventStrayRequests();
        Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::response(['countryCode' => 'DE', 'vatNumber' => '123456789', 'valid' => true, 'requestIdentifier' => 'WAPIAAAAW1234567', 'name' => 'MUSTER GMBH', 'address' => '---'])]);

        Livewire::test(ListFactures::class)->callTableAction('emettre', $brouillon);

        $facture = $brouillon->fresh();
        $this->assertSame([StatutFacture::Emise, ConsultationVies::query()->sole()->id], [$facture->statut, $facture->consultation_vies_id]);
        $this->assertSame(['250.00', '0.00', '250.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
        Http::assertSentCount(1);
    }

    public function test_une_verification_vies_valide_du_jour_evite_une_nouvelle_consultation(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $consultation = ConsultationVies::forceCreate(['client_id' => $client->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'identifiant_consultation' => 'WAPIAAAAW1234567', 'consultee_le' => now()->subHour(), 'reponse' => '{"valid":true}']);
        $brouillon = $this->brouillonDeLivraisonIntracommunautaire($client);
        Http::preventStrayRequests();

        Livewire::test(ListFactures::class)->callTableAction('emettre', $brouillon);

        $this->assertSame([StatutFacture::Emise, $consultation->id], [$brouillon->fresh()->statut, $brouillon->fresh()->consultation_vies_id]);
        Http::assertNothingSent();
    }

    public function test_vies_indisponible_laisse_la_livraison_intracommunautaire_en_brouillon_et_le_dit(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $brouillon = $this->brouillonDeLivraisonIntracommunautaire(Client::factory()->etranger('DE', 'DE123456789')->create());
        Http::preventStrayRequests();
        Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::response(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]], 500)]);

        Livewire::test(ListFactures::class)
            ->callTableAction('emettre', $brouillon)
            ->assertNotified('Le service européen VIES ne répond pas pour ce pays : réessayez dans quelques minutes. Sans n° de TVA vérifié, la vente reste à la TVA française.');

        $this->assertSame([StatutFacture::Brouillon, null], [$brouillon->fresh()->statut, $brouillon->fresh()->numero]);
        $this->assertSame(0, ConsultationVies::query()->count());
    }

    public function test_un_numero_inconnu_de_vies_refuse_la_livraison_intracommunautaire(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $brouillon = $this->brouillonDeLivraisonIntracommunautaire(Client::factory()->etranger('DE', 'DE999999999')->create());
        Http::preventStrayRequests();
        Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::response(['countryCode' => 'DE', 'vatNumber' => '999999999', 'valid' => false])]);

        Livewire::test(ListFactures::class)
            ->callTableAction('emettre', $brouillon)
            ->assertNotified('Impossible d\'émettre en « Livraison intracommunautaire à un professionnel » : le n° de TVA DE999999999 n\'a pas été trouvé valide sur VIES le 09/10/2026. Choisissez « Vente en France » (TVA française) si ces conditions ne sont pas remplies.');

        $this->assertSame(StatutFacture::Brouillon, $brouillon->fresh()->statut);
        $this->assertFalse(ConsultationVies::query()->sole()->valide);
    }

    /**
     * Un export saisi dans le panneau se calcule comme il s'enregistre,
     * sans TVA, et part toujours par le magasin : le transport ne se
     * choisit pas.
     */
    public function test_creer_un_export_depuis_le_panneau_enregistre_sa_nature_et_sa_livraison(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => '1.00', 'taux_tva' => 20, 'suivi_stock' => false]);

        Livewire::test(CreateFacture::class)
            ->fillForm([
                'client_id' => $client->id,
                'nature_operation' => NatureOperation::ExportHorsUe->value,
                'livraison_adresse' => '1 Main Street',
                'livraison_code_postal' => '10001',
                'livraison_ville' => 'New York',
                'livraison_pays' => 'us',
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1000, 'remise_pourcentage' => 0]],
            ])
            ->assertFormFieldIsHidden('transport_par')
            ->assertSeeText('Total HT 833,33 € · TVA 0,00 € · Total TTC 833,33 €')
            ->call('create')
            ->assertHasNoFormErrors();

        $facture = Facture::query()->sole();
        $this->assertSame([NatureOperation::ExportHorsUe, 'US', 'New York', TransportPar::Magasin], [$facture->nature_operation, $facture->livraison_pays, $facture->livraison_ville, $facture->transport_par]);
        $this->assertSame(['833.33', '0.00', '833.33'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * @return array<string, array{bool, RegimeTva, list<string>}>
     */
    public static function naturesProposees(): array
    {
        return [
            'particulier' => [false, RegimeTva::ReelNormalMensuel, ['vente_france', 'export_hors_ue', 'livraison_dom']],
            'professionnel' => [true, RegimeTva::ReelNormalMensuel, ['vente_france', 'export_hors_ue', 'livraison_dom', 'livraison_intracommunautaire', 'prestation_ue_autoliquidation', 'prestation_hors_ue']],
            'professionnel d\'un magasin en franchise' => [true, RegimeTva::FranchiseEnBase, ['vente_france']],
        ];
    }

    /**
     * @param  list<string>  $natures
     */
    #[DataProvider('naturesProposees')]
    public function test_les_natures_proposees_dependent_du_client_et_du_regime_du_magasin(bool $professionnel, RegimeTva $regime, array $natures): void
    {
        $this->reglerLaSociete($this->magasinQuiVendHorsDeFrance(), ['regime_tva' => $regime]);
        $client = $professionnel ? Client::factory()->professionnel()->create() : Client::factory()->create();

        Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id])
            ->assertFormFieldExists('nature_operation', fn (Select $champ): bool => array_keys($champ->getOptions()) === $natures);
    }

    /**
     * Passer d'un professionnel à un particulier ramène une nature qu'il
     * ne peut pas choisir à la vente en France.
     */
    public function test_choisir_un_particulier_ramene_une_nature_reservee_aux_professionnels_a_la_vente_en_france(): void
    {
        $this->magasinQuiVendHorsDeFrance();

        Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => Client::factory()->professionnel()->create()->id, 'nature_operation' => NatureOperation::PrestationHorsUe->value])
            ->fillForm(['client_id' => Client::factory()->create()->id])
            ->assertSet('data.nature_operation', NatureOperation::VenteEnFrance->value);
    }

    public function test_choisir_une_nature_avec_livraison_preremplit_l_adresse_du_client(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create(['adresse' => 'Musterstraße 1', 'code_postal' => '10115', 'ville' => 'Berlin']);

        Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id])
            ->fillForm(['nature_operation' => NatureOperation::LivraisonIntracommunautaire->value])
            ->assertSet('data.livraison_adresse', 'Musterstraße 1')
            ->assertSet('data.livraison_code_postal', '10115')
            ->assertSet('data.livraison_ville', 'Berlin')
            ->assertSet('data.livraison_pays', 'DE')
            ->assertFormFieldIsVisible('transport_par');
    }

    public function test_le_prix_d_un_service_se_saisit_sans_tva_hors_de_la_vente_en_france(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $service = Service::factory()->create(['nom' => 'Conseil']);
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();

        $page = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $client->id, 'nature_operation' => NatureOperation::PrestationUeAutoliquidation->value, 'lignes' => [['service_id' => $service->id, 'description' => 'Conseil', 'quantite' => 1, 'prix_unitaire_ttc' => '500.00']]]);
        $cle = array_key_first($page->get('data.lignes'));

        $page->assertFormFieldExists("lignes.{$cle}.prix_unitaire_ttc", fn (TextInput $champ): bool => $champ->getLabel() === 'Prix (sans TVA)')
            ->assertFormFieldIsHidden("lignes.{$cle}.taux_tva")
            ->assertSeeText('Total HT 500,00 € · TVA 0,00 € · Total TTC 500,00 €');
    }

    /**
     * Rouvert puis enregistré sans rien toucher, le brouillon d'une
     * livraison intracommunautaire garde sa nature, sa livraison et son
     * total sans TVA.
     */
    public function test_reenregistrer_une_livraison_intracommunautaire_sans_modification_garde_sa_nature_et_son_total(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $brouillon = $this->brouillonDeLivraisonIntracommunautaire(Client::factory()->etranger('DE', 'DE123456789')->create());

        Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertSet('data.nature_operation', NatureOperation::LivraisonIntracommunautaire->value)
            ->assertSet('data.transport_par', TransportPar::Magasin->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $brouillon->refresh();
        $this->assertSame([NatureOperation::LivraisonIntracommunautaire, 'Berlin', '2026-10-12', TransportPar::Magasin], [$brouillon->nature_operation, $brouillon->livraison_ville, $brouillon->livraison_date->toDateString(), $brouillon->transport_par]);
        $this->assertSame(['250.00', '0.00', '250.00'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
    }

    public function test_la_fiche_d_une_livraison_intracommunautaire_montre_sa_nature_sa_livraison_et_sa_verification(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        ConsultationVies::forceCreate(['client_id' => $client->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'identifiant_consultation' => 'WAPIAAAAW1234567', 'consultee_le' => now()->subHour(), 'reponse' => '{"valid":true}']);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), $this->brouillonDeLivraisonIntracommunautaire($client));

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSeeText('Livraison intracommunautaire à un professionnel')
            ->assertSeeText('Exonération de TVA, article 262 ter I du CGI')
            ->assertSeeText('Musterstraße 1')
            ->assertSeeText('Livraison le 12/10/2026')
            ->assertSeeText('consultation WAPIAAAAW1234567 du 09/10/2026 à 09:15');
    }

    private function clientAmericain(): Client
    {
        return Client::factory()->create(['nom' => 'Acme Inc.', 'type_client' => TypeClient::Professionnel, 'pays' => 'US', 'etabli_en_france' => false, 'identifiant_etranger' => 'EIN 12-3456789']);
    }

    /**
     * Un export dont l'émission est refusée repasse en vente en France :
     * les frais de port saisis 15,00 € sans TVA deviennent 18,00 € TTC à
     * 20 %, et la page le dit -- sinon ils seraient pris pour un prix TTC,
     * 12,50 € HT, et le magasin perdrait 2,50 € sans le savoir. Avec 1 000
     * articles à 1,00 € TTC : 848,33 € HT, 169,67 € de TVA, 1 018,00 € TTC.
     */
    public function test_repasser_un_export_en_vente_en_france_convertit_le_prix_sans_tva_d_un_service_en_prix_ttc(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = $this->clientAmericain();
        $produit = Produit::factory()->create(['prix_vente_ttc' => '1.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $port = Service::factory()->fraisAccessoires()->create(['nom' => 'Frais de port']);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1000],
            ['service_id' => $port->id, 'prix_unitaire_ttc' => '15.00', 'quantite' => 1],
        ], ['nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US']);

        $page = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->set('data.nature_operation', NatureOperation::VenteEnFrance->value);
        $lignePort = collect($page->get('data.lignes'))->filter(fn (array $ligne): bool => (int) $ligne['service_id'] === $port->id)->keys()->sole();
        $page->assertSet("data.lignes.{$lignePort}.prix_unitaire_ttc", '18.00')
            ->assertNotified('Prix des services convertis')
            ->call('save')
            ->assertHasNoFormErrors();

        $brouillon->refresh();
        $fraisDePort = $brouillon->lignes->firstWhere('service_id', $port->id);
        $this->assertSame(['15.00', '18.00', '20.00'], [$fraisDePort->montant_ht, $fraisDePort->prix_unitaire_ttc, $fraisDePort->taux_tva]);
        $this->assertSame(['848.33', '169.67', '1018.00'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
    }

    /**
     * Dans l'autre sens, un service saisi 18,00 € TTC en vente en France se
     * facture 15,00 € sans TVA une fois la vente passée en prestation hors
     * UE : le client étranger ne paie pas la TVA française comme prix.
     */
    public function test_sortir_une_vente_de_la_tva_francaise_convertit_le_prix_ttc_d_un_service_en_prix_sans_tva(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $conseil = Service::factory()->create(['nom' => 'Conseil']);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $this->clientAmericain(), [['service_id' => $conseil->id, 'prix_unitaire_ttc' => '18.00', 'taux_tva' => 20, 'quantite' => 1]]);

        $page = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->set('data.nature_operation', NatureOperation::PrestationHorsUe->value);
        $page->assertSet('data.lignes.'.array_key_first($page->get('data.lignes')).'.prix_unitaire_ttc', '15.00')
            ->assertNotified('Prix des services convertis')
            ->call('save')
            ->assertHasNoFormErrors();

        $brouillon->refresh();
        $this->assertSame(NatureOperation::PrestationHorsUe, $brouillon->nature_operation);
        $this->assertSame(['15.00', '0.00', '15.00'], [$brouillon->total_ht, $brouillon->total_tva, $brouillon->total_ttc]);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function vendeurOuResponsable(): array
    {
        return ['un vendeur' => [true], 'un responsable' => [false]];
    }

    /**
     * Un service au prix de référence de 11,00 € TTC à 10 %, vendu 10,00 €
     * sans TVA en prestation hors UE : le brouillon rouvert garde ce prix et
     * le taux de sa référence, et revenu en vente en France, la ligne
     * reprend 11,00 € à 10 % -- pas 12,00 € au taux du territoire, que le
     * vendeur ne pourrait ni enregistrer ni corriger.
     */
    #[DataProvider('vendeurOuResponsable')]
    public function test_un_service_a_prix_de_reference_rouvert_hors_de_la_tva_francaise_revient_en_france_a_son_prix_et_a_son_taux(bool $parUnVendeur): void
    {
        $magasin = $this->magasinQuiVendHorsDeFrance();
        $auteur = $parUnVendeur ? $this->membre($magasin, RoleMagasin::Vendeur) : $this->responsableDeTest($magasin);
        $this->actingAs($auteur);
        Filament::setTenant($magasin);
        $travaux = Service::factory()->auPrixDe('11.00', '10')->create(['nom' => 'Travaux']);
        $brouillon = app(CreerFactureAction::class)->handle($auteur, $this->clientAmericain(), [['service_id' => $travaux->id, 'quantite' => 1]], ['nature_operation' => NatureOperation::PrestationHorsUe]);

        $page = Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()]);
        $cle = array_key_first($page->get('data.lignes'));
        $page->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '10.00')
            ->assertSet("data.lignes.{$cle}.taux_tva", '10')
            ->set('data.nature_operation', NatureOperation::VenteEnFrance->value)
            ->assertSet("data.lignes.{$cle}.prix_unitaire_ttc", '11.00')
            ->assertSet("data.lignes.{$cle}.taux_tva", '10')
            ->call('save')
            ->assertHasNoFormErrors();

        $brouillon->refresh();
        $ligne = $brouillon->lignes->sole();
        $this->assertSame([NatureOperation::VenteEnFrance, '11.00', '10.00', '11.00'], [$brouillon->nature_operation, $ligne->prix_unitaire_ttc, $ligne->taux_tva, $brouillon->total_ttc]);
    }

    /**
     * Choisir un particulier ramène une prestation hors UE à la vente en
     * France : le prix de son service, saisi 15,00 € sans TVA, devient
     * 18,00 € TTC à 20 %.
     */
    public function test_choisir_un_particulier_convertit_le_prix_du_service_en_ramenant_la_vente_en_france(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $service = Service::factory()->create(['nom' => 'Conseil']);

        $page = Livewire::test(CreateFacture::class)
            ->fillForm(['client_id' => $this->clientAmericain()->id, 'nature_operation' => NatureOperation::PrestationHorsUe->value, 'lignes' => [['service_id' => $service->id, 'description' => 'Conseil', 'quantite' => 1, 'prix_unitaire_ttc' => '15.00', 'taux_tva' => '20']]])
            ->fillForm(['client_id' => Client::factory()->create()->id]);

        $page->assertSet('data.nature_operation', NatureOperation::VenteEnFrance->value)
            ->assertSet('data.lignes.'.array_key_first($page->get('data.lignes')).'.prix_unitaire_ttc', '18.00')
            ->assertNotified('Prix des services convertis');
    }

    /**
     * Rouvert après que son client est devenu particulier, le brouillon
     * d'une prestation autoliquidée repasse en vente en France, et la page
     * le dit avant qu'il ne s'enregistre ainsi.
     */
    public function test_un_brouillon_rouvert_dans_une_nature_qui_n_est_plus_permise_repasse_en_vente_en_france(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), $client, [['service_id' => Service::factory()->create()->id, 'prix_unitaire_ttc' => '500.00', 'quantite' => 1]], ['nature_operation' => NatureOperation::PrestationUeAutoliquidation]);
        $client->update(['type_client' => TypeClient::Particulier, 'numero_tva' => null]);

        Livewire::test(EditFacture::class, ['record' => $brouillon->getRouteKey()])
            ->assertSet('data.nature_operation', NatureOperation::VenteEnFrance->value)
            ->assertNotified('« Prestation à un professionnel de l\'UE (autoliquidation) » n\'est plus possible');
    }

    /**
     * Trouvé valide à 8 h puis inconnu de VIES à 9 h : « Émettre » ne
     * retient pas la vérification de 8 h, il interroge de nouveau VIES, et
     * émet avec la nouvelle vérification valide.
     */
    public function test_une_verification_vies_invalide_plus_recente_fait_verifier_de_nouveau_avant_d_emettre(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $client = Client::factory()->etranger('DE', 'DE123456789')->create();
        ConsultationVies::forceCreate(['client_id' => $client->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'identifiant_consultation' => 'WAPIAAAAW0000001', 'consultee_le' => now()->setTime(8, 0), 'reponse' => '{"valid":true}']);
        ConsultationVies::forceCreate(['client_id' => $client->id, 'numero_tva' => 'DE123456789', 'valide' => false, 'identifiant_consultation' => null, 'consultee_le' => now()->setTime(9, 0), 'reponse' => '{"valid":false}']);
        $brouillon = $this->brouillonDeLivraisonIntracommunautaire($client);
        Http::preventStrayRequests();
        Http::fake(['https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' => Http::response(['countryCode' => 'DE', 'vatNumber' => '123456789', 'valid' => true, 'requestIdentifier' => 'WAPIAAAAW1234567', 'name' => 'MUSTER GMBH', 'address' => '---'])]);

        Livewire::test(ListFactures::class)->callTableAction('emettre', $brouillon);

        $facture = $brouillon->fresh();
        $this->assertSame(StatutFacture::Emise, $facture->statut);
        $this->assertSame('WAPIAAAAW1234567', $facture->consultationVies->identifiant_consultation);
        Http::assertSentCount(1);
    }

    /**
     * La fiche d'un export à un professionnel montre, comme son PDF, le
     * prix TTC du catalogue et la TVA retirée à côté du taux de 0 %, jamais
     * un « PU TTC » que le client n'a pas payé.
     */
    public function test_la_fiche_d_un_export_montre_le_prix_catalogue_et_la_tva_retiree_sans_pu_ttc(): void
    {
        $this->magasinQuiVendHorsDeFrance();
        $produit = Produit::factory()->create(['prix_vente_ttc' => '7.00', 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $this->clientAmericain(), [['produit_id' => $produit->id, 'quantite' => 3]], [
            'nature_operation' => NatureOperation::ExportHorsUe, 'livraison_adresse' => '1 Main Street', 'livraison_code_postal' => '10001', 'livraison_ville' => 'New York', 'livraison_pays' => 'US',
        ]));

        Livewire::test(ViewFacture::class, ['record' => $facture->getRouteKey()])
            ->assertSeeText('Prix catalogue TTC')
            ->assertSeeText('TVA retirée')
            ->assertSeeText('HT de chaque taux = TTC du catalogue ÷ (1 + taux)')
            ->assertDontSeeText('PU TTC');
    }
}
