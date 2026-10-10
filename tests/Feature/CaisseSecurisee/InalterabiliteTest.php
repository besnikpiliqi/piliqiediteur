<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Exceptions\DonneeFiscaleInalterableException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Art. 286 I-3° bis du CGI : une donnée fiscale validée ne se modifie ni
 * ne se supprime. Une facture émise ne change plus que de statut de
 * règlement ; un avoir émis, les lignes des documents émis, les paiements
 * et les imputations ne changent plus du tout. Le modèle le refuse (voir
 * ProtegeLesDonneesValidees), la base aussi pour les tables où l'on ne
 * fait qu'ajouter, et les clés étrangères refusent de supprimer une fiche
 * qu'un document référence.
 */
class InalterabiliteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    private function produit(): Produit
    {
        return Produit::factory()->create(['prix_vente_ttc' => 20, 'taux_tva' => 20, 'suivi_stock' => false]);
    }

    /**
     * @param  array<string, mixed>  $attributs
     */
    private function emettre(Client $client, int $quantite = 3, array $attributs = []): Facture
    {
        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [['produit_id' => $this->produit()->id, 'quantite' => $quantite]], $attributs));
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneEnBase(string $table, int $id): array
    {
        return (array) DB::table($table)->where('id', $id)->first();
    }

    /**
     * @return array<string, array{0: Closure(): array<string, mixed>, 1: string}>
     */
    public static function modificationsDUneFactureEmise(): array
    {
        return [
            'total' => [static fn (): array => ['total_ttc' => '1.00'], 'total_ttc'],
            'client' => [static fn (): array => ['client_id' => Client::factory()->create()->id], 'client_id'],
            'numéro' => [static fn (): array => ['numero' => 'TK-LYON1-2026-999999'], 'numero'],
            'date d\'émission' => [static fn (): array => ['date_emission' => CarbonImmutable::parse('2020-01-01 00:00:00', 'UTC')], 'date_emission'],
            'notes' => [static fn (): array => ['notes' => 'Réécrite après coup'], 'notes'],
        ];
    }

    /**
     * @param  Closure(): array<string, mixed>  $modification
     */
    #[DataProvider('modificationsDUneFactureEmise')]
    public function test_une_facture_emise_ne_se_modifie_plus(Closure $modification, string $colonne): void
    {
        $facture = $this->emettre(Client::factory()->create());
        $avant = $this->ligneEnBase('factures', $facture->id);
        $attributs = $modification();

        $this->assertThrows(
            fn () => $facture->forceFill($attributs)->save(),
            DonneeFiscaleInalterableException::class,
            "Une facture ou un ticket émis ne se modifie plus : seul son statut de règlement évolue, et une correction passe par un avoir. Modification refusée : {$colonne}.",
        );

        $this->assertSame($avant, $this->ligneEnBase('factures', $facture->id));
    }

    /**
     * La garde lit la base, pas la copie en mémoire : une fiche chargée
     * quand la facture était encore un brouillon ne la réécrit pas une fois
     * émise.
     */
    public function test_une_copie_chargee_avant_l_emission_ne_reecrit_pas_la_facture_emise(): void
    {
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $copieDuBrouillon = Facture::query()->findOrFail($brouillon->id);
        app(EmettreFactureAction::class)->handle($brouillon);

        $this->assertThrows(fn () => $copieDuBrouillon->forceFill(['notes' => 'Réécrite après coup'])->save(), DonneeFiscaleInalterableException::class, 'Modification refusée : notes.');

        $this->assertNull($brouillon->fresh()->notes);
    }

    public function test_une_facture_emise_ne_se_supprime_pas(): void
    {
        $facture = $this->emettre(Client::factory()->create());

        $this->assertThrows(fn () => $facture->delete(), DonneeFiscaleInalterableException::class, 'Une facture ou un ticket émis ne se modifie plus : seul son statut de règlement évolue, et une correction passe par un avoir. Suppression refusée.');

        $this->assertModelExists($facture);
    }

    /**
     * @return array<string, array{0: Closure(Facture): mixed, 1: string}>
     */
    public static function ecrituresSurLesLignesDUneFactureEmise(): array
    {
        return [
            'modification' => [static fn (Facture $facture): bool => $facture->lignes->sole()->forceFill(['quantite' => 30, 'montant_ttc' => '600.00'])->save(), 'Modification refusée : quantite, montant_ttc.'],
            'suppression' => [static fn (Facture $facture): ?bool => $facture->lignes->sole()->delete(), 'Suppression refusée.'],
            'ajout' => [static fn (Facture $facture): Model => $facture->lignes()->create([
                'produit_id' => $facture->lignes->sole()->produit_id,
                'nature' => NatureLigne::Bien,
                'description' => 'Article ajouté après coup',
                'quantite' => 1,
                'prix_unitaire_ht' => '16.67',
                'prix_unitaire_ttc' => '20.00',
                'taux_tva' => '20.00',
                'montant_ht' => '16.67',
                'montant_tva' => '3.33',
                'montant_ttc' => '20.00',
                'ordre' => 1,
            ]), 'Ajout refusé.'],
        ];
    }

    /**
     * @param  Closure(Facture): mixed  $ecriture
     */
    #[DataProvider('ecrituresSurLesLignesDUneFactureEmise')]
    public function test_les_lignes_d_une_facture_emise_ne_se_modifient_ni_ne_se_suppriment_ni_ne_s_ajoutent(Closure $ecriture, string $refus): void
    {
        $facture = $this->emettre(Client::factory()->create());
        $avant = DB::table('lignes_facture')->get()->all();

        $this->assertThrows(fn () => $ecriture($facture), DonneeFiscaleInalterableException::class, "Les lignes d'une facture ou d'un ticket émis ne se modifient plus : une correction passe par un avoir. {$refus}");

        $this->assertEquals($avant, DB::table('lignes_facture')->get()->all());
    }

    /**
     * @return array<string, array{0: Closure(Avoir): mixed, 1: string}>
     */
    public static function ecrituresSurUnAvoirEmis(): array
    {
        return [
            'modification de l\'avoir' => [static fn (Avoir $avoir): bool => $avoir->forceFill(['motif' => 'Réécrit après coup'])->save(), 'Un avoir émis ne se modifie plus. Modification refusée : motif.'],
            'suppression de l\'avoir' => [static fn (Avoir $avoir): ?bool => $avoir->delete(), 'Un avoir émis ne se modifie plus. Suppression refusée.'],
            'modification d\'une ligne' => [static fn (Avoir $avoir): bool => $avoir->lignes->sole()->forceFill(['montant_ttc' => '1.00'])->save(), 'Les lignes d\'un avoir émis ne se modifient plus. Modification refusée : montant_ttc.'],
            'suppression d\'une ligne' => [static fn (Avoir $avoir): ?bool => $avoir->lignes->sole()->delete(), 'Les lignes d\'un avoir émis ne se modifient plus. Suppression refusée.'],
        ];
    }

    /**
     * @param  Closure(Avoir): mixed  $ecriture
     */
    #[DataProvider('ecrituresSurUnAvoirEmis')]
    public function test_un_avoir_emis_et_ses_lignes_ne_se_modifient_ni_ne_se_suppriment(Closure $ecriture, string $refus): void
    {
        $client = Client::factory()->create();
        $facture = $this->emettre($client);
        $avoir = app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]], 'Article abîmé');
        $avant = [DB::table('avoirs')->get()->all(), DB::table('lignes_avoir')->get()->all()];

        $this->assertThrows(fn () => $ecriture($avoir), DonneeFiscaleInalterableException::class, $refus);

        $this->assertEquals($avant, [DB::table('avoirs')->get()->all(), DB::table('lignes_avoir')->get()->all()]);
    }

    /**
     * Le statut de règlement d'une facture émise suit encore ses paiements
     * et son échéance : partiellement payée, en retard (par la commande
     * planifiée, en masse), puis payée.
     */
    public function test_le_statut_de_reglement_d_une_facture_emise_evolue_encore(): void
    {
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettre($client, 3, ['date_echeance' => today()->subDay()->toDateString()]);
        $payer = fn (string $montant): Paiement => app(EnregistrerPaiementAction::class)->handle(
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => $montant, 'recu_le' => today()->toDateString()],
            [['facture_id' => $facture->id, 'montant' => $montant]],
        );

        $payer('20.00');
        $partiellementPayee = $facture->fresh()->statut;
        $this->artisan('app:marquer-factures-en-retard')->assertSuccessful();
        $enRetard = $facture->fresh()->statut;
        $payer('40.00');

        $this->assertSame([StatutFacture::PartiellementPayee, StatutFacture::EnRetard, StatutFacture::Payee], [$partiellementPayee, $enRetard, $facture->fresh()->statut]);
    }

    /**
     * Un brouillon, sans numéro ni existence fiscale, se modifie puis se
     * supprime librement : rien n'entre dans la chaîne des ventes, seul
     * l'abandon de la vente s'inscrit au journal des événements (après
     * l'initialisation du registre).
     */
    public function test_un_brouillon_se_modifie_et_se_supprime(): void
    {
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $this->produit()->id, 'quantite' => 1]]);
        $autreClient = Client::factory()->create();

        $modifie = app(ModifierFactureAction::class)->handle($brouillon, $autreClient, [['produit_id' => $this->produit()->id, 'quantite' => 4]], ['notes' => 'Quatre articles']);
        $modifie->delete();

        $this->assertSame([$autreClient->id, 'Quatre articles', 4], [$modifie->client_id, $modifie->notes, $modifie->lignes->sole()->quantite]);
        $this->assertModelMissing($brouillon);
        $this->assertDatabaseCount('lignes_facture', 0);
        $this->assertSame(
            [['evenement', '260'], ['evenement', '320']],
            EnregistrementFiscal::query()->orderBy('id')->get()->map(fn (EnregistrementFiscal $enregistrement): array => [$enregistrement->chaine->value, $enregistrement->type])->all(),
        );
    }

    /**
     * Une facture de 60 € réglée par un paiement de 20 € et corrigée par
     * un avoir de 20 € imputé sur elle : un paiement, une imputation de
     * paiement, une imputation d'avoir.
     */
    private function reglerEtCorrigerUneFacture(): void
    {
        $client = Client::factory()->professionnel()->create();
        $facture = $this->emettre($client);
        app(EnregistrerPaiementAction::class)->handle(
            $client,
            ['mode_paiement' => ModePaiement::Virement->value, 'montant' => '20.00', 'recu_le' => today()->toDateString()],
            [['facture_id' => $facture->id, 'montant' => '20.00']],
        );
        app(EnregistrerAvoirAction::class)->handle($client, [['ligne_facture_id' => $facture->lignes->sole()->id, 'quantite' => 1]]);
    }

    /**
     * @return array<string, array{0: class-string<Model>, 1: string, 2: string, 3: string}>
     */
    public static function donneesEnAjoutSeul(): array
    {
        return [
            'paiement' => [Paiement::class, 'paiements', 'montant', 'Un paiement enregistré ne se modifie ni ne se supprime.'],
            'imputation d\'un paiement' => [PaiementAllocation::class, 'paiement_allocations', 'montant_alloue', 'Ce qu\'un paiement règle d\'une facture ne se modifie ni ne se supprime.'],
            'imputation d\'un avoir' => [AvoirAllocation::class, 'avoir_allocations', 'montant_alloue', 'Ce qu\'un avoir crédite d\'une facture ne se modifie ni ne se supprime.'],
        ];
    }

    /**
     * @param  class-string<Model>  $modele
     */
    #[DataProvider('donneesEnAjoutSeul')]
    public function test_le_modele_refuse_de_modifier_ou_de_supprimer_un_paiement_ou_une_imputation(string $modele, string $table, string $montant, string $regle): void
    {
        $this->reglerEtCorrigerUneFacture();
        $donnee = $modele::query()->sole();
        $avant = $this->ligneEnBase($table, $donnee->id);

        $this->assertThrows(fn () => $donnee->forceFill([$montant => '1.00'])->save(), DonneeFiscaleInalterableException::class, "{$regle} Modification refusée : {$montant}.");
        $this->assertThrows(fn () => $modele::query()->sole()->delete(), DonneeFiscaleInalterableException::class, "{$regle} Suppression refusée.");

        $this->assertSame($avant, $this->ligneEnBase($table, $donnee->id));
    }

    /**
     * @return array<string, array{0: class-string<Model>, 1: string, 2: string}>
     */
    public static function tablesEnAjoutSeul(): array
    {
        return array_map(static fn (array $donnees): array => array_slice($donnees, 0, 3), self::donneesEnAjoutSeul());
    }

    /**
     * @param  class-string<Model>  $modele
     */
    #[DataProvider('tablesEnAjoutSeul')]
    public function test_la_base_refuse_de_modifier_ou_de_supprimer_un_paiement_ou_une_imputation(string $modele, string $table, string $montant): void
    {
        $this->reglerEtCorrigerUneFacture();
        $id = $modele::query()->sole()->id;
        $avant = $this->ligneEnBase($table, $id);

        $this->assertThrows(fn () => DB::table($table)->where('id', $id)->update([$montant => '1.00']), QueryException::class, "{$table} : table en ajout seul, modification refusée");
        $this->assertThrows(fn () => DB::table($table)->where('id', $id)->delete(), QueryException::class, "{$table} : table en ajout seul, suppression refusée");

        $this->assertSame($avant, $this->ligneEnBase($table, $id));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tablesDesDonneesFiscales(): array
    {
        return array_combine($tables = [
            'factures',
            'lignes_facture',
            'avoirs',
            'lignes_avoir',
            'paiements',
            'paiement_allocations',
            'avoir_allocations',
            'sequences_documents',
            'soumissions_ereporting',
            'transmissions_pdp',
            'tetes_registre_fiscal',
            'enregistrements_fiscaux',
        ], array_map(static fn (string $table): array => [$table], $tables));
    }

    /**
     * Aucune donnée fiscale ne disparaît avec son magasin : la clé
     * magasin_id de chaque table refuse la suppression du magasin au lieu
     * de la propager. Elle se lit dans le schéma, car supprimer un magasin
     * qui a émis une facture échoue de toute façon par plusieurs clés à la
     * fois (registre, séquences, clients) et par les déclencheurs ajout
     * seul : l'échec ne dirait pas que chacune de ces clés refuse.
     */
    #[DataProvider('tablesDesDonneesFiscales')]
    public function test_la_cle_magasin_d_une_donnee_fiscale_refuse_la_suppression_du_magasin(string $table): void
    {
        $cle = collect(Schema::getForeignKeys($table))->sole(static fn (array $cle): bool => $cle['columns'] === ['magasin_id']);

        $this->assertSame(['magasins', ['id'], 'restrict'], [$cle['foreign_table'], $cle['foreign_columns'], $cle['on_delete']]);
    }

    /**
     * @return array<string, array{0: Closure(Facture): Model}>
     */
    public static function fichesReferencees(): array
    {
        return [
            'magasin' => [static fn (Facture $facture): Model => $facture->magasin],
            'auteur de la facture' => [static fn (Facture $facture): Model => $facture->creePar],
            'produit d\'une ligne' => [static fn (Facture $facture): Model => $facture->lignes->firstWhere('produit_id', '!=', null)->produit],
            'service d\'une ligne' => [static fn (Facture $facture): Model => $facture->lignes->firstWhere('service_id', '!=', null)->service],
        ];
    }

    /**
     * @param  Closure(Facture): Model  $fiche
     */
    #[DataProvider('fichesReferencees')]
    public function test_une_fiche_que_reference_une_facture_emise_ne_se_supprime_pas_definitivement(Closure $fiche): void
    {
        $this->actingAs(User::factory()->create());
        $service = Service::factory()->create();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [
            ['produit_id' => $this->produit()->id, 'quantite' => 1],
            ['service_id' => $service->id, 'quantite' => 1, 'prix_unitaire_ttc' => 30, 'taux_tva' => 20],
        ]);
        // Émise sans opérateur connecté : seul cree_par référence l'auteur.
        auth()->forgetUser();
        $facture = app(EmettreFactureAction::class)->handle($brouillon);
        $referencee = $fiche($facture);

        // La base refuse d'elle-même, quel que soit le code qui le tente : la
        // requête passe outre les garde-fous des modèles (un magasin, lui,
        // ne se supprime jamais définitivement, voir le déclencheur de sa
        // table).
        $this->assertThrows(fn () => $referencee->newQueryWithoutScopes()->whereKey($referencee->getKey())->delete(), QueryException::class);

        $this->assertModelExists($referencee);
        $this->assertSame($facture->numero, $facture->fresh()->numero);
    }
}
