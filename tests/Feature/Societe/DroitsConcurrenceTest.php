<?php

namespace Tests\Feature\Societe;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Services\VerificateurIntegrite;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Services\VerificateurJournalAudit;
use App\Models\User;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Deux modifications des droits simultanées, chacune dans son processus,
 * sur une vraie base PostgreSQL (voir JournalDesDroits) : la seconde
 * attend les verrous des registres et des comptes que tient la première,
 * puis relit l'auteur et le compte, et applique les règles à ce qu'elle
 * relit. Aucun droit retiré n'est jamais donné ensuite, et le journal des
 * événements reste cohérent.
 *
 * DatabaseMigrations, pas RefreshDatabase : l'autre processus ne voit que
 * ce que le test a validé.
 */
class DroitsConcurrenceTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Deux responsables du même magasin changent au même instant les cases
     * du même vendeur : la seconde modification attend la première, puis
     * part de ce qu'elle a écrit, si bien que le journal des événements
     * enchaîne les droits sans trou.
     */
    public function test_deux_responsables_modifiant_le_meme_vendeur_se_suivent_et_le_jet_130_reste_coherent(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->responsable($magasin);
        $second = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLIENT_VIEW]);
        [$secondId, $vendeurId] = [$second->id, $vendeur->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($secondId, $vendeurId): void {
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($secondId), User::query()->findOrFail($vendeurId), RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VIEW]);
        }, $concurrente);

        app(ModifierMembreAction::class)->handle($premier, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $this->attendreLaReussite($concurrente);
        $siens = array_values(array_filter($this->modificationsDesDroits($magasin), fn (array $details): bool => $details['membre']['id'] === $vendeur->id));
        $this->assertSame([
            [null, [PermissionRegistry::CLIENT_VIEW]],
            [[PermissionRegistry::CLIENT_VIEW], [PermissionRegistry::FACTURE_VIEW]],
            [[PermissionRegistry::FACTURE_VIEW], [PermissionRegistry::PRODUIT_VIEW]],
        ], array_map(fn (array $details): array => [$details['avant']['permissions'] ?? null, $details['apres']['permissions']], $siens));
        $this->assertSame([PermissionRegistry::PRODUIT_VIEW], Affectation::query()->where('user_id', $vendeur->id)->sole()->autorisations());
    }

    /**
     * Deux créations au même instant avec le même jeton d'écran, pour deux
     * demandes différentes : la seconde attend la première sur le
     * registre du magasin, relit le jeton qui a déjà servi pour une autre
     * demande, et est refusée sans rien révéler ; un seul compte existe.
     */
    public function test_deux_creations_simultanees_du_meme_jeton_pour_deux_demandes_n_en_retiennent_qu_une(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $jeton = (string) Str::uuid();
        [$proprietaireId, $magasinId] = [$proprietaire->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($proprietaireId, $magasinId, $jeton): void {
            $magasin = Magasin::query()->findOrFail($magasinId);
            app(TenantContext::class)->runAs($magasin, fn () => app(CreerMembreAction::class)->handle(User::query()->findOrFail($proprietaireId), $magasin, ['name' => 'Copie'], RoleMagasin::Observateur, null, $jeton));
        }, $concurrente);

        app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur, null, $jeton);

        $resultat = $this->attendreLEchec($concurrente);
        $this->assertStringContainsString(CreationDesComptes::MESSAGE_DEMANDE_DEJA_SERVIE, $resultat);
        $this->assertSame(['Paul'], User::query()->where('societe_id', $magasin->societe_id)->whereIn('name', ['Paul', 'Copie'])->pluck('name')->all());
    }

    /**
     * Le propriétaire rétrograde un responsable pendant que celui-ci coche
     * toutes les cases d'un vendeur : la modification du responsable attend
     * la rétrogradation, relit son auteur, et est refusée.
     */
    public function test_un_retrait_des_droits_d_un_responsable_et_sa_modification_d_un_vendeur_simultanes_ne_donnent_jamais_un_droit_retire(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLIENT_VIEW]);
        [$responsableId, $vendeurId] = [$responsable->id, $vendeur->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($responsableId, $vendeurId): void {
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($responsableId), User::query()->findOrFail($vendeurId), RoleMagasin::Vendeur, PermissionRegistry::toutes());
        }, $concurrente);

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $responsable, RoleMagasin::Vendeur);

        $this->assertStringContainsString(PorteeDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame([PermissionRegistry::CLIENT_VIEW], Affectation::query()->where('user_id', $vendeur->id)->sole()->autorisations());
    }

    /**
     * Le propriétaire retire à un administrateur « nommer et retirer les
     * responsables » pendant que celui-ci nomme un responsable : la
     * nomination attend, relit l'administrateur, et est refusée.
     */
    public function test_un_retrait_de_l_autorisation_de_nommer_et_une_nomination_simultanees_ne_nomment_jamais_apres_le_retrait(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        [$administrateurId, $vendeurId] = [$administrateur->id, $vendeur->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($administrateurId, $vendeurId): void {
            app(ModifierMembreAction::class)->handle(User::query()->findOrFail($administrateurId), User::query()->findOrFail($vendeurId), RoleMagasin::Responsable);
        }, $concurrente);

        app(ModifierAdministrateurAction::class)->modifier($this->proprietaireDe($magasin), $administrateur, null, PermissionRegistry::modeleDeLAdministrateur(), []);

        $this->assertStringContainsString(PorteeDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
        $this->assertSame([], $administrateur->refresh()->autorisationsDeSociete());
    }

    /**
     * Une donnée par geste de vente : le geste, et l'autorisation que le
     * propriétaire retire au même instant.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function gestesDeVente(): array
    {
        return [
            'émission' => ['émission', PermissionRegistry::FACTURE_EMETTRE],
            'paiement' => ['paiement', PermissionRegistry::PAIEMENT_CREER],
            'avoir' => ['avoir', PermissionRegistry::AVOIR_CREER],
            'contre-passation' => ['contre-passation', PermissionRegistry::PAIEMENT_CONTRE_PASSER],
            'clôture manuelle' => ['clôture manuelle', PermissionRegistry::CLOTURE_CAISSE_GERER],
            'caisse' => ['caisse', PermissionRegistry::FACTURE_EMETTRE],
        ];
    }

    /**
     * Le propriétaire retire une autorisation au vendeur pendant que
     * celui-ci fait le geste qu'elle permet, dans un autre processus : le
     * geste attend le registre que tient le retrait, relit le vendeur sous
     * ce verrou, et est refusé ; rien n'est écrit.
     */
    #[DataProvider('gestesDeVente')]
    public function test_un_retrait_d_autorisation_et_une_emission_simultanes_dans_le_meme_magasin_ne_laissent_jamais_passer_une_vente_non_autorisee(string $geste, string $autorisation): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes());
        [$documentId, $compter] = $this->preparerLeGeste($geste, $vendeur);
        $avant = $compter();
        [$vendeurId, $magasinId] = [$vendeur->id, $magasin->id];
        $concurrente = null;
        $this->concurrencerDesQueLeRegistreEstVerrouille(static function () use ($geste, $vendeurId, $magasinId, $documentId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            self::faireLeGeste($geste, User::query()->findOrFail($vendeurId), $documentId);
        }, $concurrente);

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, RoleMagasin::Vendeur, array_values(array_diff(PermissionRegistry::toutes(), [$autorisation])));

        $this->assertStringContainsString(LecteurDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame($avant, $compter());
    }

    /**
     * Le propriétaire retire « valider les factures d'achat » pendant que
     * le membre valide une facture d'achat : la validation attend la ligne
     * du membre que tient le retrait, le relit, et est refusée ; aucune
     * entrée en stock.
     */
    public function test_un_retrait_de_valider_et_une_validation_d_achat_simultanes_ne_valident_jamais_apres_le_retrait(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $acheteur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER, PermissionRegistry::FACTURE_ACHAT_VALIDER]);
        $produit = Produit::factory()->create(['suivi_stock' => true]);
        $factureAchat = app(CreerFactureAchatAction::class)->handle($acheteur, Fournisseur::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 20]]);
        [$acheteurId, $magasinId, $factureAchatId] = [$acheteur->id, $magasin->id, $factureAchat->id];
        $concurrente = null;
        $this->concurrencerDesQueLesComptesSontVerrouilles(static function () use ($acheteurId, $magasinId, $factureAchatId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($magasinId));
            app(ValiderFactureAchatAction::class)->handle(User::query()->findOrFail($acheteurId), FactureAchat::query()->findOrFail($factureAchatId));
        }, $concurrente);

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $acheteur, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]);

        $this->assertStringContainsString(LecteurDesDroits::REFUS, $this->attendreLEchec($concurrente));
        $this->assertSame([StatutFactureAchat::Brouillon, null, 0], [$factureAchat->fresh()->statut, $factureAchat->fresh()->numero, $produit->fresh()->stock_quantite]);
    }

    /**
     * Deux ventes sans stock confirmées au même instant, dans deux magasins
     * de la même société, chacune dans son processus (R1) : la vente du
     * magasin B va jusqu'au bout pendant que celle du magasin A tient encore
     * tous ses verrous, la tête de la chaîne d'audit de son magasin, prise
     * en dernier, comprise. Une vente ne verrouille jamais la ligne de la
     * société, et l'audit de chacune s'inscrit dans la chaîne de son
     * magasin : deux magasins ne s'attendent jamais, et chaque chaîne reste
     * intacte.
     */
    public function test_deux_ventes_sans_stock_dans_deux_magasins_ne_se_bloquent_pas_sur_la_societe(): void
    {
        $this->exigerUnServeurDeBaseDeDonnees();
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris']);
        $cableDeParis = self::cableSansStock();
        app(TenantContext::class)->set($lyon);
        $cableDeLyon = self::cableSansStock();
        $responsableDeLyon = $this->responsable($lyon);
        [$parisId, $responsableDeParisId, $cableDeParisId] = [$paris->id, $this->responsable($paris)->id, $cableDeParis->id];
        app(TenantContext::class)->set($lyon);
        $concurrente = null;
        $termineeSansAttendre = false;
        $this->concurrencerDesQueLAuditDuMagasinEstVerrouille(static function () use ($parisId, $responsableDeParisId, $cableDeParisId): void {
            app(TenantContext::class)->set(Magasin::query()->findOrFail($parisId));
            self::vendreSansStock(User::query()->findOrFail($responsableDeParisId), $cableDeParisId);
        }, $concurrente, $termineeSansAttendre);

        self::vendreSansStock($responsableDeLyon, $cableDeLyon->id);

        $this->assertTrue($termineeSansAttendre, 'La vente de Paris a attendu un verrou que tenait la vente de Lyon.');
        $this->attendreLaReussite($concurrente);
        $this->assertEqualsCanonicalizing([(int) $lyon->id, (int) $paris->id], array_map(fn (EntreeAudit $entree): int => (int) $entree->magasin_id, $this->entreesDAudit($lyon->societe, ActionAuditee::VenteSansStock)));
        $this->assertSame([], app(VerificateurJournalAudit::class)->verifier($lyon->societe));

        foreach ([$lyon, $paris] as $magasin) {
            app(TenantContext::class)->set($magasin);
            $this->assertSame([-1, 1], [Produit::query()->sole()->stock_quantite, Facture::query()->whereNotNull('numero')->count()]);
            $this->assertSame([], app(VerificateurIntegrite::class)->verifier($magasin)->anomalies());
        }
    }

    /**
     * Un câble du magasin actif, suivi en stock, qui n'en a plus.
     */
    private static function cableSansStock(): Produit
    {
        return Produit::factory()->create(['nom' => 'Câble USB', 'reference' => 'CABLE-USB', 'prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);
    }

    /**
     * Le câble vendu au comptoir du magasin actif, après l'avertissement
     * confirmé d'un stock qui passe à -1.
     */
    private static function vendreSansStock(User $vendeur, int $produitId): Facture
    {
        return self::vendreAuComptant(
            $vendeur,
            Client::query()->where('est_comptoir', true)->sole(),
            [['produit_id' => $produitId, 'quantite' => 1]],
            ModePaiement::Especes,
            confirmation: new ConfirmationVenteSansStock([$produitId => -1]),
        );
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test tient la
     * tête de la chaîne d'audit de son magasin, son dernier verrou, puis la
     * laisse aller jusqu'au bout, vingt secondes au plus, avant que le test
     * ne poursuive : $termineeSansAttendre dit si elle a fini pendant que
     * le test tenait encore tous ses verrous.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLAuditDuMagasinEstVerrouille(Closure $operationConcurrente, ?InvokedProcess &$concurrente, bool &$termineeSansAttendre): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        $verrouTenu = false;

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, &$verrouTenu, &$termineeSansAttendre, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente !== null || $requete->connectionName !== $connexionDuTest) {
                return;
            }

            if (str_contains($requete->sql, '"tetes_journal_audit"')) {
                $verrouTenu = $verrouTenu || str_ends_with($requete->sql, 'for update');

                return;
            }

            if ($verrouTenu) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $limite = microtime(true) + 20;

                while ($concurrente->running() && microtime(true) < $limite) {
                    usleep(20_000);
                }

                $termineeSansAttendre = ! $concurrente->running();
            }
        });
    }

    /**
     * Ce que le vendeur a fait avant le geste, validé en base : l'identifiant
     * du document que le geste reprend, et ce qui compte ses effets.
     *
     * @return array{0: int|null, 1: Closure(): int}
     */
    private function preparerLeGeste(string $geste, User $vendeur): array
    {
        $ticket = fn (): Facture => app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), self::lignes()));

        return match ($geste) {
            'émission' => [app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), self::lignes())->id, fn (): int => Facture::query()->whereNotNull('numero')->count()],
            'paiement' => [$ticket()->id, fn (): int => Paiement::query()->count()],
            'avoir' => [$ticket()->id, fn (): int => Avoir::query()->count()],
            'contre-passation' => [self::payer($vendeur, $ticket())->id, fn (): int => Paiement::query()->count()],
            'clôture manuelle' => [$ticket()->id, fn (): int => ClotureCaisse::query()->count()],
            'caisse' => [null, fn (): int => Facture::query()->count()],
        };
    }

    /**
     * Le geste, dans le processus concurrent, sur le document préparé.
     */
    private static function faireLeGeste(string $geste, User $vendeur, ?int $documentId): void
    {
        match ($geste) {
            'émission' => app(EmettreFactureAction::class)->handle($vendeur, Facture::query()->findOrFail($documentId)),
            'paiement' => self::payer($vendeur, Facture::query()->findOrFail($documentId)),
            'avoir' => app(EnregistrerAvoirAction::class)->handle($vendeur, Facture::query()->findOrFail($documentId)->client, [['ligne_facture_id' => Facture::query()->findOrFail($documentId)->lignes->sole()->id, 'quantite' => 1]], 'Retour'),
            'contre-passation' => app(ContrePasserPaiementAction::class)->handle($vendeur, Paiement::query()->findOrFail($documentId), 'Erreur de saisie'),
            'clôture manuelle' => app(CloturerCaisseAction::class)->handle($vendeur, app(TenantContext::class)->magasin(), today()),
            'caisse' => self::vendreAuComptant($vendeur, Client::query()->where('est_comptoir', true)->sole(), self::lignes(), ModePaiement::Especes),
        };
    }

    /**
     * Une ligne de 12,00 € TTC d'un nouveau produit sans suivi de stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private static function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }

    private static function payer(User $auteur, Facture $facture): Paiement
    {
        return app(EnregistrerPaiementAction::class)->handle($auteur, $facture->client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => $facture->total_ttc, 'recu_le' => today()->toDateString()], [['facture_id' => $facture->id, 'montant' => $facture->total_ttc]]);
    }

    /**
     * Démarre l'opération concurrente dès que l'opération du test tient les
     * lignes des comptes qu'elle modifie (voir JournalDesDroits), et
     * attend qu'elle attende l'une d'elles.
     *
     * @param  Closure(): void  $operationConcurrente
     */
    private function concurrencerDesQueLesComptesSontVerrouilles(Closure $operationConcurrente, ?InvokedProcess &$concurrente): void
    {
        $connexionDuTest = DB::getDefaultConnection();
        $verrouTenu = false;

        DB::listen(function (QueryExecuted $requete) use (&$concurrente, &$verrouTenu, $operationConcurrente, $connexionDuTest): void {
            if ($concurrente !== null || $requete->connectionName !== $connexionDuTest) {
                return;
            }

            if (str_contains($requete->sql, 'from "users"') && str_ends_with($requete->sql, 'for update')) {
                $verrouTenu = true;

                return;
            }

            if ($verrouTenu) {
                $concurrente = $this->demarrerUnProcessusConcurrent($operationConcurrente);
                $this->attendreQueLeProcessusAttendeUnVerrou($concurrente);
            }
        });
    }

    private function attendreLaReussite(?InvokedProcess $concurrente): void
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertTrue($resultat->successful(), $resultat->errorOutput());
    }

    /**
     * Le processus concurrent a échoué : son message d'erreur.
     */
    private function attendreLEchec(?InvokedProcess $concurrente): string
    {
        $this->assertInstanceOf(InvokedProcess::class, $concurrente);
        $resultat = $concurrente->wait();
        $this->assertFalse($resultat->successful(), 'L\'opération concurrente aurait dû être refusée.');

        return $resultat->errorOutput();
    }
}
