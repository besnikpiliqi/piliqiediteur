<?php

namespace App\Domain\Fichiers;

use App\Domain\Fichiers\Enums\RaisonDuRefus;
use App\Domain\Fichiers\Exceptions\FichierRefuseException;
use ErrorException;
use GdImage;
use Throwable;

/**
 * Une photo reçue (JPEG ou PNG) est réenregistrée par PILIQI (décision de
 * l'éditeur du 07/11/2026, Q-FI2 = a) : la même image, sans rien de caché
 * ni aucune donnée personnelle cachée.
 *
 * - Ses dimensions se lisent dans son en-tête, avant tout décodage, et
 *   sont bornées (COTE_MAXIMAL pixels de côté, PIXELS_AU_PLUS pixels) :
 *   une image de quelques octets qui en occuperait des gigaoctets une fois
 *   décodée est refusée sans être décodée ; son en-tête doit être celui du
 *   type lu dans son contenu.
 * - Elle est entièrement décodée par l'extension GD : une image qui ne se
 *   décode pas est refusée.
 * - L'orientation de l'appareil (étiquette Orientation de l'EXIF d'un
 *   JPEG) est appliquée aux pixels, puisque l'EXIF ne reste pas.
 * - Elle est réenregistrée dans son format, un JPEG en qualité
 *   QUALITE_JPEG, un PNG avec sa transparence, sans aucune métadonnée
 *   (EXIF, position GPS, XMP, commentaires) ni donnée ajoutée après
 *   l'image : c'est ce fichier qui est conservé.
 */
final class ReenregistrementDesImages
{
    public const COTE_MAXIMAL = 16000;

    public const PIXELS_AU_PLUS = 40_000_000;

    public const QUALITE_JPEG = 90;

    /**
     * Ce que GD occupe en mémoire par pixel, au plus (une image en vraies
     * couleurs : quatre octets par pixel, plus ses lignes).
     */
    private const OCTETS_PAR_PIXEL = 5;

    /**
     * Le contenu réenregistré de cette photo de ce type.
     *
     * @throws FichierRefuseException
     */
    public function reenregistrer(PieceDeposee $piece, string $type): string
    {
        $taille = self::sansAvertissement(fn (): array|false => getimagesizefromstring($piece->contenu));
        $attendu = $type === 'image/png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        if (! is_array($taille) || $taille[2] !== $attendu || $taille[0] < 1 || $taille[1] < 1) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::ImageIllisible);
        }

