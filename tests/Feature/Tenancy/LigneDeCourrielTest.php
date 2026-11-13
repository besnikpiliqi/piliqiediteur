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
 * mais ce texte s'y lit tel qu'il a été écrit, sans gras, sans lien, sans
 * titre ni code, dans la version HTML comme dans la version en texte.
 */
class LigneDeCourrielTest extends TestCase
{
    use RefreshDatabase;

    private const PIEGE = "Le fichier « **Urgent** [Vérifier](https://evil.example/connexion) `rm` _vite_.pdf » est refusé.\n# Votre compte est bloqué\n1. <b>l'antivirus</b> & co | a = b ~ c !";

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
            $this->assertStringContainsString(self::PIEGE, $this->versionEnTexte($courriel), $avis::class);
        }
    }

    public function test_une_ligne_sans_rien_de_particulier_reste_lisible(): void
    {
        $this->assertSame('Votre facture n&#039;est pas partie&#46; Réessayez dans 2 minutes&#46;', LigneDeCourriel::de("Votre facture n'est pas partie. Réessayez dans 2 minutes.")->toHtml());
    }

    private function versionEnTexte(MailMessage $courriel): string
    {
        return (string) app(Markdown::class)->renderText('notifications::email', $courriel->data());
    }
}
