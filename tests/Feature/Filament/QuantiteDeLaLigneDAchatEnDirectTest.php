<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Bug rapporté par l'éditeur : dans la fenêtre d'une ligne de facture
 * d'achat, taper ou changer la quantité (flèches comprises) ne recalculait
 * rien en temps réel (le HT proposé de la ligne, son détail), alors que les
 * autres champs de la ligne le font. La quantité n'était recalculée qu'en
 * quittant le champ (live(onBlur)), et seulement dans « Ajouter un
 * produit », jamais dans « Modifier la ligne ». Elle est maintenant
 * calculée en direct partout, le champ lui-même n'étant jamais réécrit
 * pendant la frappe (modificateur « unintrusive », voir saisieEnDirect()).
 */
class QuantiteDeLaLigneDAchatEnDirectTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La quantité de la fenêtre « Ajouter un produit » est liée au serveur à
     * chaque frappe, sans être réécrite pendant la frappe.
     */
    public function test_la_quantite_de_la_fenetre_d_ajout_se_recalcule_pendant_la_frappe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);
        $produit = Produit::factory()->create(['nom' => 'Coque iPhone 15', 'prix_vente_ttc' => 15, 'taux_tva' => 20, 'suivi_stock' => true]);

        $html = Livewire::test(CreateFactureAchat::class)
            ->fillForm(['fournisseur_id' => Fournisseur::factory()->create()->id, 'date_facture' => '2026-01-15'])
            ->mountFormComponentAction('ajouterUnProduitAction', 'ajouterUnProduit')
            ->setFormComponentActionData(['produit_id' => $produit->id])
            ->html();

        $this->assertMatchesRegularExpression(
            '/wire:model\.live\.unintrusive="[^"]*\.quantite"/',
            $html,
            'La quantité doit se recalculer à chaque frappe, sans être réécrite pendant la frappe.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/wire:model\.(?:live\.)?blur="[^"]*\.quantite"/',
            $html,
            'La quantité ne doit pas attendre la sortie du champ pour se recalculer.',
        );
    }

    /**
     * Les deux fenêtres, « Ajouter un produit » et « Modifier la ligne »,
     * partent du même formulaire de ligne : sa quantité est calculée en
     * direct dans l'une comme dans l'autre.
     *
     * @return array<string, array{0: bool}>
     */
    public static function lesDeuxFenetres(): array
    {
        return [
            'Ajouter un produit' => [true],
            'Modifier la ligne' => [false],
        ];
    }

    #[DataProvider('lesDeuxFenetres')]
    public function test_la_quantite_du_formulaire_de_ligne_est_calculee_en_direct_dans_les_deux_fenetres(bool $ajout): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->proprietaireDe($magasin));
        Filament::setTenant($magasin);

        $formulaire = (new ReflectionMethod(FactureAchatResource::class, 'formulaireDeLigne'))->invoke(null, $ajout);
        $quantite = $this->composant('quantite', is_array($formulaire) ? $formulaire : []);

        $this->assertInstanceOf(TextInput::class, $quantite);
        $this->assertTrue($quantite->isLive(), 'La quantité doit être liée au serveur.');
        $this->assertFalse($quantite->isLiveOnBlur(), 'La quantité ne doit pas attendre la sortie du champ.');
    }

    /**
     * Le composant de ce nom dans un formulaire de sections.
     *
     * @param  array<array-key, mixed>  $composants
     */
    private function composant(string $nom, array $composants): ?Component
    {
        foreach ($composants as $composant) {
            if (! $composant instanceof Component) {
                continue;
            }

            if (method_exists($composant, 'getName') && $composant->getName() === $nom) {
                return $composant;
            }

            $trouve = $this->composant($nom, $composant->getChildComponents());

            if ($trouve !== null) {
                return $trouve;
            }
        }

        return null;
    }
}
