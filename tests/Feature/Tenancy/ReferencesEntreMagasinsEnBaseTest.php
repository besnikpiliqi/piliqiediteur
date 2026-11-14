<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Models\TraceEvenement;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\EnvoyerDocumentAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\RepondreAuDevisEnLigneAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypePreuve;
use App\Domain\Facturation\Mail\DocumentMail;
use App\Domain\Facturation\Models\BordereauDetaxe;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Models\PreuveOperation;
use App\Domain\Facturation\Models\VisaDetaxe;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Stock\Actions\CreerInventaireOuvertureAction;
use App\Domain\Stock\Actions\ValiderInventaireOuvertureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * En base, une ligne d'un magasin ne désigne jamais une ligne d'un autre
 * magasin, même de la même société : toute référence d'une table qui a
 * magasin_id vers une autre table qui en a un est une clé composée
 * (magasin_id, colonne) → (magasin_id, id). La clé refuse ce que les
 * actions refusent déjà en relisant les fiches reçues (voir
 * BelongsToMagasin::relueDansLeMagasin()), quel que soit le code qui
 * écrit : tâche, commande, requête directe.
 *
 * La liste des références se lit dans le catalogue de PostgreSQL, jamais
 * dans une liste écrite ici : une table ou une référence ajoutée plus tard
 * y entre d'elle-même.
 */
class ReferencesEntreMagasinsEnBaseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque référence entre deux tables d'un magasin, telle que le
     * catalogue la décrit : nom de la contrainte, table et sa clé
     * primaire, colonnes, table visée et ses colonnes.
     *
     * @return list<array{nom: string, table: string, cle: string, colonnes: list<string>, cible: string, colonnes_cibles: list<string>}>
     */
    private function referencesEntreMagasins(): array
    {
        $references = DB::select(<<<'SQL'
            select c.conname as nom,
                   s.relname as table_source,
                   t.relname as table_cible,
                   (select a.attname from pg_index i join pg_attribute a on a.attrelid = i.indrelid and a.attnum = i.indkey[0]
                     where i.indrelid = c.conrelid and i.indisprimary) as cle_source,
                   (select string_agg(a.attname, ',' order by k.ordre) from unnest(c.conkey) with ordinality k(numero, ordre)
                      join pg_attribute a on a.attrelid = c.conrelid and a.attnum = k.numero) as colonnes,
                   (select string_agg(a.attname, ',' order by k.ordre) from unnest(c.confkey) with ordinality k(numero, ordre)
                      join pg_attribute a on a.attrelid = c.confrelid and a.attnum = k.numero) as colonnes_cibles
              from pg_constraint c
              join pg_class s on s.oid = c.conrelid
              join pg_class t on t.oid = c.confrelid
             where c.contype = 'f'
               and c.connamespace = 'public'::regnamespace
               and exists (select 1 from pg_attribute a where a.attrelid = c.conrelid and a.attname = 'magasin_id' and not a.attisdropped)
               and exists (select 1 from pg_attribute a where a.attrelid = c.confrelid and a.attname = 'magasin_id' and not a.attisdropped)
             order by s.relname, c.conname
            SQL);

        return array_map(static fn (object $reference): array => [
            'nom' => (string) $reference->nom,
            'table' => (string) $reference->table_source,
            'cle' => (string) $reference->cle_source,
            'colonnes' => explode(',', (string) $reference->colonnes),
            'cible' => (string) $reference->table_cible,
            'colonnes_cibles' => explode(',', (string) $reference->colonnes_cibles),
        ], $references);
    }

    /**
     * La colonne qui désigne la ligne visée, magasin_id mis à part.
     *
     * @param  array{nom: string, table: string, cle: string, colonnes: list<string>, cible: string, colonnes_cibles: list<string>}  $reference
     */
    private static function colonneDe(array $reference): string
    {
        $colonnes = array_values(array_diff($reference['colonnes'], ['magasin_id']));
        self::assertCount(1, $colonnes, "{$reference['nom']} : une seule colonne désigne la ligne visée.");

        return $colonnes[0];
    }

    public function test_toute_reference_entre_deux_tables_d_un_magasin_est_une_cle_composee(): void
    {
        $references = $this->referencesEntreMagasins();
        $nonComposees = [];

        foreach ($references as $reference) {
            $colonne = self::colonneDe($reference);

            if ([$reference['colonnes'], $reference['colonnes_cibles']] !== [['magasin_id', $colonne], ['magasin_id', 'id']]) {
                $nonComposees[] = "{$reference['table']}.{$colonne} → {$reference['cible']} ({$reference['nom']})";
            }
        }

        $this->assertGreaterThanOrEqual(48, count($references), 'Le catalogue doit décrire toutes les références entre tables d\'un magasin.');
        $this->assertSame([], $nonComposees, 'Ces références ne gardent pas la ligne visée dans le même magasin.');
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function autresMagasins(): array
    {
        return [
            'autre magasin de la même société' => [true],
            'autre société' => [false],
        ];
    }

    /**
     * Pour chaque référence du catalogue : une ligne du magasin A qu'on fait
     * désigner une ligne de l'autre magasin est refusée par la clé
     * composée (23503, au nom de cette contrainte), et la même écriture vers une
     * autre ligne du magasin A passe. Les déclencheurs de la table écrite
     * sont levés le temps de l'écriture, comme le ferait le propriétaire
     * des tables, dans un point de sauvegarde annulé ensuite : la table en
     * ajout seul, le document émis ou la colonne figée refuseraient sinon
     * l'écriture avant que la clé ne soit éprouvée.
     */
    #[DataProvider('autresMagasins')]
    public function test_la_base_refuse_qu_une_ligne_designe_une_ligne_d_un_autre_magasin(bool $memeSociete): void
    {
        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->travelTo(self::aParis('2026-10-09 09:00:00'));
        $magasinA = $this->ouvrirUneSociete(['nom' => 'Boutique A']);
        $ailleurs = $memeSociete ? $this->ouvrirUnAutreMagasin($magasinA) : $this->ouvrirUneSociete(['nom' => 'Boutique B']);
        $this->remplirLesMagasins([$magasinA, $ailleurs]);
        // Les contrôles différés de la préparation (cohérence des achats,
        // auteur des mouvements du grand livre) jouent ici, la préparation
        // les passant : une table dont les déclencheurs se lèvent ensuite
        // ne garde aucun événement en attente, que PostgreSQL refuserait.
        $this->assertTrue(DB::statement('SET CONSTRAINTS ALL IMMEDIATE'));
        $acceptees = [];

        foreach ($this->referencesEntreMagasins() as $reference) {
            $table = $reference['table'];
            $colonne = self::colonneDe($reference);
            // D'abord une ligne où la référence est déjà posée : une
            // contrainte qui lie la colonne à d'autres (le numéro et le jour
            // d'acceptation du devis d'origine d'une facture) refuserait
            // sinon l'écriture avant que la clé ne soit éprouvée.
            $source = DB::table($table)->where('magasin_id', $magasinA->id)->orderByRaw("{$colonne} is null")->orderBy($reference['cle'])->first();
            $this->assertNotNull($source, "La préparation n'écrit aucune ligne de {$table} dans le magasin A.");
            $actuelle = $source->{$colonne};
            $cle = [$reference['cle'] => $source->{$reference['cle']}];
            // Les lignes visées de l'autre magasin, d'abord celles que la
            // table ne désigne pas encore : une unicité qui porte sur la
            // colonne (23505) refuse sinon l'écriture avant que la clé ne
            // soit éprouvée, et la ligne suivante est essayée.
            $etrangeres = DB::table($reference['cible'])->where('magasin_id', $ailleurs->id)->orderBy('id')->pluck('id');
            $designees = DB::table($table)->whereNotNull($colonne)->pluck($colonne);
            $this->assertNotEmpty($etrangeres, "La préparation n'écrit aucune ligne de {$reference['cible']} dans l'autre magasin.");
            $refus = null;

            foreach ([...$etrangeres->diff($designees), ...$etrangeres->intersect($designees)] as $etrangere) {
                $refus = $this->ecrireSansDeclencheurs($table, $cle, $colonne, (int) $etrangere);

                if (($refus?->errorInfo[0] ?? null) !== '23505') {
                    break;
                }
            }

            $this->assertInstanceOf(QueryException::class, $refus, "{$table}.{$colonne} accepte une ligne de {$reference['cible']} d'un autre magasin.");
            $this->assertSame('23503', $refus->errorInfo[0] ?? null, "{$table}.{$colonne} : {$refus->getMessage()}");
            $this->assertStringContainsString("\"{$reference['nom']}\"", (string) ($refus->errorInfo[2] ?? ''));

            $locales = DB::table($reference['cible'])->where('magasin_id', $magasinA->id)->when($actuelle !== null, fn ($lignes) => $lignes->where('id', '!=', $actuelle))->orderByDesc('id')->pluck('id');
            $this->assertNotEmpty($locales, "La préparation n'écrit qu'une ligne de {$reference['cible']} dans le magasin A.");
            $acceptees[$reference['nom']] = $locales->contains(fn (mixed $locale): bool => $this->ecrireSansDeclencheurs($table, $cle, $colonne, (int) $locale) === null);
        }

        $this->assertSame([], array_keys(array_filter($acceptees, static fn (bool $acceptee): bool => ! $acceptee)), 'La même écriture vers une autre ligne du magasin A devrait passer.');
    }

    /**
     * Écrit cette valeur dans cette colonne de la ligne désignée par sa clé
     * primaire, déclencheurs de la table levés, dans un point de sauvegarde
     * toujours annulé : rend le refus de la base, ou null si elle a
     * accepté.
     *
     * @param  array<string, mixed>  $ligne
     */
    private function ecrireSansDeclencheurs(string $table, array $ligne, string $colonne, int $valeur): ?QueryException
    {
        DB::beginTransaction();

        try {
            DB::statement("ALTER TABLE {$table} DISABLE TRIGGER USER");
            DB::table($table)->where($ligne)->update([$colonne => $valeur]);

            return null;
        } catch (QueryException $refus) {
            return $refus;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Dans chaque magasin, deux journées d'activité faites par les
     * actions : deux fournisseurs, des clients, deux services et des
     * produits ; un inventaire d'ouverture validé et un en brouillon ; une
     * facture d'achat validée et une en brouillon, avec la pièce du
     * fournisseur, à deux taux, un avoir fournisseur
     * émis et un en brouillon ; deux devis ; chaque jour, une vente à la
     * caisse, un ticket resté dû et un avoir qui en reprend la ligne, une
     * facture en brouillon, la fermeture de la caisse du vendeur, puis la
     * clôture de la journée ; le surlendemain, un paiement de chacun des
     * deux tickets restés dus, chacun avec son reçu, que ni la caisse du
     * vendeur ni une clôture n'a encore comptés, un panier de la caisse en
     * cours (une vente le vide), puis les deux devis émis et envoyés par
     * e-mail, chacun avec son lien de réponse : le client accepte en ligne
     * celui du produit, transformé en facture brouillon. Ce qu'aucune
     * action n'écrit sans un service extérieur ou un écran (vérification
     * VIES, preuve d'exonération, bordereau de détaxe, trace technique,
     * panier de la caisse) est écrit directement, ou par sa fabrique.
     *
     * @param  list<Magasin>  $magasins
     */
    private function remplirLesMagasins(array $magasins): void
    {
        $ventes = [];

        foreach ($magasins as $magasin) {
            $ventes[$magasin->id] = app(TenantContext::class)->runAs($magasin, function (): array {
                $auteur = self::auteur();
                $fournisseur = Fournisseur::factory()->create();
                Fournisseur::factory()->create();
                Service::factory()->auPrixDe('5.00')->create();
                Service::factory()->auPrixDe('8.00')->create();
                $inventaire = app(CreerInventaireOuvertureAction::class)->handle($auteur, [
                    ['produit' => ['nom' => 'Carnet', 'reference' => 'CARNET', 'prix_vente_ttc' => '4.90', 'taux_tva' => '20'], 'quantite' => 10, 'cout_unitaire_ht' => '2.00'],
                ]);
                app(ValiderInventaireOuvertureAction::class)->handle($auteur, $inventaire);
                app(CreerInventaireOuvertureAction::class)->handle($auteur, [
                    ['produit' => ['nom' => 'Stylo', 'reference' => 'STYLO', 'prix_vente_ttc' => '1.50', 'taux_tva' => '20'], 'quantite' => 5, 'cout_unitaire_ht' => '0.50'],
                ]);
                $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => true]);
                $livre = Produit::factory()->create(['prix_vente_ttc' => 9, 'taux_tva' => '5.5', 'suivi_stock' => true]);
                $achat = app(ValiderFactureAchatAction::class)->handle($auteur, app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, [
                    ['produit_id' => $produit->id, 'quantite' => 20, 'prix_unitaire_ht' => 5],
                    ['produit_id' => $livre->id, 'quantite' => 10, 'prix_unitaire_ht' => 4],
                ], ['date_facture' => '2026-10-01']));
                app(CreerFactureAchatAction::class)->handle($auteur, $fournisseur, [['produit_id' => $livre->id, 'quantite' => 2, 'prix_unitaire_ht' => 4]], ['date_facture' => '2026-10-02'], null, [new PieceDeposee('facture-fournisseur.pdf', FichiersDeTest::pdf('Facture du fournisseur'))]);
                [$ligneDuProduit, $ligneDuLivre] = $achat->lignes()->orderBy('id')->get()->all();
                app(EmettreAvoirFournisseurAction::class)->handle($auteur, app(CreerAvoirFournisseurAction::class)->handle($auteur, $achat, [['ligne_achat_id' => $ligneDuProduit->id, 'quantite' => 1]]));
                app(CreerAvoirFournisseurAction::class)->handle($auteur, $achat, [['ligne_achat_id' => $ligneDuLivre->id, 'quantite' => 1]]);
                $client = Client::factory()->create();
                $devisDuProduit = app(CreerDevisAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
                $devisDuService = app(CreerDevisAction::class)->handle($auteur, $client, [['service_id' => Service::query()->firstOrFail()->id, 'quantite' => 1]]);
                $professionnel = Client::factory()->professionnel()->create(['pays' => 'DE', 'numero_tva' => 'DE123456789']);

                foreach ([self::enUtc('2026-10-09 12:32:10'), self::enUtc('2026-10-09 12:33:10')] as $consultee) {
                    Model::unguarded(fn (): Model => ConsultationVies::query()->create(['client_id' => $professionnel->id, 'numero_tva' => 'DE123456789', 'valide' => true, 'consultee_le' => $consultee, 'reponse' => '{"valid":true}']));
                }

                return [$client, $produit, $devisDuProduit, $devisDuService];
            });
        }

        foreach (['2026-10-09', '2026-10-10'] as $journee) {
            foreach ($magasins as $magasin) {
                $this->travelTo(self::aParis("{$journee} 10:00:00"));
                [$client, $produit] = $ventes[$magasin->id];
                app(TenantContext::class)->runAs($magasin, function () use ($magasin, $journee, $client, $produit): void {
                    $auteur = self::auteur();
                    self::vendreAuComptant($auteur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
                    $du = app(EmettreFactureAction::class)->handle($auteur, app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => 2]]));
                    app(EnregistrerAvoirAction::class)->handle($auteur, $client, [['ligne_facture_id' => $du->lignes()->sole()->id, 'quantite' => 1]], 'Retour');
                    app(CreerFactureAction::class)->handle($auteur, $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
                    $this->noterSansEcran($du);
                    app(FermerSaCaisseAction::class)->handle($auteur, $auteur);
                    $this->travelTo(self::aParis("{$journee} 18:00:00"));
                    app(CloturerCaisseAction::class)->handle($auteur, $magasin, $journee);
                });
            }
        }

        Mail::fake();

        foreach ($magasins as $magasin) {
            $this->travelTo(self::aParis('2026-10-11 10:00:00'));
            [, , $devisDuProduit, $devisDuService] = $ventes[$magasin->id];
            $jeton = app(TenantContext::class)->runAs($magasin, function () use ($ventes, $magasin, $devisDuProduit, $devisDuService): string {
                [$client] = $ventes[$magasin->id];
                $dus = Facture::query()->where('client_id', $client->id)->where('statut', StatutFacture::PartiellementPayee)->orderBy('id')->get();
                $this->assertCount(2, $dus);

                foreach ($dus as $du) {
                    app(EnregistrerPaiementAction::class)->handle(self::auteur(), $client, ['mode_paiement' => ModePaiement::Especes->value, 'montant' => '1.00', 'recu_le' => '2026-10-11'], [['facture_id' => $du->id, 'montant' => '1.00']]);
                }

                PanierCaisse::factory()->create(['user_id' => self::auteur()->id, 'client_id' => $client->id]);
                $this->envoyerParEMail(app(EmettreDevisAction::class)->handle(self::auteur(), $devisDuService));

                return $this->envoyerParEMail(app(EmettreDevisAction::class)->handle(self::auteur(), $devisDuProduit));
            });
            app(RepondreAuDevisEnLigneAction::class)->accepter($jeton, 'Claire Martin', true);
            app(TenantContext::class)->runAs($magasin, fn () => app(CreerFactureDepuisDevisAction::class)->handle(self::auteur(), $devisDuProduit));
        }
    }

    /**
     * Envoie le devis par e-mail (la tâche d'envoi tourne aussitôt,
     * QUEUE_CONNECTION=sync) et rend le jeton que porte le lien de réponse
     * de l'e-mail parti.
     */
    private function envoyerParEMail(Devis $devis): string
    {
        $envoi = app(EnvoyerDocumentAction::class)->handle(self::auteur(), $devis, 'client@exemple.fr', null, (string) Str::uuid());
        $jeton = null;

        Mail::assertSent(DocumentMail::class, function (DocumentMail $mail) use ($envoi, &$jeton): bool {
            if (! $mail->envoi->is($envoi) || preg_match('#/devis/([A-Za-z0-9]{64})"#', $mail->render(), $trouve) !== 1) {
                return false;
            }

            $jeton = $trouve[1];

            return true;
        });

        return (string) $jeton;
    }

    /**
     * Une preuve d'exonération, un bordereau de détaxe et une trace
     * technique sur cette vente, et la date du visa du premier bordereau du
     * magasin actif (les suivants l'attendent : un visa peut alors désigner
     * un autre bordereau du magasin sans heurter l'unicité d'un visa par
     * bordereau), écrits sans leurs actions, qui exigent une vente à
     * l'export ou détaxée, ou une requête HTTP.
     */
    private function noterSansEcran(Facture $vente): void
    {
        Model::unguarded(function () use ($vente): void {
            PreuveOperation::query()->create(['facture_id' => $vente->id, 'type' => TypePreuve::Mrn, 'reference' => "MRN-{$vente->id}"]);
            $bordereau = BordereauDetaxe::query()->create(['facture_id' => $vente->id, 'numero' => "BD-{$vente->id}"]);
            if (! VisaDetaxe::query()->exists()) {
                VisaDetaxe::query()->create(['bordereau_detaxe_id' => $bordereau->id, 'date_visa' => $vente->magasin->calendrier()->aujourdhui()]);
            }
            TraceEvenement::query()->create([
                'enregistrement_fiscal_id' => EnregistrementFiscal::query()->orderByDesc('id')->firstOrFail()->id,
                'ip' => '203.0.113.7',
                'agent_utilisateur' => 'Navigateur de test',
                'expire_le' => self::enUtc('2027-10-09 08:00:00'),
            ]);
        });
    }
}
