<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Cabinets\Notifications\AvisDuCabinet;
use App\Domain\Tenancy\Notifications\AvisDeLAntivirus;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Support\LigneDeCourriel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Le texte venu d'ailleurs qu'un avis met dans un e-mail (le nom d'un
 * fichier reçu, la menace nommée par l'antivirus, le refus d'un serveur de
 * messagerie, voir LigneDeCourriel) : Laravel écrit l'e-mail en Markdown,
 * mais ce texte s'y lit tel qu'il a été écrit, sur une seule ligne, sans
 * gras, sans lien, sans titre ni code, dans la version HTML comme dans la
 * version en texte.
 */
class LigneDeCourrielTest extends TestCase
{
    use RefreshDatabase;

    private const PIEGE = "Le fichier « **Urgent** [Vérifier](https://evil.example/connexion) `rm` _vite_.pdf » est refusé.\n# Votre compte est bloqué\n1. <b>l'antivirus</b> & co | a = b ~ c !";

    /**
     * Le piège tel que l'e-mail le donne à lire : ses lignes réunies en
     * une seule, comme MailMessage::line() le fait d'une chaîne simple.
     */
    private const PIEGE_SUR_UNE_LIGNE = "Le fichier « **Urgent** [Vérifier](https://evil.example/connexion) `rm` _vite_.pdf » est refusé. # Votre compte est bloqué 1. <b>l'antivirus</b> & co | a = b ~ c !";

    public function test_le_texte_d_un_avis_se_lit_tel_quel_dans_l_e_mail(): void
    {
        $societe = $this->ouvrirUneSociete()->societe;
        $proprietaire = $societe->proprietaire()->firstOrFail();

        foreach ([
            new AvisDeLAntivirus($societe, 'Fichier refusé par l\'antivirus', self::PIEGE),
            new AvisDuCabinet($societe, 'E-mail non parti', self::PIEGE, parCourriel: true),
            new AvisDeLEquipe($societe, 'Accès redonné', self::PIEGE, parCourriel: true),
        ] as $avis) {
            $courriel = $avis->toMail($proprietaire);
            $html = (string) $courriel->render();

            $this->assertDoesNotMatchRegularExpression('#<(strong|em|code|ol|li|b)\b#i', $html, $avis::class);
            $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*evil\.example#i', $html, $avis::class);
            $this->assertDoesNotMatchRegularExpression('#<h1\b[^>]*>\s*Votre compte#i', $html, $avis::class);
            $this->assertStringContainsString('« **Urgent** [Vérifier](https://evil.example/connexion) `rm` _vite_.pdf »', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5), $avis::class);
            $this->assertStringContainsString(self::PIEGE_SUR_UNE_LIGNE, $this->versionEnTexte($courriel), $avis::class);
        }
    }

    /**
     * Une ligne vide suivie d'un retrait de quatre espaces ou d'une
     * tabulation ferait, en Markdown, un bloc de code : le texte sortirait
     * de la phrase de l'avis, écrit comme si PILIQI l'avait rédigé, et ses
     * caractères se liraient en références (« &#46; »), que CommonMark ne
     * décode pas dans un bloc de code. Ses lignes se réunissent en une
     * seule, la ponctuation lue telle quelle.
     */
    public function test_un_texte_sur_plusieurs_lignes_se_lit_sur_une_seule_ligne_sans_bloc_de_code(): void
    {
        $societe = $this->ouvrirUneSociete()->societe;
        $proprietaire = $societe->proprietaire()->firstOrFail();

        foreach ([
            "Motif : « Trop cher.\n\n    Votre compte est bloqué : rappelez le 01-23-45-67-89. »" => 'Motif : « Trop cher. Votre compte est bloqué : rappelez le 01-23-45-67-89. »',
            "Motif : « Trop cher.\r\n\r\n\tVotre compte est bloqué.\r\t- Payez ici. »" => 'Motif : « Trop cher. Votre compte est bloqué. - Payez ici. »',
        ] as $texte => $surUneLigne) {
            foreach ([
                new AvisDeLAntivirus($societe, 'Fichier refusé par l\'antivirus', $texte),
                new AvisDuCabinet($societe, 'E-mail non parti', $texte, parCourriel: true),
                new AvisDeLEquipe($societe, 'Accès redonné', $texte, parCourriel: true),
            ] as $avis) {
                $courriel = $avis->toMail($proprietaire);
                $html = (string) $courriel->render();
                $lu = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

                $this->assertDoesNotMatchRegularExpression('#<(pre|code)\b#i', $html, $avis::class);
                $this->assertStringContainsString($surUneLigne, $lu, $avis::class);
                $this->assertStringNotContainsString('&#', $lu, $avis::class);
                $this->assertStringContainsString($surUneLigne, $this->versionEnTexte($courriel), $avis::class);
            }
        }
    }

    public function test_une_ligne_sans_rien_de_particulier_reste_lisible(): void
    {
        $this->assertSame('Votre facture n&#039;est pas partie&#46; Réessayez dans 2 minutes&#46;', LigneDeCourriel::de("Votre facture n'est pas partie. Réessayez dans 2 minutes.")->toHtml());
        $this->assertSame('Votre facture n&#039;est pas partie&#46; Réessayez dans 2 minutes&#46;', LigneDeCourriel::de("  Votre facture n'est pas partie.\n\n\t Réessayez dans 2 minutes.\r\n")->toHtml());
    }

    private function versionEnTexte(MailMessage $courriel): string
    {
        return (string) app(Markdown::class)->renderText('notifications::email', $courriel->data());
    }
}
