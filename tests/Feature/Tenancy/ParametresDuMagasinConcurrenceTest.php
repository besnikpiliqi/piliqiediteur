<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\ModifierLesParametresDuMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Les paramètres du magasin réglés par le propriétaire pendant qu'une
 * facture s'émet ou qu'une facture d'achat se valide, chacun dans son
 * processus, sur une vraie base PostgreSQL. Le réglage verrouille la
 * ligne du magasin « for no key update », puis son auteur (voir
 * ModifierLesParametresDuMagasinAction) ; l'émission verrouille le
 * registre fiscal, puis relit la ligne du magasin sous un verrou partagé,
 * qui exclut celui du réglage, puis son auteur et la facture (voir
 * EmettreFactureAction) ; la validation d'un achat relit la ligne du
 * magasin sous un verrou partagé, puis son auteur, la facture d'achat,
 * son fournisseur et ses produits (voir ValiderFactureAchatAction). Dans
 * les deux ordres, le second attend le premier, sans interblocage, et
 * tous deux passent.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ParametresDuMagasinConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * L'autre geste, et qui passe le premier.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function gestesEtOrdres(): array
    {
        return [
            'émettre une facture, les paramètres d\'abord' => ['emettre', true],
            'émettre une facture, l\'émission d\'abord' => ['emettre', false],
            'valider une facture d\'achat, les paramètres d\'abord' => ['recevoir', true],
            'valider une facture d\'achat, la validation d\'abord' => ['recevoir', false],
        ];
    }

    #[DataProvider('gestesEtOrdres')]
    #[Group('concurrence')]
    public function test_les_parametres_regles_pendant_une_vente_ou_un_achat_passent_avant_ou_apres_sans_interblocage(string $geste, bool $lesParametresDAbord): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = self::responsableDeTest($magasin);
        $produit = Produit::factory()->create(['nom' => 'Coque rouge', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $documentId = $geste === 'emettre'
            ? app(CreerFactureAction::class)->handle($responsable, Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]])->id
            : app(CreerFactureAchatAction::class)->handle($responsable, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => '2.00']])->id;
        $magasinId = $magasin->id;
        $parametresLus = FicheLue::de($magasin->fresh())->valeurs();
        $regler = static function () use ($magasinId, $parametresLus): void {
            $magasin = self::leMagasin($magasinId);
            app(ModifierLesParametresDuMagasinAction::class)->handle(User::query()->whereKey($magasin->societe->proprietaire()->value('id'))->firstOrFail(), PermissionRegistry::PARAMETRES_GERER, $parametresLus, ['delai_paiement_jours' => 45]);
        };
        $autre = static function () use ($geste, $magasinId, $documentId): void {
            $responsable = self::responsableDeTest(self::leMagasin($magasinId));

            $geste === 'emettre'
                ? app(EmettreFactureAction::class)->handle($responsable, Facture::query()->findOrFail($documentId))
                : app(ValiderFactureAchatAction::class)->handle($responsable, FactureAchat::query()->findOrFail($documentId));
        };
        $concurrente = null;
        $lesParametresDAbord
            ? $this->concurrencerDesQueLaLigneEstVerrouillee('magasins', 'for no key update', $autre, $concurrente)
            : $this->concurrencerDesQueLaLigneEstVerrouillee('magasins', 'for share', $regler, $concurrente);

        $lesParametresDAbord ? $regler() : $autre();

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        app(TenantContext::class)->set($magasin);
        $this->assertSame(45, $magasin->fresh()->delai_paiement_jours);
        $this->assertTrue($geste === 'emettre' ? Facture::query()->findOrFail($documentId)->numero !== null : FactureAchat::query()->findOrFail($documentId)->statut === StatutFactureAchat::Validee);
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    private static function leMagasin(int $magasinId): Magasin
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }
}
