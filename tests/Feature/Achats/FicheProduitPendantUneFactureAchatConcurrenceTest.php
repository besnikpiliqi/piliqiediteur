<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use Filament\Facades\Filament;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La fiche d'un produit modifiée dans « Produits » pendant que la page de
 * création d'une facture d'achat qui l'achète s'enregistre, chacune dans
 * son processus, sur une vraie base PostgreSQL. La ligne d'achat a lu la
 * fiche quand le produit y a été choisi : elle n'écrit que ce que son
 * auteur y a corrigé (voir FicheLue), sur la fiche relue sous le verrou
 * de ses produits. Une modification validée avant ce verrou reste, sans
 * faire refuser la facture ; une modification qui arrive pendant qu'elle
 * tient la fiche attend sa fin, et les deux corrections restent.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FicheProduitPendantUneFactureAchatConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Qui enregistre la facture, ce que le collègue change sur la fiche, et
     * la fiche qui en reste : nom, prix de vente TTC, taux de TVA, suivi du
     * stock.
     *
     * @return array<string, array{0: bool, 1: array<string, mixed>, 2: list<mixed>}>
     */
    public static function modificationsDuCollegue(): array
    {
        return [
            'le nom, le prix de vente et le suivi du stock' => [true, ['nom' => 'Coque iPhone 15 Pro', 'prix_vente_ttc' => '18', 'suivi_stock' => false], ['Coque iPhone 15 Pro', '18.00', '20.00', false]],
            'le prix de vente, la facture saisie sans droit sur les produits' => [false, ['prix_vente_ttc' => '18'], ['Coque iPhone 15', '18.00', '20.00', true]],
            'le taux de TVA' => [true, ['taux_tva' => '5.5'], ['Coque iPhone 15', '15.00', '5.50', true]],
        ];
    }

    /**
     * Le collègue enregistre sa modification pendant que la facture
     * s'enregistre, après qu'elle a relu son magasin et avant qu'elle ne
     * verrouille ses produits : la facture se crée, et la fiche garde ce
     * que le collègue y a mis, taux compris, inscrit une seule fois au
     * journal des événements.
     *
     * @param  array<string, mixed>  $modification
     * @param  list<mixed>  $ficheAttendue
     */
    #[DataProvider('modificationsDuCollegue')]
    #[Group('concurrence')]
    public function test_une_fiche_modifiee_pendant_l_enregistrement_garde_la_modification_du_collegue(bool $avecLeDroitSurLesProduits, array $modification, array $ficheAttendue): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$magasin, $produit] = $this->laBoutiqueEtSaCoque($avecLeDroitSurLesProduits);
        $page = $this->factureDAchatQuiAchete($produit);
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $collegue = null;
        DB::listen(function (QueryExecuted $requete) use (&$collegue, $magasinId, $produitId, $modification): void {
            if ($collegue === null && str_contains($requete->sql, 'from "magasins"') && str_ends_with($requete->sql, 'for share')) {
                $collegue = $this->demarrerUnProcessusConcurrent(static fn () => self::modifierLaFiche($magasinId, $produitId, $modification))->wait();
            }
        });

        $page->call('create')->assertHasNoFormErrors();

        $this->assertInstanceOf(ProcessResult::class, $collegue);
        $this->assertTrue($collegue->successful(), $collegue->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame([$produitId], FactureAchat::query()->sole()->lignes->pluck('produit_id')->all());
        $this->assertSame($ficheAttendue, array_values(Produit::query()->findOrFail($produitId)->only(['nom', 'prix_vente_ttc', 'taux_tva', 'suivi_stock'])));
        $this->assertSame(array_key_exists('taux_tva', $modification) ? [['20.00', '5.50']] : [], $this->changementsDeTauxJournalises());
    }

    /**
     * Le collègue enregistre un nouveau prix de vente pendant que la
     * facture tient la fiche, dont sa ligne corrige le nom : il attend la
     * fin de la facture, puis passe ; les deux corrections restent.
     */
    #[Group('concurrence')]
    public function test_une_modification_qui_attend_la_facture_garde_les_deux_corrections(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        [$magasin, $produit] = $this->laBoutiqueEtSaCoque(true);
        $page = $this->factureDAchatQuiAchete($produit, ['nom' => 'Coque iPhone 15 noire']);
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $collegue = null;
        $enAttente = null;
        DB::listen(function (QueryExecuted $requete) use (&$collegue, &$enAttente, $magasinId, $produitId): void {
            if ($collegue === null && str_contains($requete->sql, 'from "produits"') && str_ends_with($requete->sql, 'for update')) {
                $collegue = $this->demarrerUnProcessusConcurrent(static fn () => self::modifierLaFiche($magasinId, $produitId, ['prix_vente_ttc' => '18']));
                $this->attendreQueLeProcessusAttendeUnVerrou($collegue);
                $enAttente = $collegue->running();
            }
        });

        $page->call('create')->assertHasNoFormErrors();

        $this->assertInstanceOf(InvokedProcess::class, $collegue);
        $this->assertTrue($enAttente, 'La modification du collègue n\'a pas attendu la fin de la facture.');
        $resultat = $collegue->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(['Coque iPhone 15 noire', '18.00'], array_values(Produit::query()->findOrFail($produitId)->only(['nom', 'prix_vente_ttc'])));
    }

    /**
     * La boutique et sa « Coque iPhone 15 » à 15,00 € TTC, à 20 %, suivie
     * en stock ; la personne qui saisit la facture, connectée : le
     * propriétaire, ou un observateur à qui il a confié la saisie des
     * achats sans droit sur les produits. Le responsable de test, le
     * collègue, est obtenu avant tout processus concurrent.
     *
     * @return array{0: Magasin, 1: Produit}
     */
    private function laBoutiqueEtSaCoque(bool $avecLeDroitSurLesProduits): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        self::responsableDeTest($magasin);
        $this->actingAs($avecLeDroitSurLesProduits
            ? $this->proprietaireDe($magasin)
            : $this->membre($magasin, RoleMagasin::Observateur, [...RoleMagasin::Observateur->permissionsParDefaut(), PermissionRegistry::FACTURE_ACHAT_CREER], ['name' => 'Léa']));
        Filament::setTenant($magasin);

        return [$magasin, Produit::factory()->create(['nom' => 'Coque iPhone 15', 'prix_vente_ttc' => 15, 'taux_tva' => 20, 'suivi_stock' => true])];
    }

    /**
     * La page de création d'une facture d'achat, avec une ligne qui achète
     * 4 de ce produit, choisi dans le catalogue, puis corrigé comme
     * l'indique $corrections.
     *
     * @param  array<string, mixed>  $corrections
     */
    private function factureDAchatQuiAchete(Produit $produit, array $corrections = []): Testable
    {
        return Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['produit_id' => $produit->id])
            ->setFormComponentActionData([...$corrections, 'quantite' => 4])
            ->callMountedFormComponentAction()
            ->assertHasNoFormComponentActionErrors();
    }

    /**
     * Le collègue, le responsable de test, enregistre la fiche depuis sa
     * page « Produits », ouverte sur la fiche telle qu'elle est en base.
     *
     * @param  array<string, mixed>  $modification
     */
    private static function modifierLaFiche(int $magasinId, int $produitId, array $modification): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $produit = Produit::query()->findOrFail($produitId);

        app(ModifierUneFicheAction::class)->handle(self::responsableDeTest($magasin), $produit, FicheLue::de($produit)->valeurs(), $modification);
    }

    /**
     * Les changements de taux de TVA inscrits au journal des événements
     * du magasin (JET 270), chacun [ancienne valeur, nouvelle valeur].
     *
     * @return list<array{0: string, 1: string}>
     */
    private function changementsDeTauxJournalises(): array
    {
        return EnregistrementFiscal::query()
            ->where('type', (string) CodeEvenement::ParametreDeConformite->value)
            ->orderBy('sequence')
            ->get()
            ->map(fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->filter(fn (array $details): bool => $details['parametre'] === 'taux_tva')
            ->map(fn (array $details): array => [$details['ancienne_valeur'], $details['nouvelle_valeur']])
            ->values()
            ->all();
    }
}
