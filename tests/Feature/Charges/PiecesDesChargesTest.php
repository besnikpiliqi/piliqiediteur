<?php

namespace Tests\Feature\Charges;

use App\Domain\Charges\Actions\DeposerUneChargeAction;
use App\Domain\Charges\Models\Charge;
use App\Domain\Charges\Models\PieceDeCharge;
use App\Domain\Charges\Services\PiecesDesCharges;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\PageDesCharges;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Integrations\Antivirus\ResultatDeLAntivirus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * La pièce d'une charge (lot CHARGES) passe les contrôles de toute pièce
 * reçue (CLAUDE.md, « Fichiers reçus ») : un PDF actif est refusé avant
 * toute écriture et son refus s'inscrit au journal d'audit ; une photo est
 * réenregistrée sans ses métadonnées, sous un nom à l'extension de son
 * type ; une menace au dépôt est refusée et prévient le propriétaire ; une
 * pièce reconnue plus tard comme une menace est bloquée, gardée, et ne se
 * télécharge plus.
 */
class PiecesDesChargesTest extends TestCase
{
    use PrepareDesCharges;
    use RefreshDatabase;

    private Magasin $lyon;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disqueDesCharges();
        Notification::fake();
        $this->travelTo(self::aParis('2026-05-20 10:00:00'));
        $this->lyon = $this->ouvrirUneSociete(['nom' => 'Lyon']);
        $this->societe = $this->lyon->societe;
        $this->proprietaire = $this->proprietaireDe($this->lyon);
        app(TenantContext::class)->clear();
    }

    public function test_un_pdf_actif_est_refuse_avant_toute_ecriture_et_son_refus_s_inscrit_au_journal_d_audit(): void
    {
        $actif = FichiersDeTest::pdf('x', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>');

        $this->assertRefus(RaisonDuRefus::PdfActif, fn () => $this->deposerLaPiece(new PieceDeposee('quittance.pdf', $actif)));

        $this->assertSame([0, 0], [$this->nombre(Charge::class), $this->nombre(PieceDeCharge::class)]);
        $this->assertSame([], Storage::disk(PiecesDesCharges::DISQUE)->allFiles());
        $entrees = $this->entreesDAudit($this->societe, ActionAuditee::PieceDeChargeRefusee);
        $this->assertCount(1, $entrees);
        $this->assertSame([
            'charge' => null,
            'detail' => ['javascript', 'action_automatique'],
            'empreinte_recue' => hash('sha256', $actif),
            'nom_recu' => 'quittance.pdf',
            'raison' => 'pdf_actif',
            'taille_recue' => strlen($actif),
        ], $entrees[0]->nouvelles_valeurs);
        $this->assertSame($this->proprietaire->id, $entrees[0]->auteur_id);
        Notification::assertNothingSent();
    }

    /**
     * Le refus d'une pièce déposée depuis l'espace d'un cabinet lié
     * s'inscrit au journal de la société de la charge, jamais à celui du
     * cabinet, actif dans son espace.
     */
    public function test_le_refus_d_une_piece_deposee_par_le_cabinet_s_inscrit_au_journal_du_client(): void
    {
        $collaborateur = $this->collaborateurDuCabinetLie($this->lyon);
        $cabinet = $collaborateur->societe()->firstOrFail();
        $actif = FichiersDeTest::pdf('x', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>');
        $this->dansLEspaceDuCabinet($cabinet, $collaborateur);

        $this->assertRefus(RaisonDuRefus::PdfActif, fn () => app(DeposerUneChargeAction::class)->handle($collaborateur, $this->societe, $this->saisie(), new PieceDeposee('quittance.pdf', $actif)));

        $this->assertSame([$collaborateur->id], array_map(fn ($entree): ?int => $entree->auteur_id, $this->entreesDAudit($this->societe, ActionAuditee::PieceDeChargeRefusee)));
        $this->assertSame([], $this->entreesDAudit($cabinet, ActionAuditee::PieceDeChargeRefusee));
    }

    public function test_une_photo_est_reenregistree_sans_ses_metadonnees_sous_l_extension_de_son_type(): void
    {
        $photo = FichiersDeTest::avecExif(FichiersDeTest::jpeg(), gps: true);
        $this->assertContains(0xE1, FichiersDeTest::segmentsJpeg($photo));

        $charge = $this->deposerLaPiece(new PieceDeposee('quittance.png', $photo));

        $piece = PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $charge->getKey())->sole();
        $enregistree = (string) Storage::disk(PiecesDesCharges::DISQUE)->get($piece->chemin);
        $this->assertSame(['quittance.jpg', 'image/jpeg'], [$piece->nom, $piece->type_mime]);
        $this->assertNotContains(0xE1, FichiersDeTest::segmentsJpeg($enregistree));
        $this->assertStringNotContainsString('Exif', $enregistree);
        $this->assertSame(hash('sha256', $enregistree), $piece->empreinte);
    }

    public function test_une_menace_au_depot_est_refusee_et_previent_le_proprietaire(): void
    {
        $this->assertRefus(RaisonDuRefus::Menace, fn () => $this->deposerLaPiece(new PieceDeposee('quittance.pdf', AntivirusFactice::fichierDeTest())));

        $this->assertSame([0, 0], [$this->nombre(Charge::class), $this->nombre(PieceDeCharge::class)]);
        $valeurs = $this->entreesDAudit($this->societe, ActionAuditee::PieceDeChargeRefusee)[0]->nouvelles_valeurs;
        $this->assertSame(['menace', AntivirusFactice::MENACE], [$valeurs['raison'], $valeurs['detail']]);
        Notification::assertSentTo($this->proprietaire, AvisDeLAntivirus::class, fn (AvisDeLAntivirus $avis): bool => str_contains($avis->texte, AntivirusFactice::MENACE)
            && str_contains($avis->texte, 'pour une charge'));
    }

    public function test_une_piece_reconnue_plus_tard_comme_une_menace_est_bloquee_gardee_et_ne_se_telecharge_plus(): void
    {
        $charge = $this->deposer($this->proprietaire, $this->societe);
        $piece = PieceDeCharge::query()->withoutGlobalScopes()->where('charge_id', $charge->getKey())->sole();
        $this->app->instance(Antivirus::class, new class implements Antivirus
        {
            public function analyser(string $contenu): ResultatDeLAntivirus
            {
                return ResultatDeLAntivirus::menace('Pdf.Exploit.Nouveau');
            }

            public function description(): string
            {
                return 'antivirus des tests';
            }
        });
        $this->travelTo(self::aParis('2026-05-21 10:00:01'));
        $this->dansLEspaceDeLaSociete($this->societe, $this->proprietaire);

        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->callTableAction('voirLaPiece', $charge)
            ->assertNotified('Cette pièce ne se télécharge pas')
            ->assertNoFileDownloaded();
        Livewire::test(PageDesCharges::class, ['ecran' => PageDesCharges::SOCIETE])
            ->assertTableActionHidden('voirLaPiece', $charge);

        $bloquee = PieceDeCharge::query()->withoutGlobalScopes()->findOrFail($piece->getKey());
        $this->assertSame('Pdf.Exploit.Nouveau', $bloquee->menace);
        $this->assertMemeInstant(self::aParis('2026-05-21 10:00:01'), $bloquee->bloquee_le);
        Storage::disk(PiecesDesCharges::DISQUE)->assertExists($bloquee->chemin);
        $this->assertCount(1, $this->entreesDAudit($this->societe, ActionAuditee::PieceDeChargeBloquee));
        Notification::assertSentToTimes($this->proprietaire, AvisDeLAntivirus::class, 1);
        $this->assertThrows(fn () => app(TenantContext::class)->runAsSociete($this->societe, fn (): string => app(PiecesDesCharges::class)->contenuServable($this->proprietaire, $bloquee)), PieceBloqueeException::class);
    }

    private function deposerLaPiece(PieceDeposee $piece): Charge
    {
        app(TenantContext::class)->clear();
        $charge = app(DeposerUneChargeAction::class)->handle($this->proprietaire, $this->societe, $this->saisie(), $piece);
        app(TenantContext::class)->clear();

        return $charge;
    }

    /**
     * @param  class-string<Charge|PieceDeCharge>  $modele
     */
    private function nombre(string $modele): int
    {
        return $modele::query()->withoutGlobalScopes()->count();
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
}
