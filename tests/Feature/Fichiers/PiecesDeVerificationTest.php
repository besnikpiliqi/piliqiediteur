<?php

namespace Tests\Feature\Fichiers;

use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\PieceDeposee;
use App\Domain\Tenancy\Actions\DeposerUnePieceAction;
use App\Domain\Tenancy\Actions\EnvoyerCodeDeConfirmationAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\TypePiece;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Services\PiecesDeVerification;
use App\Domain\Tenancy\Services\TenantContext;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Integrations\Antivirus\ResultatDeLAntivirus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Les pièces déposées pour l'éditeur par une société dont les courriers
 * ne sont pas parvenus, face aux contrôles des fichiers reçus (décisions de
 * l'éditeur du 07/11/2026, lot FI) : un refus pour une raison de sécurité
 * s'inscrit au journal d'audit de la société, une menace prévient le
 * propriétaire ; à la consultation par l'éditeur (editeur:societe:pieces),
 * une pièce dont la dernière analyse a plus de 24 heures repasse par
 * l'antivirus, et une menace trouvée alors la bloque, gardée mais jamais
 * déchiffrée dans le dossier d'examen ; chaque pièce y porte l'extension
 * de son type réel ; un antivirus injoignable arrête l'examen.
 */
class PiecesDeVerificationTest extends TestCase
{
    use RefreshDatabase;

    private Societe $societe;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PiecesDeVerification::DISQUE);
        Notification::fake();
        config(['editeur.operateurs' => ['alice' => password_hash('secret-d-alice', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])]]);
        $this->societe = $this->societeDontLesCourriersNeSontPasParvenus();
        $this->proprietaire = $this->societe->proprietaire()->sole();
    }

    /**
     * Un PDF actif, puis le fichier de test EICAR, refusés : rien n'est
     * enregistré ni rangé, chaque refus s'inscrit au journal d'audit de la
     * société (type de la pièce, raison, ce qu'il contient, nom tel que
     * reçu, empreinte et taille du fichier reçu, jamais son contenu), et
     * seule la menace prévient le propriétaire.
     */
    public function test_un_refus_de_securite_s_inscrit_au_journal_d_audit_sans_rien_enregistrer(): void
    {
        $actif = FichiersDeTest::pdf('Extrait Kbis', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>');
        $eicar = AntivirusFactice::fichierDeTest();

        $this->assertRefus(RaisonDuRefus::PdfActif, fn () => $this->deposer(TypePiece::Kbis, new PieceDeposee('kbis.pdf', $actif)));
        $this->assertRefus(RaisonDuRefus::Menace, fn () => $this->deposer(TypePiece::Identite, new PieceDeposee("carte\u{202E}gpj.exe", $eicar)));
        $this->assertRefus(RaisonDuRefus::Vide, fn () => $this->deposer(TypePiece::Autre, new PieceDeposee('vide.pdf', '')));

        $this->assertSame(0, app(TenantContext::class)->runAsSociete($this->societe, fn (): int => PieceVerification::query()->count()));
        $this->assertSame([], Storage::disk(PiecesDeVerification::DISQUE)->allFiles());
        $this->assertSame([
            [
                'detail' => ['javascript', 'action_automatique'],
                'empreinte_recue' => hash('sha256', $actif),
                'nom_recu' => 'kbis.pdf',
                'raison' => 'pdf_actif',
                'taille_recue' => strlen($actif),
                'type_piece' => 'kbis',
            ],
            [
                'detail' => AntivirusFactice::MENACE,
                'empreinte_recue' => hash('sha256', $eicar),
                'nom_recu' => "carte\u{202E}gpj.exe",
                'raison' => 'menace',
                'taille_recue' => strlen($eicar),
                'type_piece' => 'identite',
            ],
        ], array_map(fn ($entree): ?array => $entree->nouvelles_valeurs, $this->entreesDAudit($this->societe, ActionAuditee::PieceDeVerificationRefusee)));
        $this->assertSame([$this->proprietaire->id], array_values(array_unique(array_map(fn ($entree): ?int => $entree->auteur_id, $this->entreesDAudit($this->societe, ActionAuditee::PieceDeVerificationRefusee)))));
        Notification::assertSentToTimes($this->proprietaire, AvisDeLAntivirus::class, 1);
        Notification::assertSentTo($this->proprietaire, AvisDeLAntivirus::class, fn (AvisDeLAntivirus $avis): bool => $avis->titre === 'Fichier refusé par l\'antivirus'
            && str_contains($avis->texte, AntivirusFactice::MENACE)
            && ! str_contains($avis->texte, "\u{202E}"));
    }

    /**
     * Une photo déposée sous le nom « photo.bat » se range sous le nom
     * « photo.jpg », réenregistrée : le journal d'audit garde le nom tel
     * que reçu, l'empreinte et la taille du fichier reçu, la pièce celle
     * du fichier conservé.
     */
    public function test_une_piece_acceptee_porte_le_nom_de_son_type_et_l_audit_le_fichier_recu(): void
    {
        $photo = FichiersDeTest::avecExif(FichiersDeTest::jpeg(), gps: true);

        $piece = $this->deposer(TypePiece::Identite, new PieceDeposee('photo.bat', $photo));

        $this->assertSame(['photo.jpg', 'image/jpeg'], [$piece->nom, $piece->type_mime]);
        $this->assertNotSame(hash('sha256', $photo), $piece->empreinte);
        $this->assertSame([
            'empreinte' => $piece->empreinte,
            'empreinte_recue' => hash('sha256', $photo),
            'nom' => 'photo.jpg',
            'nom_recu' => 'photo.bat',
            'taille_recue' => strlen($photo),
            'type_piece' => 'identite',
        ], $this->entreesDAudit($this->societe, ActionAuditee::PieceDeposee)[0]->nouvelles_valeurs);
    }

    /**
     * L'éditeur consulte les pièces plus de 24 heures après leur dépôt :
     * chacune repasse par l'antivirus ; reconnue comme une menace, la
     * pièce est bloquée, gardée, son blocage inscrit au journal d'audit et
     * le propriétaire prévenu, et elle n'est pas écrite dans le dossier
     * d'examen ; l'autre y est écrite sous l'extension de son type réel, et
     * sa dernière analyse avance. Une pièce bloquée ne repasse plus par
     * l'antivirus ; les pièces d'une autre société ne sont ni lues ni
     * analysées.
     */
    public function test_la_consultation_bloque_une_piece_reconnue_comme_une_menace_et_n_ecrit_que_les_autres(): void
    {
        $kbis = $this->deposer(TypePiece::Kbis, new PieceDeposee('Kbis.exe', FichiersDeTest::pdf('Extrait Kbis')));
        $identite = $this->deposer(TypePiece::Identite, new PieceDeposee('cni.bat', FichiersDeTest::jpeg()));
        $autre = $this->societeDontLesCourriersNeSontPasParvenus();
        $piecesDeLAutre = app(DeposerUnePieceAction::class)->handle($autre->proprietaire()->sole(), $autre, TypePiece::Kbis, new PieceDeposee('kbis.pdf', FichiersDeTest::pdf('Extrait Kbis')));
        $antivirus = $this->antivirusQuiReconnait(FichiersDeTest::pdf('Extrait Kbis'));
        $ecrits = $this->fichiersEcrits();
        $this->travelTo(self::aParis('2026-10-21 10:00:01'));

        $this->consulter()
            ->expectsOutputToContain('Pièce kbis bloquée par l\'antivirus')
            ->expectsQuestion('Appuyez sur Entrée une fois l\'examen terminé : le dossier sera effacé', '')
            ->assertSuccessful();

        $this->assertSame(["piece-{$identite->id}-identite.jpg"], array_keys($ecrits->fichiers));
        $this->assertSame($identite->empreinte, hash('sha256', $ecrits->fichiers["piece-{$identite->id}-identite.jpg"]));
        $this->assertSame(2, $antivirus->analyses);
        $kbis = $this->relire($kbis);
        $this->assertSame('Pdf.Exploit.Nouveau', $kbis->menace);
        $this->assertMemeInstant(self::aParis('2026-10-21 10:00:01'), $kbis->bloquee_le);
        Storage::disk(PiecesDeVerification::DISQUE)->assertExists((string) $kbis->chemin);
        $this->assertMemeInstant(self::aParis('2026-10-21 10:00:01'), $this->relire($identite)->analysee_le);
        $bloquee = $this->entreesDAudit($this->societe, ActionAuditee::PieceDeVerificationBloquee);
        $this->assertSame([[
            'empreinte' => $kbis->empreinte,
            'menace' => 'Pdf.Exploit.Nouveau',
            'nom' => 'Kbis.pdf',
            'type_piece' => 'kbis',
        ]], array_map(fn ($entree): ?array => $entree->nouvelles_valeurs, $bloquee));
        $this->assertNull($bloquee[0]->auteur_id);
        $this->assertSame(['identite'], $this->entreesDAudit($this->societe, ActionAuditee::PiecesConsultees)[0]->nouvelles_valeurs['pieces']);
        Notification::assertSentToTimes($this->proprietaire, AvisDeLAntivirus::class, 1);
        $this->assertMemeInstant(self::aParis('2026-10-20 10:00:00'), app(TenantContext::class)->runAsSociete($autre, fn () => PieceVerification::query()->findOrFail($piecesDeLAutre->id)->analysee_le));

        $this->consulter()
            ->expectsOutputToContain('Pièce kbis bloquée par l\'antivirus')
            ->expectsQuestion('Appuyez sur Entrée une fois l\'examen terminé : le dossier sera effacé', '')
            ->assertSuccessful();
        $this->assertSame(2, $antivirus->analyses, 'Une pièce bloquée, ou analysée il y a moins de 24 heures, ne repasse pas par l\'antivirus.');
        Notification::assertSentToTimes($this->proprietaire, AvisDeLAntivirus::class, 1);
    }

    /**
     * Un antivirus injoignable arrête l'examen d'une pièce dont la
     * dernière analyse a plus de 24 heures : rien n'est écrit, rien n'est
     * bloqué, la consultation n'est pas inscrite.
     */
    public function test_un_antivirus_injoignable_arrete_l_examen(): void
    {
        $kbis = $this->deposer(TypePiece::Kbis, new PieceDeposee('kbis.pdf', FichiersDeTest::pdf('Extrait Kbis')));
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
        $ecrits = $this->fichiersEcrits();
        $this->travelTo(self::aParis('2026-10-21 10:00:01'));

        $this->consulter()->expectsOutputToContain('L\'antivirus ne répond pas')->assertFailed();

        $this->assertSame([], $ecrits->fichiers);
        $this->assertSame([], PiecesDeVerification::dossiersDExamen());
        $this->assertNull($this->relire($kbis)->bloquee_le);
        $this->assertSame([], $this->entreesDAudit($this->societe, ActionAuditee::PiecesConsultees));
    }

    private function deposer(TypePiece $type, PieceDeposee $depot): PieceVerification
    {
        return app(DeposerUnePieceAction::class)->handle($this->proprietaire, $this->societe, $type, $depot);
    }

    private function relire(PieceVerification $piece): PieceVerification
    {
        return app(TenantContext::class)->runAsSociete($this->societe, fn (): PieceVerification => PieceVerification::query()->findOrFail($piece->id));
    }

    /**
     * Une inscription dont les deux courriers ne sont pas parvenus : le
     * nouveau courrier demandé le 20 octobre à 10 h, à l'heure où l'horloge
     * reste figée ; son propriétaire peut déposer des pièces pour
     * l'éditeur.
     */
    private function societeDontLesCourriersNeSontPasParvenus(): Societe
    {
        $this->travelTo(self::aParis('2026-10-05 10:00:00'));
        $societe = $this->inscrireUneSociete();
        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        app(EnvoyerCodeDeConfirmationAction::class)->nouvelEnvoi($societe->proprietaire, $societe);
        app(TenantContext::class)->clear();

        return $societe->fresh();
    }

    /**
     * editeur:societe:pieces sur la société, lancée par l'opératrice
     * alice.
     */
    private function consulter(): PendingCommand
    {
        return $this->artisan('editeur:societe:pieces', [
            'societe' => $this->societe->id,
            '--justification' => 'Examen du dossier de vérification par l\'éditeur',
            '--piece' => 'DOSSIER-2026-0001',
            '--confirmer' => $this->societe->siren,
        ])
            ->expectsQuestion('Nom de l\'opérateur', 'alice')
            ->expectsQuestion('Secret de l\'opérateur', 'secret-d-alice');
    }

    /**
     * Un antivirus qui ne reconnaît comme une menace que ce contenu, et
     * compte ses analyses.
     */
    private function antivirusQuiReconnait(string $menace): object
    {
        $antivirus = new class($menace) implements Antivirus
        {
            public int $analyses = 0;

            public function __construct(private readonly string $menace) {}

            public function analyser(string $contenu): ResultatDeLAntivirus
            {
                $this->analyses++;

                return $contenu === $this->menace ? ResultatDeLAntivirus::menace('Pdf.Exploit.Nouveau') : ResultatDeLAntivirus::sain();
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
     * Les fichiers que la consultation écrit dans le dossier d'examen,
     * chacun sous son nom, tels qu'écrits : le dossier s'efface à la fin
     * de l'examen.
     */
    private function fichiersEcrits(): object
    {
        $ecrits = new class
        {
            /** @var array<string, string> */
            public array $fichiers = [];
        };
        File::partialMock()->shouldReceive('put')->andReturnUsing(function (string $chemin, string $contenu) use ($ecrits): int|false {
            $ecrits->fichiers[basename($chemin)] = $contenu;

            return file_put_contents($chemin, $contenu);
        });

        return $ecrits;
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
