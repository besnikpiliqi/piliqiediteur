<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * La fiche d'un produit enregistrée depuis sa page « Produits » pendant
 * qu'une facture qui le vend s'émet, ou qu'une facture d'achat qui le
 * reçoit se valide, chacune dans son processus, sur une vraie base
 * PostgreSQL. La fiche verrouille le registre fiscal seulement quand son
 * taux de TVA change, puis la ligne du magasin sous un verrou partagé,
 * son auteur et le produit (voir ModifierUneFicheAction) ; l'émission
 * verrouille le registre, puis le magasin, son auteur, la facture et ses
 * produits (voir EmettreFactureAction) ; la validation d'un achat, le
 * magasin, son auteur, la facture d'achat, son fournisseur et ses
 * produits (voir ValiderFactureAchatAction). Dans les deux ordres, le
 * second attend le premier, sans interblocage, et tous deux passent.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class FicheProduitConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * L'autre geste, la correction de la fiche (son nom, ou son taux de
     * TVA, qui passe par le registre), et qui passe le premier.
     *
     * @return array<string, array{0: string, 1: array<string, string>, 2: bool}>
     */
    public static function gestesEtOrdres(): array
    {
        $cas = [];

        foreach (['émettre une facture qui le vend' => 'emettre', 'valider une facture d\'achat qui le reçoit' => 'recevoir'] as $libelle => $geste) {
            foreach (['son nom' => ['nom' => 'Coque bleue'], 'son taux de TVA' => ['taux_tva' => '5.5']] as $correction => $saisie) {
                $cas["{$libelle}, {$correction}, la fiche d'abord"] = [$geste, $saisie, true];
                $cas["{$libelle}, {$correction}, l'autre geste d'abord"] = [$geste, $saisie, false];
            }
        }

        return $cas;
    }

    /**
     * @param  array<string, string>  $saisie
     */
    #[DataProvider('gestesEtOrdres')]
    #[Group('concurrence')]
    public function test_une_fiche_produit_enregistree_pendant_une_vente_ou_un_achat_passe_avant_ou_apres_sans_interblocage(string $geste, array $saisie, bool $laFicheDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo('2026-10-05 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['nom' => 'Coque rouge', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $documentId = $geste === 'emettre'
            ? app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]])->id
            : app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00']])->id;
        $magasinId = $magasin->id;
        $produitId = $produit->id;
        $ficheLue = FicheLue::de($produit)->valeurs();
        $enregistrer = static function () use ($magasinId, $produitId, $ficheLue, $saisie): void {
            $magasin = self::leMagasin($magasinId);
            app(ModifierUneFicheAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), Produit::query()->findOrFail($produitId), $ficheLue, $saisie);
        };
        $autre = static function () use ($geste, $magasinId, $documentId): void {
            $responsable = self::responsableDeTest(self::leMagasin($magasinId));

            $geste === 'emettre'
                ? app(EmettreFactureAction::class)->handle($responsable, Facture::query()->findOrFail($documentId))
                : app(ValiderFactureAchatAction::class)->handle($responsable, FactureAchat::query()->findOrFail($documentId));
        };
        $concurrente = null;
        $this->croiser($laFicheDAbord, $geste === 'emettre' && array_key_exists('taux_tva', $saisie), $enregistrer, $autre, $concurrente);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame($saisie === ['nom' => 'Coque bleue'] ? ['Coque bleue', '20.00'] : ['Coque rouge', '5.50'], array_values(Produit::query()->findOrFail($produitId)->only(['nom', 'taux_tva'])));
        $this->assertTrue($geste === 'emettre' ? Facture::query()->findOrFail($documentId)->numero !== null : FactureAchat::query()->findOrFail($documentId)->statut === StatutFactureAchat::Validee);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * La fiche et l'autre geste au même instant, dans cet ordre : le
     * second démarre dès que le premier tient le verrou qu'il attendra.
     * C'est le registre quand les deux le prennent, un changement de taux
     * et une émission ; sinon le produit.
     *
     * @param  Closure(): void  $enregistrer
     * @param  Closure(): void  $autre
     */
    private function croiser(bool $laFicheDAbord, bool $surLeRegistre, Closure $enregistrer, Closure $autre, ?InvokedProcess &$concurrente): void
    {
        $second = $laFicheDAbord ? $autre : $enregistrer;

        $surLeRegistre
            ? $this->concurrencerDesQueLeRegistreEstVerrouille($second, $concurrente)
            : $this->concurrencerDesQueLaLigneEstVerrouillee('produits', 'for update', $second, $concurrente);

        $laFicheDAbord ? $enregistrer() : $autre();
    }

    private static function leMagasin(int $magasinId): Magasin
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }
}
