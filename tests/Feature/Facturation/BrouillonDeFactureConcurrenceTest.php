<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Actions\SupprimerFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Deux gestes sur le même brouillon de facture au même instant, chacun
 * dans son processus, sur une vraie base PostgreSQL : la
 * suppression, la modification et l'émission verrouillent d'abord le
 * registre fiscal du magasin, et le second geste attend le premier, puis
 * relit le brouillon sous son verrou. Un brouillon déjà supprimé ne se
 * supprime pas une seconde fois, ni ne s'émet, et l'abandon de la vente
 * ne s'inscrit qu'une fois au journal des événements ; un brouillon déjà
 * émis ne se supprime ni ne se modifie plus ; une modification passée
 * avant l'émission est celle que la facture émise porte.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class BrouillonDeFactureConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Le geste du test, celui de l'autre processus, qui passe après lui, et
     * le refus que ce second geste reçoit, ou null s'il passe.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function gestes(): array
    {
        return [
            'deux suppressions' => ['supprimer', 'supprimer', Facture::REFUS_INTROUVABLE],
            'l\'émission avant la suppression' => ['emettre', 'supprimer', Facture::REFUS_SUPPRESSION],
            'la suppression avant l\'émission' => ['supprimer', 'emettre', Facture::REFUS_INTROUVABLE],
            'l\'émission avant la modification' => ['emettre', 'modifier', 'Seule une facture brouillon peut être modifiée.'],
            'la modification avant l\'émission' => ['modifier', 'emettre', null],
        ];
    }

    #[DataProvider('gestes')]
    #[Group('concurrence')]
    public function test_deux_gestes_simultanes_sur_un_brouillon_de_facture_passent_l_un_apres_l_autre(string $premier, string $second, ?string $refus): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12.5, 'taux_tva' => 20, 'suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 2]]);
        $magasinId = $magasin->id;
        $factureId = $brouillon->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($second, $magasinId, $factureId, $produitId): void {
            self::faire($second, $magasinId, $factureId, $produitId);
        }, $concurrente);

        self::faire($premier, $magasinId, $factureId, $produitId);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertSame($refus !== null, $resultat->failed(), $resultat->errorOutput());

        if ($refus !== null) {
            $this->assertStringContainsString($refus, $resultat->errorOutput());
        }

        app(TenantContext::class)->set($magasin);
        $supprime = $premier === 'supprimer';
        $facture = Facture::query()->find($factureId);
        $this->assertSame($supprime ? 1 : 0, $this->nombreDAbandons());

        if ($supprime) {
            $this->assertNull($facture);
        } else {
            $this->assertSame(StatutFacture::Emise, $facture->statut);
            $this->assertSame($premier === 'modifier' ? 1 : 2, $facture->lignes()->sole()->quantite);
        }

        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Le geste du responsable de test du magasin, obtenu par le test avant
     * de lancer l'autre processus (voir auteur()), dans le processus du
     * test ou dans le sien : il supprime le brouillon, le modifie (une
     * coque au lieu de deux) ou l'émet.
     */
    private static function faire(string $geste, int $magasinId, int $factureId, int $produitId): void
    {
        $magasin = Magasin::query()->findOrFail($magasinId);
        app(TenantContext::class)->set($magasin);
        $auteur = self::responsableDeTest($magasin);
        $brouillon = Facture::query()->findOrFail($factureId);

        match ($geste) {
            'supprimer' => app(SupprimerFactureAction::class)->handle($auteur, $brouillon),
            'modifier' => app(ModifierFactureAction::class)->handle($auteur, $brouillon, $brouillon->client, [['produit_id' => $produitId, 'quantite' => 1]]),
            'emettre' => app(EmettreFactureAction::class)->handle($auteur, $brouillon),
        };
    }

    /**
     * Le nombre de ventes abandonnées (événement 320) d'un brouillon au
     * journal des événements du magasin actif.
     */
    private function nombreDAbandons(): int
    {
        return EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->where('type', '320')->get()
            ->filter(fn (EnregistrementFiscal $evenement): bool => ($evenement->contenuSigne()['donnees']['details']['origine'] ?? null) === 'brouillon')
            ->count();
    }
}
