<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\TransfererProprieteAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un administrateur à qui le propriétaire a coché la vente émet un ticket
 * au moment même où le propriétaire lui transmet la propriété, chacun dans
 * son processus, sur une vraie base PostgreSQL. La vente verrouille le
 * registre de son magasin puis la ligne de son auteur ; la transmission
 * verrouille les registres de tous les magasins puis les comptes (voir
 * JournalDesDroits) : l'une passe avant l'autre, jamais pendant. Soit le
 * ticket passe avant, signé par un administrateur ; soit il est refusé
 * après, son auteur relu sous verrou étant devenu propriétaire. Jamais un
 * enregistrement de vente au nom d'un propriétaire.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class ProprietaireSansVenteConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Le mot de passe que la fabrique donne au propriétaire.
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    #[Group('concurrence')]
    public function test_une_vente_et_la_transmission_de_la_propriete_au_vendeur_simultanees_ne_signent_jamais_une_vente_du_proprietaire(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();

        [$transmisAvant, $administrateurAvant, $produitAvant] = $this->magasinDUnAdministrateurQuiVend('Transmis avant');
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($transmisAvant, $administrateurAvant, $produitAvant): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($transmisAvant));
            self::vendreAuComptant(User::query()->findOrFail($administrateurAvant), Client::factory()->create(), [['produit_id' => $produitAvant, 'quantite' => 1]], ModePaiement::Especes);
        }, $concurrente);

        app(TransfererProprieteAction::class)->handle($this->proprietaireDe(Magasin::query()->findOrFail($transmisAvant)), User::query()->findOrFail($administrateurAvant), self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $refus = $concurrente->wait();
        $this->assertFalse($refus->successful(), 'La vente, passée après la transmission, aurait dû être refusée.');
        $this->assertStringContainsString(LecteurDesDroits::REFUS, $refus->errorOutput());
        $this->assertSame(RoleSociete::Proprietaire, User::query()->findOrFail($administrateurAvant)->role_societe);
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $transmisAvant)->count());

        [$venduAvant, $administrateurApres, $produitApres] = $this->magasinDUnAdministrateurQuiVend('Vendu avant');
        $proprietaireId = $this->proprietaireDe(Magasin::query()->findOrFail($venduAvant))->id;
        $transmission = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $administrateurApres): void {
            app(TransfererProprieteAction::class)->handle(User::query()->findOrFail($proprietaireId), User::query()->findOrFail($administrateurApres), self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        }, $transmission);
        app(TenantContext::class)->set(Magasin::query()->findOrFail($venduAvant));

        $ticket = self::vendreAuComptant(User::query()->findOrFail($administrateurApres), Client::factory()->create(), [['produit_id' => $produitApres, 'quantite' => 1]], ModePaiement::Especes);

        $this->assertInstanceOf(InvokedProcess::class, $transmission);
        $resultat = $transmission->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
        $this->assertSame(RoleSociete::Proprietaire, User::query()->findOrFail($administrateurApres)->role_societe);
        $vente = EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $venduAvant)->where('chaine', ChaineFiscale::Vente->value)->sole();
        $droits = EnregistrementFiscal::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $venduAvant)->where('type', (string) CodeEvenement::ModificationDesDroits->value)->orderByDesc('id')->firstOrFail();
        $this->assertSame([(int) $administrateurApres, (int) $administrateurApres], [(int) $ticket->cree_par, (int) $vente->operateur_id]);
        $this->assertLessThan($droits->id, $vente->id, 'La vente est signée avant que la transmission ne fasse de son auteur le propriétaire.');
    }

    /**
     * Une société validée, son magasin de ce nom et un administrateur de
     * tous ses magasins, à qui le propriétaire a coché toutes les
     * autorisations, vente comprise, et dont la qualité de représentant est
     * prouvée ; un produit de 12,00 € sans suivi de stock. Leurs
     * identifiants, pour l'autre processus.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function magasinDUnAdministrateurQuiVend(string $nom): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => $nom]);
        $administrateur = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();
        $produit = app(TenantContext::class)->runAs($magasin, fn (): int => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id);

        return [(int) $magasin->id, (int) $administrateur->id, $produit];
    }
}
