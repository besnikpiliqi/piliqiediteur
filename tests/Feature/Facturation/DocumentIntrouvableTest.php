<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\SupprimerDevisAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\EnvoiDocument;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ModifierInventaireOuvertureAction;
use App\Domain\Stock\Actions\SupprimerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un geste sur un document que l'écran a lu, mais qui n'est plus dans ce
 * magasin quand l'action le relit sous son verrou : un brouillon supprimé
 * par une autre requête pendant celle du geste, ou un document d'un autre
 * magasin de la société. L'action le refuse par une exception métier qui
 * le dit, que les boutons, les actions groupées et les pages affichent en
 * notification, jamais par une page introuvable, et rien ne s'écrit. Un
 * brouillon supprimé avant le geste n'arrive pas jusqu'à l'action : la
 * page ou la liste le dit (voir DocumentDisparuDeLEcranTest).
 */
class DocumentIntrouvableTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
    }

    /**
     * Le brouillon, créé puis supprimé par une autre requête ; le geste sur
     * la copie que l'écran avait lue ; le refus attendu.
     *
     * @return array<string, array{0: Closure(): Model, 1: Closure(Model): void, 2: Closure(Model): mixed, 3: string}>
     */
    public static function brouillonsSupprimes(): array
    {
        $produit = static fn (): Produit => Produit::factory()->create(['nom' => 'Coque', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = static fn (): Facture => app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit()->id, 'quantite' => 1]]);
        $devis = static fn (): Devis => app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit()->id, 'quantite' => 1]]);
        $inventaire = static fn (): InventaireOuverture => app(CreerInventaireOuvertureAction::class)->handle(self::auteur(), [self::carnet()]);
        $supprimerLaFacture = static fn (Model $brouillon) => app(SupprimerFactureAction::class)->handle(self::auteur(), $brouillon);
        $supprimerLeDevis = static fn (Model $brouillon) => app(SupprimerDevisAction::class)->handle(self::auteur(), $brouillon);
        $supprimerLInventaire = static fn (Model $brouillon) => app(SupprimerInventaireOuvertureAction::class)->handle(self::auteur(), $brouillon);

        return [
            'émettre un brouillon de facture' => [$facture, $supprimerLaFacture, static fn (Model $copie) => app(EmettreFactureAction::class)->handle(self::auteur(), $copie), Facture::REFUS_INTROUVABLE],
            'modifier un brouillon de facture' => [$facture, $supprimerLaFacture, static fn (Model $copie) => app(ModifierFactureAction::class)->handle(self::auteur(), $copie, $copie->client, [['produit_id' => $copie->lignes()->value('produit_id'), 'quantite' => 2]]), Facture::REFUS_INTROUVABLE],
            'numéroter un devis' => [$devis, $supprimerLeDevis, static fn (Model $copie) => app(EmettreDevisAction::class)->handle(self::auteur(), $copie), Devis::REFUS_INTROUVABLE],
            'modifier un devis' => [$devis, $supprimerLeDevis, static fn (Model $copie) => app(ModifierDevisAction::class)->handle(self::auteur(), $copie, $copie->client, [['produit_id' => $copie->lignes()->value('produit_id'), 'quantite' => 2]]), Devis::REFUS_INTROUVABLE],
            'supprimer un devis' => [$devis, $supprimerLeDevis, static fn (Model $copie) => app(SupprimerDevisAction::class)->handle(self::auteur(), $copie), Devis::REFUS_INTROUVABLE],
            'envoyer un devis' => [$devis, $supprimerLeDevis, static fn (Model $copie) => app(EnvoyerDocumentAction::class)->handle(self::auteur(), $copie, 'client@exemple.fr', null), EnvoyerDocumentAction::REFUS_INTROUVABLE],
            'modifier un inventaire d\'ouverture' => [$inventaire, $supprimerLInventaire, static fn (Model $copie) => app(ModifierInventaireOuvertureAction::class)->handle(self::auteur(), $copie, [self::carnet()]), InventaireOuverture::REFUS_INTROUVABLE],
            'supprimer un inventaire d\'ouverture' => [$inventaire, $supprimerLInventaire, static fn (Model $copie) => app(SupprimerInventaireOuvertureAction::class)->handle(self::auteur(), $copie), InventaireOuverture::REFUS_INTROUVABLE],
            'valider un inventaire d\'ouverture' => [$inventaire, $supprimerLInventaire, static fn (Model $copie) => app(ValiderInventaireOuvertureAction::class)->handle(self::auteur(), $copie), InventaireOuverture::REFUS_INTROUVABLE],
        ];
    }

    /**
     * @param  Closure(): Model  $creer
     * @param  Closure(Model): void  $supprimer
     * @param  Closure(Model): mixed  $geste
     */
    #[DataProvider('brouillonsSupprimes')]
    public function test_un_geste_sur_un_brouillon_supprime_entre_temps_le_dit_sans_rien_ecrire(Closure $creer, Closure $supprimer, Closure $geste, string $refus): void
    {
        $brouillon = $creer();
        $copie = $brouillon->newQuery()->findOrFail($brouillon->getKey());
        $supprimer($brouillon);

        $this->assertThrows(fn () => $geste($copie), LogicException::class, $refus);

        $this->assertFalse($copie->newQuery()->whereKey($copie->getKey())->exists());
        $this->assertSame([0, 0, 0], [EnvoiDocument::query()->count(), MouvementStock::query()->count(), Produit::query()->where('nom', 'Carnet A5')->count()]);
    }

    /**
     * Le devis d'un autre magasin de la société, que le magasin actif ne
     * voit pas, amené là où le geste s'appliquerait (brouillon, envoyé ou
     * accepté) ; le geste.
     *
     * @return array<string, array{0: Closure(Devis): Devis, 1: Closure(Devis): mixed}>
     */
    public static function devisDUnAutreMagasin(): array
    {
        $envoyer = static fn (Devis $devis): Devis => app(EmettreDevisAction::class)->handle(self::auteur(), $devis);

        return [
            'numéroter un brouillon' => [static fn (Devis $devis): Devis => $devis, static fn (Devis $devis) => app(EmettreDevisAction::class)->handle(self::auteur(), $devis)],
            'accepter un devis envoyé' => [$envoyer, static fn (Devis $devis) => app(AccepterDevisAction::class)->handle(self::auteur(), $devis)],
            'refuser un devis envoyé' => [$envoyer, static fn (Devis $devis) => app(RefuserDevisAction::class)->handle(self::auteur(), $devis)],
            'convertir un devis accepté' => [static fn (Devis $devis): Devis => app(AccepterDevisAction::class)->handle(self::auteur(), $envoyer($devis)), static fn (Devis $devis) => app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devis)],
        ];
    }

    /**
     * @param  Closure(Devis): Devis  $amener
     * @param  Closure(Devis): mixed  $geste
     */
    #[DataProvider('devisDUnAutreMagasin')]
    public function test_un_geste_sur_un_devis_d_un_autre_magasin_le_dit_sans_rien_ecrire(Closure $amener, Closure $geste): void
    {
        $contexte = app(TenantContext::class);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $devis = $amener(app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]))->refresh();
        $avant = $devis->getAttributes();
        $contexte->set($this->ouvrirUnAutreMagasin($this->magasin));

        $this->assertThrows(fn () => $geste($devis), LogicException::class, Devis::REFUS_INTROUVABLE);

        $contexte->set($this->magasin);
        $this->assertSame($avant, Devis::query()->findOrFail($devis->id)->getAttributes());
        $this->assertSame(0, Facture::query()->count());
    }

    /**
     * Une ligne d'inventaire d'ouverture : dix carnets.
     *
     * @return array<string, mixed>
     */
    private static function carnet(): array
    {
        return ['produit' => ['nom' => 'Carnet A5', 'reference' => 'CARNET-A5', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'];
    }
}
