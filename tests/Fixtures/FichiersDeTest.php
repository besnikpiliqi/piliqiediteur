<?php

namespace Tests\Fixtures;

use GdImage;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Les fichiers que les tests déposent (lot FI) : de vrais PDF, que qpdf lit
 * en entier, fabriqués objet par objet avec leur table des objets exacte,
 * et de vraies photos JPEG et PNG, dessinées par GD, avec, au besoin, un
 * EXIF écrit à la main (orientation, position GPS) ; jamais un fichier
 * d'un autre test ni du hasard.
 */
final class FichiersDeTest
{
    /**
     * Un PDF d'une page qui porte ce texte : son contenu, et donc son
     * empreinte, change avec lui.
     *
     * @param  string  $catalogue  ce que le catalogue du document porte en plus de ses pages (une action d'ouverture, des fichiers joints)
     * @param  list<string>  $autresObjets  des objets de plus, numérotés à partir de 5
     */
    public static function pdf(string $texte = 'Facture du fournisseur', string $catalogue = '', array $autresObjets = []): string
    {
        $flux = 'BT /F1 12 Tf 72 720 Td ('.self::echapper($texte).') Tj ET';

        return self::assembler([
            "<< /Type /Catalog /Pages 2 0 R {$catalogue} >>",
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> >>',
            '<< /Length '.strlen($flux)." >>\nstream\n{$flux}\nendstream",
            ...$autresObjets,
        ]);
    }

    /**
     * Un PDF valide d'exactement cette taille en octets : le texte de sa
     * page, puis un flux de remplissage à sa taille.
     */
    public static function pdfDeTaille(int $taille, string $texte = 'Facture du fournisseur'): string
    {
        $remplissage = 0;

        do {
            $pdf = self::pdf($texte, '', ['<< /Length '.$remplissage." >>\nstream\n".str_repeat('x', $remplissage)."\nendstream"]);
            $remplissage += $taille - strlen($pdf);
        } while (strlen($pdf) !== $taille);

        return $pdf;
    }

