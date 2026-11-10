<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un administrateur à qui le propriétaire a coché la vente émet un ticket
 * au moment même où il accepte la propriété que le propriétaire lui
 * transmet, chacun dans son processus, sur une vraie base PostgreSQL. La
 * vente verrouille le registre de son magasin puis la ligne de son auteur ;
 * l'acceptation verrouille les registres de tous les magasins puis les
 * comptes (voir JournalDesDroits) : l'une passe avant l'autre, jamais
 * pendant. Soit le
 * ticket passe avant, signé par un administrateur ; soit il est refusé
 * après, son auteur relu sous verrou étant devenu propriétaire. Jamais un
 * enregistrement de vente au nom d'un propriétaire. Une vente écrite
 * directement en base pendant la transmission, sans l'application,
 * attend de même la transmission et se refuse ensuite : la garde de la
 * vente verrouille la ligne de son auteur (voir GardesDeLaVente).
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
        [$transmisAvant, $administrateurAvant, $produitAvant] = $this->magasinDUnAdministrateurQuiVend('Transmis avant');
        $jeton = $this->demanderLaTransmission($this->proprietaireDe(Magasin::query()->findOrFail($transmisAvant)), User::query()->findOrFail($administrateurAvant), self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($transmisAvant, $administrateurAvant, $produitAvant): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($transmisAvant));
            self::vendreAuComptant(User::query()->findOrFail($administrateurAvant), Client::factory()->create(), [['produit_id' => $produitAvant, 'quantite' => 1]], ModePaiement::Especes);
        }, $concurrente);

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($administrateurAvant), $jeton, self::MOT_DE_PASSE_CHOISI);

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $refus = $concurrente->wait();
        $this->assertFalse($refus->successful(), 'La vente, passée après la transmission, aurait dû être refusée.');
        $this->assertStringContainsString(LecteurDesDroits::REFUS, $refus->errorOutput());
        $this->assertSame(RoleSociete::Proprietaire, User::query()->findOrFail($administrateurAvant)->role_societe);
        $this->assertSame(0, Facture::withoutGlobalScope(MagasinScope::class)->where('magasin_id', $transmisAvant)->count());

        [$venduAvant, $administrateurApres, $produitApres] = $this->magasinDUnAdministrateurQuiVend('Vendu avant');
        $jetonApres = $this->demanderLaTransmission($this->proprietaireDe(Magasin::query()->findOrFail($venduAvant)), User::query()->findOrFail($administrateurApres), self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $transmission = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($administrateurApres, $jetonApres): void {
            app(AccepterLaTransmissionDeLaProprieteAction::class)->handle(User::query()->findOrFail($administrateurApres), $jetonApres, self::MOT_DE_PASSE_CHOISI);
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
     * Une vente écrite directement en base, sans l'application, au nom de
     * cet auteur : une facture, un paiement ou l'enregistrement signé d'un
     * ticket, et la colonne de son auteur.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ventesEcritesDirectement(): array
    {
        return [
            'facture' => ['factures', 'cree_par'],
            'paiement' => ['paiements', 'cree_par'],
            'enregistrement signé d\'un ticket' => ['enregistrements_fiscaux', 'operateur_id'],
        ];
    }

    /**
     * Le test transmet par la base la propriété de la société à un
     * administrateur qui vend, comme l'acceptation de la transmission : il
     * verrouille les comptes, fait l'ancien propriétaire administrateur,
     * puis l'administrateur propriétaire. Pendant ce temps, un autre
     * processus écrit directement en base, sans l'application qui
     * verrouillerait d'abord le registre et le compte, une vente au nom de
     * cet administrateur. La garde de la vente verrouille la ligne de son
     * auteur avant de lire son rôle (voir GardesDeLaVente) : l'écriture
     * attend la transmission, puis la base la refuse, son auteur étant
     * devenu propriétaire. Sans ce verrou, elle lirait le rôle d'avant la
     * transmission et passerait pendant elle.
     */
    #[DataProvider('ventesEcritesDirectement')]
    #[Group('concurrence')]
    public function test_une_vente_ecrite_en_base_pendant_la_transmission_a_son_auteur_l_attend_puis_est_refusee(string $table, string $auteur): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        [$magasinId, $administrateurId] = $this->magasinDUnAdministrateurQuiVend('Ma Boutique');
        $magasin = Magasin::query()->findOrFail($magasinId);
        $proprietaireId = (int) $this->proprietaireDe($magasin)->id;
        $clientId = app(TenantContext::class)->runAs($magasin, fn (): int => Client::factory()->create()->id);
        $instant = now()->toDateTimeString();
        $vente = match ($table) {
            'factures' => ['magasin_id' => $magasinId, 'client_id' => $clientId, 'type_client' => TypeClient::Particulier->value, 'cree_par' => $administrateurId, 'created_at' => $instant, 'updated_at' => $instant],
            'paiements' => ['magasin_id' => $magasinId, 'client_id' => $clientId, 'mode_paiement' => ModePaiement::Especes->value, 'montant' => '12.00', 'recu_le' => '2026-10-09', 'cree_par' => $administrateurId, 'created_at' => $instant, 'updated_at' => $instant],
            'enregistrements_fiscaux' => ['magasin_id' => $magasinId, 'chaine' => ChaineFiscale::Vente->value, 'sequence' => 1, 'type' => 'ticket', 'operateur_id' => $administrateurId, 'horodatage' => $instant, 'contenu' => '{}', 'signature' => 'signature', 'algorithme' => 'ES256', 'cle_id' => str_repeat('0', 64), 'version_module' => '1.5', 'created_at' => $instant],
        };
        $concurrente = null;
        $this->concurrencerDesQueLaLigneEstVerrouillee('users', 'for update', static function () use ($table, $vente): void {
            DB::table($table)->insert($vente);
        }, $concurrente);

        DB::transaction(function () use ($proprietaireId, $administrateurId): void {
            DB::table('users')->whereIn('id', [$proprietaireId, $administrateurId])->orderBy('id')->lockForUpdate()->get();
            DB::table('affectations')->where('user_id', $administrateurId)->delete();
            DB::table('autorisations_societe')->where('user_id', $administrateurId)->delete();
            DB::table('users')->where('id', $proprietaireId)->update(['role_societe' => RoleSociete::Administrateur->value]);
            DB::table('users')->where('id', $administrateurId)->update(['role_societe' => RoleSociete::Proprietaire->value, 'email_verified_at' => now()]);
        });

        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $refus = $concurrente->wait();
        $this->assertFalse($refus->successful(), 'La vente, écrite pendant la transmission, aurait dû l\'attendre puis être refusée.');
        $this->assertStringContainsString(GardesDeLaVente::MESSAGE, $refus->errorOutput());
        $this->assertSame(RoleSociete::Proprietaire, User::query()->findOrFail($administrateurId)->role_societe);
        $this->assertSame(0, DB::table($table)->where($auteur, $administrateurId)->count());
    }

    /**
     * Une société validée, son magasin de ce nom et un administrateur de
     * tous ses magasins, à qui le propriétaire a coché toutes les
     * autorisations, vente comprise, qui remplit les conditions de la
     * transmission de la propriété (voir administrateurPretAReprendre()) ;
     * un produit de 12,00 € sans suivi de stock. Leurs identifiants, pour
     * l'autre processus.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function magasinDUnAdministrateurQuiVend(string $nom): array
    {
        $magasin = $this->ouvrirUneSociete(['nom' => $nom]);
        $administrateur = $this->administrateurPretAReprendre($magasin, "repreneur-{$magasin->id}@example.test", PermissionRegistry::toutes());
        $produit = app(TenantContext::class)->runAs($magasin, fn (): int => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id);

        return [(int) $magasin->id, (int) $administrateur->id, $produit];
    }
}
