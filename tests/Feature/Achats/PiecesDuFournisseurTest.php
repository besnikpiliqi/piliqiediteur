<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EnregistrerAvoirFournisseurAction;
use App\Domain\Achats\Actions\JoindreDesPiecesAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\RetirerUnePieceAction;
use App\Domain\Achats\Actions\SupprimerFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PieceDeposee;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\FermerMagasinAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Les pièces du fournisseur jointes aux factures d'achat et aux avoirs
 * fournisseur saisis à la main (décision de l'éditeur du 03/11/2026, lot
 * EX, F1 = b) : la vraie facture ou la vraie note d'avoir, en PDF ou en
 * photo, rangée par magasin sous un nom tiré par l'application, avec son
 * nom d'origine et son empreinte ; un document saisi à la main sans pièce
 * s'enregistre et se valide, marqué « pièce du fournisseur manquante »
 * jusqu'à l'ajout de la première ; avant la validation une pièce se retire
 * ou se remplace, après elle on ne peut qu'en ajouter ; chaque geste
 * s'inscrit au journal d'audit. Une saisie à la main porte aussi la date de
 * la facture du fournisseur.
 */
class PiecesDuFournisseurTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF";

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00photo d'une facture";

    private const PNG = "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDRcapture";

    private Magasin $magasin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(PiecesDuFournisseur::DISQUE);
        $this->magasin = $this->ouvrirUneSociete(['nom' => 'Boutique du Port', 'code' => 'PORT']);
    }

    public function test_une_facture_saisie_a_la_main_exige_la_date_de_la_facture_du_fournisseur(): void
    {
        try {
            $this->saisir([], ['date_facture' => null]);
            $this->fail('Une facture saisie à la main sans la date de la facture du fournisseur est refusée.');
        } catch (LogicException $refus) {
            $this->assertSame(FactureAchat::REFUS_SANS_DATE, $refus->getMessage());
        }

        $this->assertSame(0, FactureAchat::query()->count());
        $parLApi = $this->saisir([], ['source' => SourceFactureAchat::Api, 'date_facture' => null]);
        $this->assertNull($parLApi->date_facture);

        $saisie = $this->saisir([], ['date_facture' => '2026-02-27']);
        $this->assertSame('2026-02-27', $saisie->date_facture);
        $this->expectExceptionMessage(FactureAchat::REFUS_SANS_DATE);
        app(ModifierFactureAchatAction::class)->handle(self::auteur(), $saisie, $saisie->fournisseur, [$this->ligne()], ['date_facture' => null]);
    }

    public function test_une_piece_pdf_se_joint_a_la_saisie_rangee_par_magasin_avec_son_nom_d_origine_et_son_empreinte(): void
    {
        $factureAchat = $this->saisir([new PieceDeposee('C:\\Factures\\Grossiste du Nord n°812.pdf', self::PDF)]);

        $piece = $factureAchat->pieces()->sole();
        $this->assertSame(['Grossiste du Nord n°812.pdf', 'application/pdf', strlen(self::PDF), hash('sha256', self::PDF)], [$piece->nom_origine, $piece->type_mime, $piece->taille, $piece->empreinte]);
        $this->assertMatchesRegularExpression('#^magasin-'.$this->magasin->id.'/[0-9a-f]{32}\.pdf$#', $piece->chemin);
        $this->assertSame(self::PDF, Storage::disk(PiecesDuFournisseur::DISQUE)->get($piece->chemin));
        $this->assertSame(self::auteur()->id, (int) $piece->ajoutee_par);
        $this->assertFalse($factureAchat->piece_manquante);
        $this->assertSame([[
            'document' => ['nature' => 'facture_achat', 'numero' => null, 'numero_fournisseur' => 'F-812'],
            'empreinte' => hash('sha256', self::PDF),
            'nom_origine' => 'Grossiste du Nord n°812.pdf',
        ]], $this->valeursAuditees(ActionAuditee::PieceDuFournisseurJointe));
        $this->assertSame([], $this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquantePosee));
    }

    public function test_sans_piece_la_facture_s_enregistre_et_se_valide_marquee_jusqu_a_l_ajout_de_la_premiere(): void
    {
        $produit = Produit::factory()->create(['taux_tva' => 20, 'suivi_stock' => true, 'stock_quantite' => 0]);
        $factureAchat = $this->saisir([], [], ['produit_id' => $produit->id, 'quantite' => 4, 'prix_unitaire_ht' => '2.50']);
        $this->assertTrue($factureAchat->piece_manquante);

        $validee = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $factureAchat);
        $this->assertTrue($validee->refresh()->piece_manquante);
        $this->assertSame(4, $produit->refresh()->stock_quantite);

        app(JoindreDesPiecesAction::class)->handle(self::auteur(), $validee, [new PieceDeposee('photo.jpg', self::JPEG)]);

        $this->assertFalse($validee->refresh()->piece_manquante);
        $designation = ['nature' => 'facture_achat', 'numero' => $validee->numero, 'numero_fournisseur' => 'F-812'];
        $this->assertSame([['document' => ['nature' => 'facture_achat', 'numero' => null, 'numero_fournisseur' => 'F-812']]], $this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquantePosee));
        $this->assertSame([['document' => $designation]], $this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquanteLevee));
        $this->assertSame('image/jpeg', $validee->pieces()->sole()->type_mime);
    }

    public function test_seuls_un_pdf_une_photo_jpeg_ou_png_sont_admis_d_apres_leur_contenu(): void
    {
        $factureAchat = $this->saisir([new PieceDeposee('scan.jpg', self::JPEG), new PieceDeposee('capture.png', self::PNG), new PieceDeposee('facture.pdf', "\xEF\xBB\xBF  ".self::PDF)]);

        $this->assertSame(['image/jpeg', 'image/png', 'application/pdf'], $factureAchat->pieces()->pluck('type_mime')->all());
        $this->assertSame(['jpg', 'png', 'pdf'], $factureAchat->pieces()->get()->map(fn (PieceDuFournisseur $piece): string => pathinfo($piece->chemin, PATHINFO_EXTENSION))->all());

        $this->assertRefus(sprintf(PiecesDuFournisseur::REFUS_TYPE, 'facture.pdf'), fn () => app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('facture.pdf', 'MZ un programme qui se dit PDF')]));
        $this->assertRefus(sprintf(PiecesDuFournisseur::REFUS_TYPE, 'page.html'), fn () => app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('page.html', '<html><body>%PDF-</body>')]));
        $this->assertSame(3, $factureAchat->pieces()->count());
    }

    public function test_une_piece_vide_ou_de_plus_de_10_mo_est_refusee_et_rien_ne_reste(): void
    {
        $this->assertRefus(sprintf(PiecesDuFournisseur::REFUS_VIDE, 'vide.pdf'), fn () => $this->saisir([new PieceDeposee('vide.pdf', '')]));
        $this->assertRefus(sprintf(PiecesDuFournisseur::REFUS_TROP_GROS, 'enorme.pdf'), fn () => $this->saisir([new PieceDeposee('bonne.pdf', self::PDF), new PieceDeposee('enorme.pdf', self::PDF.str_repeat('x', PiecesDuFournisseur::TAILLE_MAXIMALE_EN_OCTETS))]));

        $this->assertSame([0, 0], [FactureAchat::query()->count(), PieceDuFournisseur::query()->count()]);
        $this->assertSame([], Storage::disk(PiecesDuFournisseur::DISQUE)->allFiles());

        $tout = $this->saisir([new PieceDeposee('pile.pdf', str_pad(self::PDF, PiecesDuFournisseur::TAILLE_MAXIMALE_EN_OCTETS, ' '))]);
        $this->assertSame(PiecesDuFournisseur::TAILLE_MAXIMALE_EN_OCTETS, $tout->pieces()->sole()->taille);
    }

    public function test_un_document_porte_au_plus_dix_pieces_et_un_meme_fichier_une_seule_fois(): void
    {
        $factureAchat = $this->saisir(array_map(fn (int $page): PieceDeposee => new PieceDeposee("page-{$page}.pdf", self::PDF." page {$page}"), range(1, 9)));

        $this->assertRefus(sprintf(PiecesDuFournisseur::REFUS_DEJA_JOINTE, 'encore.pdf'), fn () => app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('encore.pdf', self::PDF.' page 3')]));
        $this->assertRefus(PiecesDuFournisseur::REFUS_TROP_DE_PIECES, fn () => app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('a.pdf', self::PDF.' a'), new PieceDeposee('b.pdf', self::PDF.' b')]));
        app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('dixieme.pdf', self::PDF.' page 10')]);
        $this->assertRefus(PiecesDuFournisseur::REFUS_TROP_DE_PIECES, fn () => app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('onzieme.pdf', self::PDF.' page 11')]));

        $this->assertSame(10, $factureAchat->pieces()->count());
        $this->assertCount(10, Storage::disk(PiecesDuFournisseur::DISQUE)->allFiles());
        $this->assertRefus(sprintf(PiecesDuFournisseur::REFUS_DEJA_JOINTE, 'double.pdf'), fn () => $this->saisir([new PieceDeposee('simple.pdf', self::PDF), new PieceDeposee('double.pdf', self::PDF)]));
    }

    public function test_une_piece_se_retire_ou_se_remplace_d_un_brouillon_et_son_fichier_s_efface(): void
    {
        $factureAchat = $this->saisir([new PieceDeposee('mauvaise.pdf', self::PDF.' mauvaise'), new PieceDeposee('bonne.jpg', self::JPEG)]);
        [$mauvaise, $bonne] = $factureAchat->pieces()->get()->all();

        app(RetirerUnePieceAction::class)->handle(self::auteur(), $mauvaise, new PieceDeposee('corrigee.png', self::PNG));

        $this->assertSame(['bonne.jpg', 'corrigee.png'], $factureAchat->pieces()->pluck('nom_origine')->all());
        Storage::disk(PiecesDuFournisseur::DISQUE)->assertMissing($mauvaise->chemin);
        $this->assertFalse($factureAchat->refresh()->piece_manquante);
        $this->assertSame([], $this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquantePosee));

        app(RetirerUnePieceAction::class)->handle(self::auteur(), $bonne);
        app(RetirerUnePieceAction::class)->handle(self::auteur(), $factureAchat->pieces()->sole());

        $this->assertSame(0, $factureAchat->pieces()->count());
        $this->assertSame([], Storage::disk(PiecesDuFournisseur::DISQUE)->allFiles());
        $this->assertTrue($factureAchat->refresh()->piece_manquante);
        $this->assertSame(['mauvaise.pdf', 'bonne.jpg', 'corrigee.png'], array_column($this->valeursAuditees(ActionAuditee::PieceDuFournisseurRetiree), 'nom_origine'));
        $this->assertCount(1, $this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquantePosee));
    }

    public function test_une_piece_d_une_facture_validee_ou_d_un_avoir_emis_ne_se_retire_jamais(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]));
        $avoir = $this->retourner($factureAchat, [new PieceDeposee('note.pdf', self::PDF.' avoir')]);

        foreach ([$factureAchat->pieces()->sole(), $avoir->pieces()->sole()] as $piece) {
            try {
                app(RetirerUnePieceAction::class)->handle(self::auteur(), $piece);
                $this->fail('Une pièce d\'un document validé ne se retire pas.');
            } catch (AuthorizationException) {
                $this->assertTrue(PieceDuFournisseur::query()->whereKey($piece->id)->exists());
            }

            try {
                app(PiecesDuFournisseur::class)->retirer(self::auteur(), $piece);
                $this->fail('Une pièce d\'un document validé ne se retire pas, même sans l\'écran.');
            } catch (LogicException $refus) {
                $this->assertSame(PiecesDuFournisseur::REFUS_RETRAIT, $refus->getMessage());
            }

            $this->assertRefusDeLaBase(fn () => DB::table('pieces_fournisseur')->where('id', $piece->id)->delete(), 'pieces_fournisseur_effacees_d_un_brouillon_seulement');
            Storage::disk(PiecesDuFournisseur::DISQUE)->assertExists($piece->chemin);
        }

        app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('page-2.pdf', self::PDF.' page 2')]);
        $this->assertSame(2, $factureAchat->pieces()->count());
    }

    public function test_la_base_refuse_une_piece_modifiee_ou_jointe_a_deux_documents_ou_a_aucun(): void
    {
        $factureAchat = $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]);
        $piece = $factureAchat->pieces()->sole();
        $avoir = AvoirFournisseur::factory()->create(['facture_achat_id' => $factureAchat->id]);

        $this->assertRefusDeLaBase(fn () => DB::table('pieces_fournisseur')->where('id', $piece->id)->update(['nom_origine' => 'autre.pdf']), 'pieces_fournisseur_jamais_modifiees');
        $ligne = ['uuid' => 'a3c8f0c4-3b5f-4b11-9a68-0f1f3b6d2c10', 'magasin_id' => $this->magasin->id, 'nom_origine' => 'x.pdf', 'type_mime' => 'application/pdf', 'taille' => 1, 'empreinte' => str_repeat('b', 64), 'chemin' => 'magasin-1/x.pdf'];
        $this->assertRefusDeLaBase(fn () => DB::table('pieces_fournisseur')->insert([...$ligne, 'facture_achat_id' => $factureAchat->id, 'avoir_fournisseur_id' => $avoir->id]), 'pieces_fournisseur_un_seul_document');
        $this->assertRefusDeLaBase(fn () => DB::table('pieces_fournisseur')->insert($ligne), 'pieces_fournisseur_un_seul_document');
        $this->assertSame('facture.pdf', $piece->refresh()->nom_origine);
    }

    public function test_un_achat_recu_par_l_api_n_est_jamais_marque_et_peut_recevoir_une_piece(): void
    {
        $factureAchat = $this->saisir([], ['source' => SourceFactureAchat::Api]);
        $this->assertFalse($factureAchat->piece_manquante);

        app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat, [new PieceDeposee('facturx.pdf', self::PDF)]);

        $this->assertSame(1, $factureAchat->pieces()->count());
        $this->assertSame([], [...$this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquantePosee), ...$this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquanteLevee)]);
    }

    public function test_un_avoir_fournisseur_porte_sa_note_d_avoir_ou_en_est_marque_a_son_emission(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->saisir([new PieceDeposee('facture.pdf', self::PDF)], [], ['quantite' => 5]));

        $avecSaNote = $this->retourner($factureAchat, [new PieceDeposee('note.pdf', self::PDF.' note')]);
        $sansSaNote = $this->retourner($factureAchat, []);

        $this->assertSame([false, true], [$avecSaNote->piece_manquante, $sansSaNote->refresh()->piece_manquante]);
        $this->assertSame(['note.pdf'], $avecSaNote->pieces()->pluck('nom_origine')->all());
        $this->assertSame([['document' => ['nature' => 'avoir_fournisseur', 'numero' => $sansSaNote->numero, 'numero_fournisseur' => 'AV-12']]], $this->valeursAuditees(ActionAuditee::PieceDuFournisseurManquantePosee));

        app(JoindreDesPiecesAction::class)->handle(self::auteur(), $sansSaNote, [new PieceDeposee('note-recue.jpg', self::JPEG)]);
        $this->assertFalse($sansSaNote->refresh()->piece_manquante);
    }

    public function test_une_demande_d_avoir_repetee_ne_joint_pas_deux_fois_sa_note_et_un_autre_fichier_est_refuse(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->saisir([], [], ['quantite' => 5]));
        $jeton = 'b5a1e3d2-8c4f-4a7e-9b61-2d0f7c3e4a95';

        $premier = $this->retourner($factureAchat, [new PieceDeposee('note.pdf', self::PDF)], $jeton);
        $repete = $this->retourner($factureAchat, [new PieceDeposee('note.pdf', self::PDF)], $jeton);

        $this->assertTrue($premier->is($repete));
        $this->assertSame([1, 1], [AvoirFournisseur::query()->count(), PieceDuFournisseur::query()->whereNotNull('avoir_fournisseur_id')->count()]);
        $this->expectException(LogicException::class);
        $this->retourner($factureAchat, [new PieceDeposee('note.pdf', self::PDF.' autre')], $jeton);
    }

    public function test_un_brouillon_supprime_retire_ses_pieces_et_leurs_fichiers(): void
    {
        $brouillon = $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]);

        app(SupprimerFactureAchatAction::class)->handle(self::auteur(), $brouillon);

        $this->assertSame([0, 0], [FactureAchat::query()->count(), PieceDuFournisseur::query()->count()]);
        $this->assertSame([], Storage::disk(PiecesDuFournisseur::DISQUE)->allFiles());
        $this->assertSame(['facture.pdf'], array_column($this->valeursAuditees(ActionAuditee::PieceDuFournisseurRetiree), 'nom_origine'));
    }

    public function test_joindre_exige_de_saisir_les_achats_et_retirer_un_brouillon(): void
    {
        $factureAchat = $this->saisir([]);
        $consultant = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW]);
        $acheteur = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::FACTURE_ACHAT_CREER]);

        try {
            app(JoindreDesPiecesAction::class)->handle($consultant, $factureAchat, [new PieceDeposee('facture.pdf', self::PDF)]);
            $this->fail('Sans le droit de saisir les achats, aucune pièce ne se joint.');
        } catch (AuthorizationException) {
            $this->assertSame(0, PieceDuFournisseur::query()->count());
        }

        $piece = app(JoindreDesPiecesAction::class)->handle($acheteur, $factureAchat, [new PieceDeposee('facture.pdf', self::PDF)])[0];
        $this->assertSame($acheteur->id, (int) $piece->ajoutee_par);

        $this->expectException(AuthorizationException::class);
        app(RetirerUnePieceAction::class)->handle($consultant, $piece);
    }

    public function test_joindre_a_un_avoir_emis_exige_de_creer_les_avoirs_fournisseur(): void
    {
        $avoir = $this->retourner(app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->saisir([])), []);
        $consultant = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW]);
        $retourneur = $this->membre($this->magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_CREER]);

        try {
            app(JoindreDesPiecesAction::class)->handle($consultant, $avoir, [new PieceDeposee('note.pdf', self::PDF)]);
            $this->fail('Sans le droit de créer les avoirs fournisseur, aucune pièce ne se joint à un avoir.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $avoir->pieces()->count());
        }

        app(JoindreDesPiecesAction::class)->handle($retourneur, $avoir, [new PieceDeposee('note.pdf', self::PDF)]);
        $this->assertSame(1, $avoir->pieces()->count());
    }

    public function test_le_cabinet_lie_joint_une_piece_s_il_saisit_les_achats_du_client(): void
    {
        $factureAchat = $this->saisir([]);
        $consultation = $this->collaborateurDuCabinetLie($this->magasin, nom: 'Paul Lefèvre');

        try {
            app(JoindreDesPiecesAction::class)->handle($consultation, $factureAchat, [new PieceDeposee('facture.pdf', self::PDF)]);
            $this->fail('Le cabinet qui consulte seulement ne joint aucune pièce.');
        } catch (AuthorizationException) {
            $this->assertSame(0, PieceDuFournisseur::query()->count());
        }

        $autreMagasin = $this->ouvrirUneSociete(['nom' => 'Atelier', 'code' => 'ATEL']);
        $saisie = $this->saisir([]);
        $autorisees = [...PermissionRegistry::parDefautDuCabinet($autreMagasin->societe->type_activite), PermissionRegistry::FACTURE_ACHAT_CREER];
        $saisisseur = $this->collaborateurDuCabinetLie($autreMagasin, $autorisees, 'Lucie Moreau');

        $piece = app(JoindreDesPiecesAction::class)->handle($saisisseur, $saisie, [new PieceDeposee('facture.pdf', self::PDF)])[0];
        $this->assertStringContainsString('Lucie Moreau', (string) $piece->ajoutee_par_nom);
        $this->assertFalse($saisie->refresh()->piece_manquante);
    }

    public function test_un_magasin_en_lecture_seule_ne_recoit_plus_de_piece(): void
    {
        $factureAchat = app(ValiderFactureAchatAction::class)->handle(self::auteur(), $this->saisir([]));
        app(FermerMagasinAction::class)->handle($this->proprietaireDe($this->magasin), $this->magasin->fresh(), MotifFermeture::Temporaire, 'password');

        $this->expectExceptionObject(new LogicException('Ce magasin est fermé : seules la consultation et les téléchargements restent possibles.'));
        app(JoindreDesPiecesAction::class)->handle(self::auteur(), $factureAchat->refresh(), [new PieceDeposee('facture.pdf', self::PDF)]);
    }

    public function test_une_piece_d_un_magasin_est_introuvable_dans_un_autre(): void
    {
        $factureAchat = $this->saisir([new PieceDeposee('facture.pdf', self::PDF)]);
        $piece = $factureAchat->pieces()->sole();
        $autre = $this->ouvrirUnAutreMagasin($this->magasin, ['nom' => 'Boutique de la Gare', 'code' => 'GARE']);

        app(TenantContext::class)->runAs($autre, function () use ($piece): void {
            $this->assertNull(PieceDuFournisseur::query()->parUuid($piece->uuid)->first());
            $this->assertSame(0, PieceDuFournisseur::query()->count());
        });
    }

    /**
     * Saisit à la main une facture d'achat du Grossiste du Nord, n° F-812,
     * datée du 27/02/2026, d'une ligne, avec ces pièces.
     *
     * @param  list<PieceDeposee>  $pieces
     * @param  array<string, mixed>  $attributs
     * @param  array<string, mixed>  $ligne
     */
    private function saisir(array $pieces, array $attributs = [], array $ligne = []): FactureAchat
    {
        return app(CreerFactureAchatAction::class)->handle(
            self::auteur(),
            Fournisseur::query()->firstOrCreate(['nom' => 'Grossiste du Nord']),
            [[...$this->ligne(), ...$ligne]],
            ['date_facture' => '2026-02-27', 'numero_fournisseur' => 'F-812', ...$attributs],
            null,
            $pieces,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function ligne(): array
    {
        $produit = Produit::query()->where('reference', 'CARNET')->first() ?? Produit::factory()->create(['reference' => 'CARNET', 'taux_tva' => 20, 'suivi_stock' => false]);

        return ['produit_id' => $produit->id, 'quantite' => 2, 'prix_unitaire_ht' => '3.00'];
    }

    /**
     * Retourne un article de la facture d'achat par un avoir saisi à la main,
     * avec ces pièces, comme l'écran.
     *
     * @param  list<PieceDeposee>  $pieces
     */
    private function retourner(FactureAchat $factureAchat, array $pieces, ?string $jeton = null): AvoirFournisseur
    {
        return app(EnregistrerAvoirFournisseurAction::class)->handle(self::auteur(), $factureAchat, [['ligne_achat_id' => $factureAchat->lignes()->first()->id, 'quantite' => 1]], 'Carnet abîmé', 'AV-12', $jeton, null, $pieces);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function valeursAuditees(ActionAuditee $action): array
    {
        return array_map(fn ($entree): array => $entree->nouvelles_valeurs, $this->entreesDAudit($this->magasin->societe, $action));
    }

    private function assertRefus(string $message, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Refus attendu : {$message}");
        } catch (InvalidArgumentException $refus) {
            $this->assertSame($message, $refus->getMessage());
        }
    }

    private function assertRefusDeLaBase(callable $operation, string $garde): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail("La base refuse : {$garde}");
        } catch (QueryException $refus) {
            $this->assertStringContainsString($garde, $refus->getMessage());
        }
    }
}