    /**
     * Un PDF fait de ces objets, numérotés à partir de 1, dont le premier
     * est le catalogue, avec sa table des objets et son dictionnaire de
     * fin exacts.
     *
     * @param  list<string>  $objets
     */
    public static function assembler(array $objets, string $finEnPlus = ''): string
    {
        $pdf = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $positions = [];

        foreach ($objets as $rang => $objet) {
            $positions[] = strlen($pdf);
            $pdf .= ($rang + 1)." 0 obj\n{$objet}\nendobj\n";
        }

        $table = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objets) + 1)."\n0000000000 65535 f \n";

        foreach ($positions as $position) {
            $pdf .= sprintf("%010d 00000 n \n", $position);
        }

        return $pdf."trailer\n<< /Size ".(count($objets) + 1)." /Root 1 0 R {$finEnPlus} >>\nstartxref\n{$table}\n%%EOF\n";
    }

    /**
     * Ce PDF suivi d'une mise à jour incrémentale qui remplace ou ajoute
     * ces objets (numéro => contenu), avec sa propre table des objets et
     * son lien vers la précédente (/Prev) ; $avantLesObjets s'écrit entre
     * la fin du PDF et le premier objet de la mise à jour.
     *
     * @param  array<int, string>  $objets
     */
    public static function miseAJour(string $pdf, array $objets, string $avantLesObjets = ''): string
    {
        [$precedente, $taille, $racine] = self::finDuPdf($pdf);
        $pdf .= $avantLesObjets;
        $positions = [];

        foreach ($objets as $numero => $objet) {
            $positions[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$objet}\nendobj\n";
        }

        $table = strlen($pdf);
        $pdf .= "xref\n";

        foreach ($positions as $numero => $position) {
            $pdf .= "{$numero} 1\n".sprintf("%010d 00000 n \n", $position);
        }

        $nouvelleTaille = max($taille, max(array_keys($objets)) + 1);

        return $pdf."trailer\n<< /Size {$nouvelleTaille} /Root {$racine} /Prev {$precedente} >>\nstartxref\n{$table}\n%%EOF\n";
    }

    /**
     * Ce PDF suivi d'une mise à jour incrémentale qui ajoute ces objets
     * (numéro => contenu) et les liste dans un flux de table des objets
     * (/Type /XRef, sans compression), comme l'écrivent les logiciels
     * récents, lié à la table précédente (/Prev).
     *
     * @param  array<int, string>  $objets
     */
    public static function miseAJourParFlux(string $pdf, array $objets): string
    {
        [$precedente, $taille, $racine] = self::finDuPdf($pdf);
        $positions = [];

        foreach ($objets as $numero => $objet) {
            $positions[$numero] = strlen($pdf);
            $pdf .= "{$numero} 0 obj\n{$objet}\nendobj\n";
        }

        $numeroDeLaTable = max($taille, max(array_keys($objets)) + 1);
        $positions[$numeroDeLaTable] = strlen($pdf);
        ksort($positions);
        $entrees = implode('', array_map(fn (int $position): string => "\x01".pack('N', $position)."\x00\x00", $positions));
        $index = implode(' ', array_map(fn (int $numero): string => "{$numero} 1", array_keys($positions)));
        $pdf .= "{$numeroDeLaTable} 0 obj\n<< /Type /XRef /Size ".($numeroDeLaTable + 1)." /W [1 4 2] /Index [{$index}] /Root {$racine} /Prev {$precedente} /Length ".strlen($entrees)." >>\nstream\n{$entrees}\nendstream\nendobj\n";

        return $pdf."startxref\n{$positions[$numeroDeLaTable]}\n%%EOF\n";
    }

    /**
     * La position de la dernière table des objets d'un PDF, le nombre
     * d'objets qu'annonce son dictionnaire de fin (/Size) et son catalogue
     * (/Root).
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private static function finDuPdf(string $pdf): array
    {
        preg_match_all('/startxref\s+(\d+)\s+%%EOF/', $pdf, $precedentes);
        preg_match_all('/\/Size (\d+)/', $pdf, $tailles);
        preg_match_all('/\/Root (\d+ \d+ R)/', $pdf, $racines);

        return [(int) end($precedentes[1]), max(array_map(intval(...), $tailles[1])), (string) end($racines[1])];
    }

    /**
     * Une archive ZIP de ces fichiers (nom => contenu).
     *
     * @param  array<string, string>  $fichiers
     */
    public static function archiveZip(array $fichiers): string
    {
        $chemin = (string) tempnam(sys_get_temp_dir(), 'piliqi-test-');
        $archive = new ZipArchive;
        $archive->open($chemin, ZipArchive::OVERWRITE);

        foreach ($fichiers as $nom => $contenu) {
            $archive->addFromString($nom, $contenu);
        }

        $archive->close();

        try {
            return (string) file_get_contents($chemin);
        } finally {
            @unlink($chemin);
        }
    }

    /**
     * Un PDF valide d'une page qui porte ce nombre d'annotations de texte,
     * chacune un objet : une longue description pour qpdf.
     */
    public static function pdfDAnnotations(int $nombre): string
    {
        $references = implode(' ', array_map(fn (int $rang): string => ($rang + 5).' 0 R', range(0, $nombre - 1)));
        $annotations = array_map(fn (int $rang): string => "<< /Type /Annot /Subtype /Text /Rect [0 0 10 10] /Contents (note {$rang}) >>", range(0, $nombre - 1));

        return self::assembler([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Annots [{$references}] >>",
            "<< /Length 0 >>\nstream\n\nendstream",
            ...$annotations,
        ]);
    }

    /**
     * Un PDF valide, de quelques centaines de kilo-octets, qui porte ce
     * nombre de petits objets rangés par qpdf dans des flux d'objets
     * compressés : un petit fichier, une description démesurée.
     */
    public static function pdfDObjetsCompresses(int $nombre): string
    {
        $references = implode(' ', array_map(fn (int $rang): string => ($rang + 5).' 0 R', range(0, $nombre - 1)));

        return self::parQpdf(self::assembler([
            "<< /Type /Catalog /Pages 2 0 R /Liste [{$references}] >>",
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>',
            "<< /Length 0 >>\nstream\n\nendstream",
            ...array_fill(0, $nombre, '<< /A [1 2 3 4 5 6 7 8 9 10] /B /C >>'),
        ]), ['--object-streams=generate']);
    }

    /**
     * Ce PDF réécrit par qpdf avec ces options : ses objets rangés dans des
     * flux d'objets compressés (--object-streams=generate), ou chiffré
     * (--encrypt …).
     *
     * @param  list<string>  $options
     */
    public static function parQpdf(string $pdf, array $options): string
    {
        $entree = (string) tempnam(sys_get_temp_dir(), 'piliqi-test-');
        $sortie = $entree.'-sortie';
        file_put_contents($entree, $pdf);

        try {
            $processus = new Process(['qpdf', $entree, ...$options, $sortie]);
            $processus->mustRun();

            return (string) file_get_contents($sortie);
        } finally {
            @unlink($entree);
            @unlink($sortie);
        }
    }

    /**
     * Le flux d'un objet, compressé (FlateDecode).
     */
    public static function fluxCompresse(string $contenu, string $dictionnaire = ''): string
    {
        $compresse = (string) gzcompress($contenu);

        return '<< /Filter /FlateDecode /Length '.strlen($compresse)." {$dictionnaire} >>\nstream\n{$compresse}\nendstream";
    }

    /**
     * Une photo JPEG de cette taille, sans aucune métadonnée, dessinée par
     * GD : le coin en haut à gauche en rouge, le reste en bleu.
     */
    public static function jpeg(int $largeur = 40, int $hauteur = 30): string
    {
        return self::dessiner($largeur, $hauteur, fn (GdImage $image) => imagejpeg($image, null, 95));
    }

    /**
     * Une image PNG de cette taille, dessinée comme jpeg().
     */
    public static function png(int $largeur = 40, int $hauteur = 30): string
    {
        return self::dessiner($largeur, $hauteur, fn (GdImage $image) => imagepng($image));
    }

    /**
     * Ce JPEG avec un EXIF écrit juste après son début : l'orientation de
     * l'appareil et, au besoin, la position GPS de la photo (48° 51' 24" N,
     * 2° 21' 7" E : Paris) et le modèle du téléphone.
     */
    public static function avecExif(string $jpeg, int $orientation = 1, bool $gps = false): string
    {
        $entrees = [[0x0110, 2, 'PiliqiPhone 12'], [0x0112, 3, $orientation]];

        if ($gps) {
            $entrees[] = [0x8825, 4, null];
        }

        $ifd0 = self::repertoire($entrees, 8, $pointeurGps);
        $tiff = "MM\x00\x2A\x00\x00\x00\x08".$ifd0;

        if ($gps) {
            $tiff = substr_replace($tiff, pack('N', strlen($tiff)), $pointeurGps, 4);
            $tiff .= self::repertoire([
                [0x0001, 2, 'N'],
                [0x0002, 5, [[48, 1], [51, 1], [24, 1]]],
                [0x0003, 2, 'E'],
                [0x0004, 5, [[2, 1], [21, 1], [7, 1]]],
            ], strlen($tiff));
        }

        $segment = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
    }

    /**
     * Les marqueurs des segments d'un JPEG, du début du fichier jusqu'à
     * celui de l'image (SOS, FF DA) compris : 0xE0 pour APP0 (JFIF), 0xE1
     * pour APP1 (EXIF, XMP), 0xED pour APP13 (IPTC), 0xFE pour un
     * commentaire…
     *
     * @return list<int>
     */
    public static function segmentsJpeg(string $jpeg): array
    {
        $marqueurs = [];
        $position = 2;

        while ($position + 4 <= strlen($jpeg) && $jpeg[$position] === "\xFF") {
            $marqueurs[] = ord($jpeg[$position + 1]);

            if ($marqueurs[array_key_last($marqueurs)] === 0xDA) {
                break;
            }

            $position += 2 + (int) unpack('n', substr($jpeg, $position + 2, 2))[1];
        }

        return $marqueurs;
    }

    /**
     * Les types des morceaux d'une image PNG, dans l'ordre (IHDR, IDAT,
     * tEXt, eXIf…).
     *
     * @return list<string>
     */
    public static function morceauxPng(string $png): array
    {
        $morceaux = [];
        $position = 8;

        while ($position + 8 <= strlen($png)) {
            $morceaux[] = substr($png, $position + 4, 4);
            $position += 12 + (int) unpack('N', substr($png, $position, 4))[1];
        }

        return $morceaux;
    }

    /**
     * L'en-tête seul d'une image PNG de ces dimensions, sans pixel : ce
     * que lit getimagesizefromstring(), avant tout décodage.
     */
    public static function enTetePng(int $largeur, int $hauteur): string
    {
        $entete = 'IHDR'.pack('NNCCCCC', $largeur, $hauteur, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n".pack('N', 13).$entete.pack('N', crc32($entete));
    }

    /**
     * La couleur du pixel (x, y) d'une image, « rouge » ou « bleu » (ou
     * « autre ») : où le coin rouge a fini une fois l'image tournée.
     */
    public static function couleur(string $image, int $x, int $y): string
    {
        $gd = imagecreatefromstring($image);

        if (! $gd instanceof GdImage) {
            throw new RuntimeException('Image illisible.');
        }

        $couleur = imagecolorat($gd, $x, $y);
        [$rouge, $bleu] = [($couleur >> 16) & 0xFF, $couleur & 0xFF];

        return match (true) {
            $rouge > 200 && $bleu < 80 => 'rouge',
            $bleu > 200 && $rouge < 80 => 'bleu',
            default => 'autre',
        };
    }

    /**
     * @param  callable(GdImage): mixed  $enregistrer
     */
    private static function dessiner(int $largeur, int $hauteur, callable $enregistrer): string
    {
        $image = imagecreatetruecolor($largeur, $hauteur);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('Image impossible à créer.');
        }

        imagefill($image, 0, 0, (int) imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, intdiv($largeur, 4), intdiv($hauteur, 4), (int) imagecolorallocate($image, 255, 0, 0));
        ob_start();
        $enregistrer($image);

        return (string) ob_get_clean();
    }

    /**
     * Un répertoire TIFF (IFD) en ordre « MM », placé à cette position du
     * TIFF, ses valeurs longues rangées après lui ; une entrée de type 4
     * sans valeur réserve un pointeur, dont la position revient par
     * $pointeur.
     *
     * @param  list<array{0: int, 1: int, 2: mixed}>  $entrees
     */
    private static function repertoire(array $entrees, int $position, ?int &$pointeur = null): string
    {
        $suite = $position + 2 + count($entrees) * 12 + 4;
        $repertoire = pack('n', count($entrees));
        $donnees = '';

        foreach ($entrees as [$etiquette, $type, $valeur]) {
            $repertoire .= pack('nn', $etiquette, $type);

            if ($type === 3) {
                $repertoire .= pack('N', 1).pack('n', $valeur)."\x00\x00";
            } elseif ($type === 4) {
                $pointeur = $position + strlen($repertoire) + 4;
                $repertoire .= pack('N', 1).pack('N', 0);
            } elseif ($type === 2) {
                $texte = $valeur."\x00";

                if (strlen($texte) <= 4) {
                    $repertoire .= pack('N', strlen($texte)).str_pad($texte, 4, "\x00");
                } else {
                    $repertoire .= pack('N', strlen($texte)).pack('N', $suite + strlen($donnees));
                    $donnees .= $texte;
                }
            } else {
                $repertoire .= pack('N', count($valeur)).pack('N', $suite + strlen($donnees));

                foreach ($valeur as [$numerateur, $denominateur]) {
                    $donnees .= pack('NN', $numerateur, $denominateur);
                }
            }
        }

        return $repertoire.pack('N', 0).$donnees;
    }

    /**
     * Une chaîne littérale de PDF : parenthèses et barres obliques
     * inverses échappées.
     */
    private static function echapper(string $texte): string
    {
        return strtr($texte, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }
}
