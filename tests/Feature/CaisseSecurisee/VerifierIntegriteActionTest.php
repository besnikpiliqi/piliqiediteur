<?php

namespace Tests\Feature\CaisseSecurisee;

use App\Domain\CaisseSecurisee\Actions\VerifierIntegriteAction;
use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Enums\DeclenchementVerification;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\RapportIntegrite;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Chaque vérification de l'intégrité s'inscrit au journal des événements
 * du magasin : ses anomalies (JET 90 et 95), puis l'opération de contrôle
 * (JET 280), dont les points de reprise font repartir la vérification
 * incrémentale suivante.
 */
class VerifierIntegriteActionTest extends TestCase
{
    use RefreshDatabase;

    private function magasinActif(): Magasin
    {
        $magasin = Magasin::factory()->create(['code' => 'LYON1']);
        app(TenantContext::class)->set($magasin);

        return $magasin;
    }

    private function vendre(float $prixTtc = 12): Facture
    {
        $produit = Produit::factory()->create(['prix_vente_ttc' => $prixTtc, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EnregistrerVenteCaisseAction::class)->handle(self::auteur(), Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]], ModePaiement::Especes);
    }

    /**
     * Une vérification lancée par la commande, au nom du système, ou depuis
     * l'écran, au nom de son auteur.
     */
    private function verifier(bool $complet, DeclenchementVerification $declenchement = DeclenchementVerification::Commande): RapportIntegrite
    {
        $magasin = app(TenantContext::class)->magasin();

        return $declenchement === DeclenchementVerification::Commande
            ? app(VerifierIntegriteAction::class)->parLeSysteme($magasin, $complet)
            : app(VerifierIntegriteAction::class)->handle(self::auteur(), $magasin, $complet);
    }

    /**
     * Le journal des événements, dans l'ordre : séquence et code.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function journal(): array
    {
        return EnregistrementFiscal::query()->where('chaine', ChaineFiscale::Evenement->value)->orderBy('sequence')->get()
            ->map(static fn (EnregistrementFiscal $evenement): array => [$evenement->sequence, $evenement->type])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function detailsDuDernierControle(): array
    {
        return EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Evenement->value)
            ->where('type', '280')
            ->orderByDesc('sequence')
            ->firstOrFail()
            ->contenuSigne()['donnees']['details'];
    }

    private function signatureDe(ChaineFiscale $chaine, int $sequence): string
    {
        return EnregistrementFiscal::query()->where('chaine', $chaine->value)->where('sequence', $sequence)->value('signature');
    }

    public function test_une_verification_conforme_inscrit_l_operation_de_controle_et_ses_points_de_reprise(): void
    {
        $this->magasinActif();
        $this->vendre();
        Log::spy();

        $rapport = $this->verifier(complet: true);

        $this->assertTrue($rapport->estConforme());
        $this->assertSame([[1, '260'], [2, '280']], $this->journal());
        $this->assertSame([
            'verification' => 'complete',
            'declenchement' => 'commande',
            'resultat' => 'conforme',
            'nombre_anomalies' => 0,
            'enregistrements_verifies' => ['vente' => 1, 'encaissement' => 1, 'evenement' => 1],
            'points_de_reprise' => [
                'vente' => ['sequence' => 1, 'signature' => $this->signatureDe(ChaineFiscale::Vente, 1)],
                'encaissement' => ['sequence' => 1, 'signature' => $this->signatureDe(ChaineFiscale::Encaissement, 1)],
                'evenement' => ['sequence' => 1, 'signature' => $this->signatureDe(ChaineFiscale::Evenement, 1)],
            ],
        ], $this->detailsDuDernierControle());
        Log::shouldNotHaveReceived('critical');
    }

    /**
     * Chaque anomalie s'inscrit sous son code, une rupture de numérotation
     * (95) comme un document modifié (90), avant l'opération de contrôle
     * qui en donne le nombre ; l'éditeur en est prévenu.
     */
    public function test_chaque_anomalie_s_inscrit_au_journal_avant_l_operation_de_controle_et_est_signalee(): void
    {
        $magasin = $this->magasinActif();
        $ticket = $this->vendre();
        DB::table('lignes_facture')->where('magasin_id', $magasin->id)->where('facture_id', $ticket->id)->update(['description' => 'Article réécrit']);
        DB::table('sequences_documents')->where('magasin_id', $magasin->id)->where('type_document', 'ticket_caisse')->update(['prochain_numero' => 3]);
        Log::spy();

        $rapport = $this->verifier(complet: true);

        $this->assertCount(2, $rapport->anomalies());
        $this->assertSame([[1, '260'], [2, '90'], [3, '95'], [4, '280']], $this->journal());
        $anomalies = EnregistrementFiscal::query()->whereIn('type', ['90', '95'])->orderBy('sequence')->get()
            ->map(static fn (EnregistrementFiscal $evenement): array => $evenement->contenuSigne()['donnees']['details'])
            ->all();
        $this->assertSame([
            ['controle' => 'document', 'message' => 'Le ticket TK-LYON1-2026-000001 ne correspond plus à ce qui a été signé à son émission (lignes).', 'chaine' => 'vente', 'sequence' => 1, 'document' => 'facture', 'document_id' => $ticket->id],
            ['controle' => 'numerotation', 'message' => 'Série TK-LYON1-2026- : 1 numéro(s) attribué(s) sans document : TK-LYON1-2026-000002.', 'serie' => 'TK-LYON1-2026-'],
        ], $anomalies);
        $this->assertSame(['resultat' => 'anomalies', 'nombre_anomalies' => 2], Arr::only($this->detailsDuDernierControle(), ['resultat', 'nombre_anomalies']));
        Log::shouldHaveReceived('critical')->once()->withArgs(static fn (string $message, array $contexte): bool => $contexte['magasin_id'] === $magasin->id
            && $contexte['nombre_anomalies'] === 2
            && $contexte['anomalies'][1] === '[95] Série TK-LYON1-2026- : 1 numéro(s) attribué(s) sans document : TK-LYON1-2026-000002.');
    }

    /**
     * La vérification incrémentale reprend après la dernière opération de
     * contrôle : la nouvelle vente, son encaissement et les événements
     * inscrits depuis, dont cette opération elle-même.
     */
    public function test_la_verification_incrementale_reprend_apres_la_derniere_operation_de_controle(): void
    {
        $this->magasinActif();
        $this->vendre();
        $this->verifier(complet: true);
        $this->vendre(20);

        $rapport = $this->verifier(complet: false);

        $this->assertFalse($rapport->complet);
        $this->assertSame(['verification' => 'incrementale', 'enregistrements_verifies' => ['vente' => 1, 'encaissement' => 1, 'evenement' => 1]], Arr::only($this->detailsDuDernierControle(), ['verification', 'enregistrements_verifies']));
        $this->assertSame(
            ['vente' => 2, 'encaissement' => 2, 'evenement' => 2],
            Arr::map($this->detailsDuDernierControle()['points_de_reprise'], static fn (array $pointDeReprise): int => $pointDeReprise['sequence']),
        );
    }

    public function test_sans_operation_de_controle_precedente_la_verification_reprend_tout_le_registre(): void
    {
        $this->magasinActif();
        $this->vendre();

        $rapport = $this->verifier(complet: false);

        $this->assertTrue($rapport->complet);
        $this->assertSame('complete', $this->detailsDuDernierControle()['verification']);
    }

    /**
     * Le registre encore vide, la première opération de contrôle n'a aucun
     * point de reprise : la suivante relit chaque chaîne depuis son premier
     * enregistrement, et s'inscrit comme complète.
     */
    public function test_apres_une_operation_de_controle_sans_point_de_reprise_la_verification_est_complete(): void
    {
        $this->magasinActif();
        $this->verifier(complet: true);

        $rapport = $this->verifier(complet: false);

        $this->assertTrue($rapport->complet);
        $this->assertSame(
            ['verification' => 'complete', 'enregistrements_verifies' => ['vente' => 0, 'encaissement' => 0, 'evenement' => 2]],
            Arr::only($this->detailsDuDernierControle(), ['verification', 'enregistrements_verifies']),
        );
    }

    /**
     * Des points de reprise falsifiés pour sauter des enregistrements ne
     * se vérifient plus avec leur signature : tout le registre est
     * vérifié, et la falsification relevée.
     */
    public function test_une_operation_de_controle_falsifiee_fait_verifier_tout_le_registre(): void
    {
        $magasin = $this->magasinActif();
        $this->vendre();
        $this->verifier(complet: true);
        $controle = EnregistrementFiscal::query()->where('type', '280')->sole();
        $this->retirerLeDeclencheur('enregistrements_fiscaux', 'enregistrements_fiscaux_ajout_seul_update');
        DB::table('enregistrements_fiscaux')->where('magasin_id', $magasin->id)->where('id', $controle->id)
            ->update(['contenu' => str_replace('"vente":{"sequence":1', '"vente":{"sequence":9', $controle->contenu)]);

        $rapport = $this->verifier(complet: false);

        $this->assertTrue($rapport->complet);
        $this->assertSame(['Enregistrement n° 2 du journal des événements : sa signature ne correspond pas à son contenu signé.'], array_map(
            static fn (AnomalieFiscale $anomalie): string => $anomalie->message,
            $rapport->anomalies(),
        ));
    }

    /**
     * Une clé retirée du dépôt rend suspect chaque enregistrement qu'elle a
     * signé : le rapport et le journal n'en retiennent que les cent
     * premiers, le rapport et l'opération de contrôle en donnent le nombre
     * total.
     */
    public function test_le_rapport_et_le_journal_ne_retiennent_que_les_cent_premieres_anomalies_d_une_verification(): void
    {
        $this->magasinActif();
        DB::transaction(function (): void {
            foreach (range(1, 104) as $export) {
                app(RegistreFiscal::class)->journaliser(CodeEvenement::ExportDeDonnees, ['export' => $export]);
            }
        });
        $nouvellePaire = SignataireFiscal::genererUnePaire();
        config([
            'caisse_securisee.cle_privee' => base64_encode($nouvellePaire['cle_privee']),
            'caisse_securisee.cles_publiques' => ['nouvelle.pem' => $nouvellePaire['cle_publique']],
        ]);
        $this->app->forgetInstance(RegistreFiscal::class);

        $rapport = $this->verifier(complet: true);

        $this->assertSame(105, $rapport->nombreDAnomalies());
        $this->assertCount(100, $rapport->anomalies());
        $this->assertSame(100, EnregistrementFiscal::query()->where('type', '90')->count());
        $this->assertSame(105, $this->detailsDuDernierControle()['nombre_anomalies']);
    }

    public function test_sans_cle_de_signature_la_verification_est_refusee_sans_rien_inscrire(): void
    {
        $this->magasinActif();
        $this->vendre();
        config(['caisse_securisee.cle_privee' => null]);
        $this->app->forgetInstance(RegistreFiscal::class);

        $this->assertThrows(
            fn () => $this->verifier(complet: true),
            ModuleFiscalNonConfigureException::class,
            'Caisse sécurisée non configurée : la clé privée de signature (CAISSE_SECURISEE_CLE_PRIVEE) est absente.',
        );

        $this->assertSame([[1, '260']], $this->journal());
    }
}