        if ($taille[0] > self::COTE_MAXIMAL || $taille[1] > self::COTE_MAXIMAL || $taille[0] * $taille[1] > self::PIXELS_AU_PLUS) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::ImageTropGrande);
        }

        MemoireDePhp::prevoir($taille[0] * $taille[1] * self::OCTETS_PAR_PIXEL);
        $image = self::sansAvertissement(fn (): GdImage|false => imagecreatefromstring($piece->contenu));

        if (! $image instanceof GdImage || imagesx($image) !== $taille[0] || imagesy($image) !== $taille[1]) {
            throw FichierRefuseException::pour($piece, RaisonDuRefus::ImageIllisible);
        }

        $image = $type === 'image/jpeg' ? self::orienter($image, self::orientation($piece->contenu)) : $image;
        $contenu = self::enregistrer($image, $type);

        return $contenu !== '' ? $contenu : throw FichierRefuseException::pour($piece, RaisonDuRefus::ImageIllisible);
    }

    /**
     * L'étiquette Orientation (0x0112) de l'EXIF d'un JPEG, de 1 à 8 ; 1
     * (l'image telle qu'enregistrée) quand il n'y en a pas, ou qu'elle ne
     * se lit pas. L'EXIF se cherche dans les segments APP1 qui précèdent
     * l'image, chaque longueur contrôlée.
     */
    public static function orientation(string $jpeg): int
    {
        $longueur = strlen($jpeg);
        $position = 2;

        while ($position + 4 <= $longueur && $jpeg[$position] === "\xFF") {
            $marqueur = ord($jpeg[$position + 1]);

            if ($marqueur === 0xFF) {
                $position++;

                continue;
            }

            if ($marqueur === 0xD9 || $marqueur === 0xDA) {
                break;
            }

            $segment = self::entier($jpeg, $position + 2, 2, false);

            if ($segment < 2) {
                break;
            }

            if ($marqueur === 0xE1 && substr($jpeg, $position + 4, 6) === "Exif\0\0") {
                return self::orientationDeLExif(substr($jpeg, $position + 10, $segment - 8));
            }

            $position += 2 + $segment;
        }

        return 1;
    }

    /**
     * L'orientation lue dans l'en-tête TIFF de l'EXIF et son premier
     * répertoire (IFD0).
     */
    private static function orientationDeLExif(string $tiff): int
    {
        $ordre = substr($tiff, 0, 2);

        if (strlen($tiff) < 8 || ! in_array($ordre, ['II', 'MM'], true)) {
            return 1;
        }

        $petitBout = $ordre === 'II';

        if (self::entier($tiff, 2, 2, $petitBout) !== 42) {
            return 1;
        }

        $repertoire = self::entier($tiff, 4, 4, $petitBout);
        $nombre = self::entier($tiff, $repertoire, 2, $petitBout);

        for ($rang = 0; $rang < $nombre; $rang++) {
            $entree = $repertoire + 2 + $rang * 12;

            if ($entree + 12 > strlen($tiff)) {
                break;
            }

            if (self::entier($tiff, $entree, 2, $petitBout) === 0x0112) {
                $valeur = self::entier($tiff, $entree + 8, 2, $petitBout);

                return $valeur >= 1 && $valeur <= 8 ? $valeur : 1;
            }
        }

        return 1;
    }

    /**
     * L'entier non signé de 2 ou 4 octets à cette position, -1 au-delà de
     * la fin.
     */
    private static function entier(string $octets, int $position, int $taille, bool $petitBout): int
    {
        if ($position < 0 || $position + $taille > strlen($octets)) {
            return -1;
        }

        $format = $taille === 2 ? ($petitBout ? 'v' : 'n') : ($petitBout ? 'V' : 'N');
        $lu = unpack($format, substr($octets, $position, $taille));

        return is_array($lu) && is_int($lu[1]) ? $lu[1] : -1;
    }

    /**
     * Les pixels tournés ou retournés comme l'orientation le demande :
     * 2 miroir horizontal, 3 demi-tour, 4 miroir vertical, 5 transposée,
     * 6 quart de tour à droite, 7 transverse, 8 quart de tour à gauche
     * (imagerotate() tourne dans le sens inverse des aiguilles d'une
     * montre).
     */
    private static function orienter(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        if (in_array($orientation, [4, 5], true)) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $tournee = imagerotate($image, $angle, 0);

        return $tournee instanceof GdImage ? $tournee : $image;
    }

    /**
     * L'image enregistrée par GD dans son format, sans métadonnée : un JPEG
     * sans le commentaire qu'imagejpeg() y écrit d'office (voir
     * sansCommentaire()) ; un PNG n'a que ses morceaux d'image (IHDR, pHYs,
     * IDAT, IEND).
     */
    private static function enregistrer(GdImage $image, string $type): string
    {
        ob_start();

        try {
            if ($type === 'image/png') {
                imagealphablending($image, false);
                imagesavealpha($image, true);
                imagepng($image);
            } else {
                imageinterlace($image, false);
                imagejpeg($image, null, self::QUALITE_JPEG);
            }
        } finally {
            $contenu = (string) ob_get_clean();
        }

        return $type === 'image/jpeg' ? self::sansCommentaire($contenu) : $contenu;
    }

    /**
     * Ce JPEG sans ses segments de commentaire (COM, FF FE), comme celui
     * qu'imagejpeg() écrit d'office (« CREATOR: gd-jpeg … ») : la photo
     * conservée n'en porte aucun. Les segments se parcourent du début du
     * fichier jusqu'à celui de l'image (SOS), chaque longueur contrôlée.
     */
    private static function sansCommentaire(string $jpeg): string
    {
        $longueur = strlen($jpeg);
        $position = 2;
        $sansCommentaire = substr($jpeg, 0, 2);

        while ($position + 4 <= $longueur && $jpeg[$position] === "\xFF") {
            $marqueur = ord($jpeg[$position + 1]);
            $segment = self::entier($jpeg, $position + 2, 2, false);

            if ($marqueur === 0xD9 || $marqueur === 0xDA || $segment < 2) {
                break;
            }

            if ($marqueur !== 0xFE) {
                $sansCommentaire .= substr($jpeg, $position, 2 + $segment);
            }

            $position += 2 + $segment;
        }

        return $sansCommentaire.substr($jpeg, $position);
    }

    /**
     * Le résultat de cette lecture, ses avertissements (une image abîmée
     * en émet) tenus pour un échec.
     *
     * @template TResultat
     *
     * @param  callable(): TResultat  $lecture
     * @return TResultat|false
     */
    private static function sansAvertissement(callable $lecture): mixed
    {
        set_error_handler(static fn (): bool => throw new ErrorException('lecture de l\'image'));

        try {
            return $lecture();
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }
}
