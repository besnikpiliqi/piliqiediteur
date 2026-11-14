<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Support\HtmlString;

/**
 * Une ligne d'un e-mail de notification (MailMessage::line()) qui porte un
 * texte venu d'ailleurs : le nom d'un fichier reçu, la menace que nomme
 * l'antivirus, le refus d'un serveur de messagerie, le nom ou le motif
 * qu'un client tape en répondant à un devis. Laravel écrit ces lignes dans
 * un modèle Markdown, que CommonMark lit ensuite : sans précaution, un
 * texte « [Vérifier](https://…) » y deviendrait un lien, « **…** » du gras
 * et « # … » un titre. Le texte se lit tel qu'il a été écrit, sur une seule
 * ligne : ses lignes se réunissent d'abord, chacune rognée, les lignes
 * vides retirées, comme MailMessage::line() le fait d'une chaîne simple
 * (une ligne vide suivie d'un retrait ferait sinon un bloc de code, hors
 * de la phrase de l'avis, où CommonMark laisse les références en clair) ;
 * puis il est échappé pour le HTML, et chaque caractère que Markdown lit
 * comme une structure écrit en référence numérique, que CommonMark affiche
 * comme ce caractère sans jamais le lire comme une structure. La version
 * en texte de l'e-mail, dont Laravel décode les références, garde le texte
 * tel quel, sur sa ligne.
 */
final class LigneDeCourriel
{
    /**
     * Les caractères que Markdown (CommonMark et ses tableaux) lit comme
     * une structure, chacun avec sa référence numérique ; l'apostrophe que
     * htmlspecialchars() a déjà écrite « &#039; » reste telle quelle (strtr()
     * essaie d'abord les clés les plus longues).
     */
    private const STRUCTURES = [
        '&#039;' => '&#039;',
        '\\' => '&#92;',
        '`' => '&#96;',
        '*' => '&#42;',
        '_' => '&#95;',
        '{' => '&#123;',
        '}' => '&#125;',
        '[' => '&#91;',
        ']' => '&#93;',
        '(' => '&#40;',
        ')' => '&#41;',
        '#' => '&#35;',
        '+' => '&#43;',
        '-' => '&#45;',
        '.' => '&#46;',
        '!' => '&#33;',
        '|' => '&#124;',
        '~' => '&#126;',
        '=' => '&#61;',
    ];

    public static function de(string $texte): HtmlString
    {
        $lignes = array_filter(array_map(trim(...), explode("\n", str_replace(["\r\n", "\r"], "\n", $texte))), fn (string $ligne): bool => $ligne !== '');

        return new HtmlString(strtr(htmlspecialchars(implode(' ', $lignes), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8'), self::STRUCTURES));
    }
}
