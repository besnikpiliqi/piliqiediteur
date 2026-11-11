<?php

namespace App\Domain\Fichiers;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Le nom sous lequel un fichier reçu s'enregistre, s'affiche, se télécharge
 * et se range dans un ZIP (décision de l'éditeur du 07/11/2026, lot FI) :
 * jamais le nom tel que reçu, qui n'est gardé que comme donnée au journal
 * d'audit, mais son nom de base nettoyé, suivi de l'extension de son type
 * réel, lu dans son contenu (.pdf, .jpg, .png). Un fichier « facture.bat »
 * dont le contenu est un PDF devient « facture.pdf » : sous Windows, un
 * double-clic sur « facture.bat » exécuterait les commandes qu'il
 * contient.
 *
 * Le nettoyage, dans cet ordre :
 * - seul le nom de base reste (aucun chemin, « / » comme « \ ») ;
 * - les octets qui ne sont pas de l'UTF-8 deviennent « _ » ; les caractères
 *   de contrôle, invisibles et de sens d'écriture (catégories Unicode Cc,
 *   Cf, Zl et Zp : U+200B à U+200F, U+202A à U+202E, U+2066 à U+2069,
 *   U+FEFF…), qui déguisent une extension (« facture‮fdp.exe » s'affiche
 *   « facture exe.pdf »), sont retirés ; les caractères interdits dans un
 *   nom de fichier Windows (< > : " | ? *) deviennent « _ » ;
 * - l'extension finale est retirée, puis, si ce qui reste finit par
 *   l'extension du type réel, celle-ci aussi : « facture.pdf.exe » devient
 *   « facture » ; une extension est un point suivi d'au plus dix lettres
 *   et chiffres dont au moins une lettre (« rapport.2026 » garde son
 *   « .2026 ») ;
 * - les espaces au début et à la fin et les points finaux sont retirés, et
 *   la longueur est bornée à LONGUEUR_MAXIMALE caractères ;
 * - un nom réservé de Windows (CON, PRN, AUX, NUL, COM1 à COM9, LPT1 à
 *   LPT9, seul ou suivi d'un point) est préfixé de « _ » ;
 * - « pièce » quand il ne reste rien ;
 * - puis l'extension du type réel.
 */
final class NomDeFichier
{
    /**
     * La longueur maximale du nom de base, en caractères, sans
     * l'extension : un nom de fichier tient en 255 octets, même rangé dans
     * un ZIP derrière le numéro de son document.
     */
    public const LONGUEUR_MAXIMALE = 100;

    public const NOM_PAR_DEFAUT = 'pièce';

    /**
     * L'extension du fichier rangé, par type admis.
     */
    public const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    /**
     * Les autres écritures de l'extension d'un type, retirées comme elle.
     */
    private const AUTRES_EXTENSIONS = [
        'image/jpeg' => ['jpeg', 'jpe', 'jfif'],
    ];

    private const NOMS_RESERVES = '/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i';

    /**
     * Le nom sous lequel s'enregistre un fichier reçu sous ce nom, de ce
     * type.
     */
    public static function nettoyer(string $nomRecu, string $type): string
    {
        $extension = self::EXTENSIONS[$type] ?? throw new InvalidArgumentException("Type de fichier inconnu : {$type}.");
        $nom = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', mb_scrub(str_replace('\\', '/', $nomRecu), 'UTF-8'));
        $nom = str_replace("\u{FFFD}", '_', $nom);
        $nom = (string) preg_replace('#[<>:"|?*]#', '_', substr($nom, (int) strrpos('/'.$nom, '/')));
        $nom = self::sansExtension($nom, [$extension, ...(self::AUTRES_EXTENSIONS[$type] ?? [])]);
        $nom = self::borner($nom);

        if (preg_match(self::NOMS_RESERVES, $nom) === 1) {
            $nom = '_'.$nom;
        }

        return ($nom === '' ? self::NOM_PAR_DEFAUT : $nom).'.'.$extension;
    }

    /**
     * Un nom tel que reçu, comme il s'écrit dans un message : son seul nom
     * de base, sans caractère de contrôle, invisible ni de sens d'écriture,
     * borné à LONGUEUR_MAXIMALE caractères ; « pièce » quand il n'en reste
     * rien.
     */
    public static function pourUnMessage(string $nomRecu): string
    {
        $nom = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', '', mb_scrub(str_replace('\\', '/', $nomRecu), 'UTF-8'));
        $nom = self::borner(substr($nom, (int) strrpos('/'.$nom, '/')));

        return $nom === '' ? self::NOM_PAR_DEFAUT : $nom;
    }

    /**
     * La valeur de l'en-tête Content-Disposition qui fait télécharger ce
     * fichier en pièce jointe, jamais afficher : son nom en UTF-8
     * (filename*), et, pour les navigateurs qui ne le lisent pas, un nom en
     * ASCII sûr (filename) : lettres, chiffres, espaces, points, tirets,
     * soulignés et parenthèses.
     */
    public static function enPieceJointe(string $nom): string
    {
        return HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, str_replace(['/', '\\', '%'], '_', $nom), self::enAscii($nom));
    }

    /**
     * Ce nom réduit aux caractères ASCII sûrs.
     */
    public static function enAscii(string $nom): string
    {
        $ascii = trim((string) preg_replace('/[^A-Za-z0-9 ._()-]/', '_', Str::ascii($nom)), ' .');

        return $ascii === '' || str_starts_with($ascii, '.') ? 'fichier'.$ascii : $ascii;
    }

    /**
     * Le nom sans son extension finale, puis sans l'extension du type réel
     * qui la précédait.
     *
     * @param  list<string>  $extensionsDuType
     */
    private static function sansExtension(string $nom, array $extensionsDuType): string
    {
        $motif = '/\.(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{1,10}$/';

        if (preg_match($motif, $nom, $finale) !== 1) {
            return $nom;
        }

        $nom = substr($nom, 0, -strlen($finale[0]));

        if (in_array(strtolower(substr($finale[0], 1)), $extensionsDuType, true)) {
            return $nom;
        }

        foreach ($extensionsDuType as $extension) {
            if (str_ends_with(strtolower($nom), '.'.$extension)) {
                return substr($nom, 0, -strlen($extension) - 1);
            }
        }

        return $nom;
    }

    /**
     * Le nom sans espace au début ni à la fin, sans point final, borné à
     * LONGUEUR_MAXIMALE caractères.
     */
    private static function borner(string $nom): string
    {
        $nom = rtrim(trim($nom), '. ');

        return rtrim(trim(mb_substr($nom, 0, self::LONGUEUR_MAXIMALE)), '. ');
    }
}
