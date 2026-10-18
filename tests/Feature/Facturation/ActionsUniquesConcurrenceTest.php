<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Tenancy\Models\ActionTraitee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deux demandes simultanées sous le même jeton (R3), chacune dans son
 * processus, sur une vraie base PostgreSQL : un double clic sur
 * « Encaisser », ou un encaissement renvoyé pendant que le premier est
 * encore en cours. La seconde attend le verrou du registre fiscal que
 * tient la première, puis trouve le jeton qu'elle a inscrit : elle rend
 * le même ticket, sans second numéro, second paiement ni seconde
 * signature.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ActionsUniquesConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_deux_demandes_simultanees_avec_le_meme_jeton_ne_font_qu_un_effet(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $produit = Produit::factory()->create(['nom' => 'Câble USB', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);
        $jeton = (string) Str::uuid();
        $magasinId = $magasin->id;
        $proprietaireId = $proprietaire->id;
        $produitId = $produit->id;
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($magasinId, $proprietaireId, $produitId, $jeton): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            fwrite(STDOUT, (string) self::vendreUnCable(User::query()->findOrFail($proprietaireId), $produitId, $jeton)->id);
        }, $concurrente);

        $ticket = self::vendreUnCable($proprietaire, $produitId, $jeton);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame((string) $ticket->id, $resultat->output());
        $this->assertSame([1, 1, 1], [Facture::query()->count(), Paiement::query()->count(), ActionTraitee::query()->count()]);
        $this->assertSame(1, EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Vente->value)->count());
        $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
    }

    /**
     * Un câble vendu au comptoir, payé en espèces, sous ce jeton.
     */
    private static function vendreUnCable(User $vendeur, int $produitId, string $jeton): Facture
    {
        return self::vendreAuComptant(
            $vendeur,
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            jeton: $jeton,
        );
    }
}
