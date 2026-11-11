<?php

namespace Tests\Feature\Fichiers;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\JoindreDesPiecesAction;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Calendrier\Casts\Instant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Fichiers\Enums\ContenuActif;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureAchatResource;
use App\Filament\App\Resources\FactureAchatResource\Pages\ViewFactureAchat;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Integrations\Antivirus\ResultatDeLAntivirus;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Les pièces du fournisseur face aux contrôles des fichiers reçus
 * (décisions de l'éditeur du 07/11/2026, lot FI) : un refus pour une
 * raison de sécurité s'inscrit au journal d'audit de la société, une
 * menace prévient le propriétaire ; une pièce dont la dernière analyse a
 * plus de 24 heures repasse par l'antivirus au téléchargement, et une
 * menace trouvée alors la bloque, gardée mais plus jamais servie ; un
 * antivirus injoignable fait réessayer ; un magasin ne touche jamais la
 * pièce ni l'analyse d'un autre.
 */
class PiecesRecuesTest extends TestCase
{
    use RefreshDatabase;

    private Magasin $magasin;

    private User $responsable;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PiecesDuFournisseur::DISQUE);
        Notification::fake();
        $this->travelTo(self::aParis('2026-03-02 10:00:00'));
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']);
        $this->responsable = $this->membre($this->magasin, RoleMagasin::Responsable, identite: ['name' => 'Rose Martin']);
    }

    /**
     * Un PDF actif est refusé avant toute écriture, et son refus
     * s'inscrit au journal d'audit, dans la chaîne du magasin : auteur,
     * document visé, raison, ce qu'il contient, nom tel que reçu,
     * empreinte et taille du fichier reçu, jamais son contenu.
     */
    public function test_un_refus_de_securite_s_inscrit_au_journal_d_audit_sans_rien_enregistrer(): void
    {
        $factureAchat = $this->saisir([]);
        $actif = FichiersDeTest::pdf('x', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>');

        $this->assertRefus(RaisonDuRefus::PdfActif, fn () => app(JoindreDesPiecesAction::class)->handle($this->responsable, $factureAchat, [new PieceDeposee("facture\u{202E}fdp.exe", $actif)]));

        $this->assertSame(0, PieceDuFournisseur::query()->count());
        $this->assertSame([], Storage::disk(PiecesDuFournisseur::DISQUE)->allFiles());
        $entree = $this->entreesDAudit($this->magasin->societe, ActionAuditee::PieceDuFournisseurRefusee)[0];
        $this->assertSame([
            'detail' => ['javascript', 'action_automatique'],
            'document' => ['nature' => 'facture_achat', 'numero' => null, 'numero_fournisseur' => 'F-812'],
            'empreinte_recue' => hash('sha256', $actif),
            'nom_recu' => "facture\u{202E}fdp.exe",
            'raison' => 'pdf_actif',
            'taille_recue' => strlen($actif),
        ], $entree->nouvelles_valeurs);
        $this->assertSame([$this->magasin->id, $this->responsable->id], [$entree->magasin_id, $entree->auteur_id]);

        $this->assertRefus(RaisonDuRefus::Vide, fn () => app(JoindreDesPiecesAction::class)->handle($this->responsable, $factureAchat, [new PieceDeposee('vide.pdf', '')]));
        $this->assertCount(1, $this->entreesDAudit($this->magasin->societe, ActionAuditee::PieceDuFournisseurRefusee), 'Un fichier vide n\'est pas un refus de sécurité.');
        Notification::assertNothingSent();
    }

    /**
     * Le fichier de test EICAR, refusé par l'antivirus à la saisie d'une
     * facture d'achat : rien n'est enregistré, le refus s'inscrit au
     * journal d'audit (aucun document encore), et le propriétaire de la
     * société est prévenu.
     */
    public function test_une_menace_au_depot_est_refusee_et_previent_le_proprietaire(): void
    {
        $this->assertRefus(RaisonDuRefus::Menace, fn () => $this->saisir([new PieceDeposee('facture.pdf', AntivirusFactice::fichierDeTest())]));

        $this->assertSame([0, 0], [FactureAchat::query()->count(), PieceDuFournisseur::query()->count()]);
        $valeurs = $this->entreesDAudit($this->magasin->societe, ActionAuditee::PieceDuFournisseurRefusee)[0]->nouvelles_valeurs;
        $this->assertSame([null, 'menace', AntivirusFactice::MENACE], [$valeurs['document'], $valeurs['raison'], $valeurs['detail']]);
        Notification::assertSentTo($this->proprietaireDe($this->magasin), AvisDeLAntivirus::class, fn (AvisDeLAntivirus $avis): bool => $avis->titre === 'Boutique du Port : Fichier refusé par l\'antivirus'
            && str_contains($avis->texte, 'Rose Martin')
            && str_contains($avis->texte, AntivirusFactice::MENACE));
    }

    /**
     * À l'écran, le refus s'affiche avec sa raison et ce qu'il faut faire,
     * et la fiche ne reçoit rien.
     */
    public function test_l_ecran_dit_le_refus_et_comment_obtenir_une_copie_propre(): void
    {
        $factureAchat = $this->saisir([]);
        $this->actingAs($this->responsable);
        Filament::setTenant($this->magasin);

        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->callAction('ajouter_des_pieces', ['pieces' => [UploadedFile::fake()->createWithContent('facture.pdf', FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /Link /A << /S /Launch /F (calc.exe) >> >>']))]])
            ->assertNotified(RaisonDuRefus::PdfActif->message('facture.pdf', [ContenuActif::LancementDeProgramme]));

        $this->assertSame(0, $factureAchat->pieces()->count());
        $this->assertCount(1, $this->entreesDAudit($this->magasin->societe, ActionAuditee::PieceDuFournisseurRefusee));
    }

    /**
     * Une pièce analysée il y a moins de 24 heures se sert sans nouvelle
     * analyse ; au-delà, l'antivirus la relit, et sa dernière analyse
     * avance.
     */
    public function test_une_piece_se_relit_au_telechargement_quand_sa_derniere_analyse_a_plus_de_24_heures(): void
    {
        $piece = $this->saisir([new PieceDeposee('facture.pdf', FichiersDeTest::pdf())])->pieces()->sole();
        $antivirus = $this->antivirusQuiCompte(ResultatDeLAntivirus::sain());

        $this->travelTo(self::aParis('2026-03-03 09:59:59'));
        $this->telecharger($this->responsable, $piece)->assertOk();
        $this->assertSame(0, $antivirus->analyses);

        $this->travelTo(self::aParis('2026-03-03 10:00:01'));
        $this->telecharger($this->responsable, $piece)->assertOk()->assertDownload('facture.pdf');
        $this->assertSame(1, $antivirus->analyses);
        $this->assertMemeInstant(self::aParis('2026-03-03 10:00:01'), $piece->fresh()?->analysee_le);
    }

    /**
     * Reconnue plus tard comme une menace, la pièce est bloquée : gardée
     * comme pièce justificative, plus jamais servie, son blocage inscrit
     * au journal d'audit, le propriétaire prévenu une fois ; la fiche la
     * marque, sans lien.
     */
    public function test_une_piece_reconnue_plus_tard_comme_une_menace_est_bloquee_et_gardee(): void
    {
        $factureAchat = $this->saisir([new PieceDeposee('facture.pdf', FichiersDeTest::pdf())]);
        $piece = $factureAchat->pieces()->sole();
        $this->antivirusQuiCompte(ResultatDeLAntivirus::menace('Pdf.Exploit.Nouveau'));
        $this->travelTo(self::aParis('2026-03-04 10:00:00'));

        $this->telecharger($this->responsable, $piece)->assertForbidden()->assertSee('l&#039;antivirus y a trouvé une menace', false);
        $this->telecharger($this->proprietaireDe($this->magasin), $piece)->assertForbidden();

        $bloquee = PieceDuFournisseur::query()->findOrFail($piece->id);
        $this->assertSame('Pdf.Exploit.Nouveau', $bloquee->menace);
        $this->assertMemeInstant(self::aParis('2026-03-04 10:00:00'), $bloquee->bloquee_le);
        Storage::disk(PiecesDuFournisseur::DISQUE)->assertExists($bloquee->chemin);
        $this->assertSame([[
            'document' => ['nature' => 'facture_achat', 'numero' => null, 'numero_fournisseur' => 'F-812'],
            'empreinte' => $piece->empreinte,
            'menace' => 'Pdf.Exploit.Nouveau',
            'nom' => 'facture.pdf',
        ]], array_map(fn ($entree): ?array => $entree->nouvelles_valeurs, $this->entreesDAudit($this->magasin->societe, ActionAuditee::PieceDuFournisseurBloquee)));
        Notification::assertSentToTimes($this->proprietaireDe($this->magasin), AvisDeLAntivirus::class, 1);

        $this->actingAs($this->responsable);
        Filament::setTenant($this->magasin);
        Livewire::test(ViewFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->assertSee('Bloquée par l\'antivirus')
            ->assertDontSee(FactureAchatResource::adresseDeLaPiece($bloquee));
    }

    /**
     * Un antivirus injoignable ne sert jamais une pièce dont la dernière
     * analyse a plus de 24 heures : la page dit de réessayer, rien n'est
     * bloqué ni noté.
     */
    public function test_un_antivirus_injoignable_fait_reessayer_le_telechargement(): void
    {
        $piece = $this->saisir([new PieceDeposee('facture.pdf', FichiersDeTest::pdf())])->pieces()->sole();
        $this->app->instance(Antivirus::class, new class implements Antivirus
        {
            public function analyser(string $contenu): ResultatDeLAntivirus
            {
                throw new AntivirusInjoignableException('clamd est injoignable.');
            }

            public function description(): string
            {
                throw new AntivirusInjoignableException('clamd est injoignable.');
            }
        });
        $this->travelTo(self::aParis('2026-03-04 10:00:00'));

        $this->telecharger($this->responsable, $piece)->assertStatus(503)->assertHeader('Retry-After', '300')->assertSee('Réessayez dans quelques minutes');

        $relue = PieceDuFournisseur::query()->findOrFail($piece->id);
        $this->assertNull($relue->bloquee_le);
        $this->assertMemeInstant(self::aParis('2026-03-02 10:00:00'), $relue->analysee_le);
    }

    /**
     * Un magasin ne lit jamais la pièce d'un autre, ni ne déclenche son
     * analyse : l'adresse d'une pièce, prise dans un autre magasin, ne
     * trouve rien, avant tout antivirus.
     */
    public function test_un_autre_magasin_ne_touche_ni_la_piece_ni_son_analyse(): void
    {
        $piece = $this->saisir([new PieceDeposee('facture.pdf', FichiersDeTest::pdf())])->pieces()->sole();
        $autre = $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);
        $antivirus = $this->antivirusQuiCompte(ResultatDeLAntivirus::menace('Pdf.Exploit.Nouveau'));
        $this->travelTo(self::aParis('2026-03-04 10:00:00'));

        $this->flushSession();
        $this->actingAs($this->proprietaireDe($this->magasin))
            ->get(route('filament.app.pieces-du-fournisseur.telecharger', ['tenant' => $autre, 'piece' => $piece->uuid]))
            ->assertNotFound();
        $this->telecharger($this->membre($autre, RoleMagasin::Responsable), $piece)->assertNotFound();

        $this->assertSame(0, $antivirus->analyses);
        $this->assertNull(PieceDuFournisseur::query()->findOrFail($piece->id)->bloquee_le);
        app(TenantContext::class)->runAs($autre, fn () => $this->assertSame(0, PieceDuFournisseur::query()->count()));
    }

    /**
     * La base tient la pièce : seule sa dernière analyse avance, et son
     * blocage se pose une fois, avec sa menace, pour toujours ; tout le
     * reste ne bouge jamais.
     */
    public function test_la_base_ne_laisse_changer_que_l_analyse_et_le_blocage_une_fois(): void
    {
        $piece = $this->saisir([new PieceDeposee('facture.pdf', FichiersDeTest::pdf())])->pieces()->sole();
        $ligne = fn () => DB::table('pieces_fournisseur')->where('id', $piece->id);

        $ligne()->update(['analysee_le' => Instant::enBase(self::aParis('2026-03-03 09:00:00'))]);
        $this->assertRefusDeLaBase(fn () => $ligne()->update(['analysee_le' => Instant::enBase(self::aParis('2026-03-01 09:00:00'))]));
        $this->assertRefusDeLaBase(fn () => $ligne()->update(['bloquee_le' => Instant::enBase(self::aParis('2026-03-03 09:00:00'))]));
        $ligne()->update(['bloquee_le' => Instant::enBase(self::aParis('2026-03-03 09:00:00')), 'menace' => 'Pdf.Exploit.Nouveau']);
        $this->assertRefusDeLaBase(fn () => $ligne()->update(['bloquee_le' => null, 'menace' => null]));
        $this->assertRefusDeLaBase(fn () => $ligne()->update(['menace' => 'Autre']));
        $this->assertRefusDeLaBase(fn () => $ligne()->update(['chemin' => 'magasin-1/autre.pdf']));
        $this->assertRefusDeLaBase(fn () => $ligne()->update(['nom' => 'facture.bat']));

        $this->assertSame(['facture.pdf', 'Pdf.Exploit.Nouveau'], [$ligne()->value('nom'), $ligne()->value('menace')]);
    }

    /**
     * Saisit à la main une facture d'achat du Grossiste du Nord, n° F-812,
     * d'une ligne, avec ces pièces.
     *
     * @param  list<PieceDeposee>  $pieces
     */
    private function saisir(array $pieces): FactureAchat
    {
        $produit = Produit::query()->where('reference', 'CARNET')->first() ?? Produit::factory()->create(['reference' => 'CARNET', 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(CreerFactureAchatAction::class)->handle(
            $this->responsable,
            Fournisseur::query()->firstOrCreate(['nom' => 'Grossiste du Nord']),
            [['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => '3.00']],
            ['date_facture' => '2026-02-27', 'numero_fournisseur' => 'F-812'],
            null,
            $pieces,
        );
    }

    /**
     * Un antivirus qui rend toujours ce verdict, et compte ses analyses.
     */
    private function antivirusQuiCompte(ResultatDeLAntivirus $verdict): object
    {
        $antivirus = new class($verdict) implements Antivirus
        {
            public int $analyses = 0;

            public function __construct(private readonly ResultatDeLAntivirus $verdict) {}

            public function analyser(string $contenu): ResultatDeLAntivirus
            {
                $this->analyses++;

                return $this->verdict;
            }

            public function description(): string
            {
                return 'antivirus des tests';
            }
        };
        $this->app->instance(Antivirus::class, $antivirus);

        return $antivirus;
    }

    /**
     * Le téléchargement de cette pièce par ce compte, dans une session
     * neuve, comme depuis son propre navigateur.
     *
     * @return TestResponse<Response>
     */
    private function telecharger(User $compte, PieceDuFournisseur $piece): TestResponse
    {
        $this->flushSession();
        app(TenantContext::class)->set($this->magasin);
        $adresse = FactureAchatResource::adresseDeLaPiece($piece);

        return $this->actingAs($compte)->get($adresse);
    }

    private function assertRefus(RaisonDuRefus $raison, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Refus attendu : {$raison->value}");
        } catch (FichierRefuseException $refus) {
            $this->assertSame($raison, $refus->raison);
        }
    }

    private function assertRefusDeLaBase(callable $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('La base refuse la modification.');
        } catch (QueryException $refus) {
            $this->assertStringContainsString('pieces_fournisseur_jamais_modifiees', $refus->getMessage());
        }
    }
}
