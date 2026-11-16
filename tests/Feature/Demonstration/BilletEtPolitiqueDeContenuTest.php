<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Tenancy\Services\TenantContext;
use App\Http\Controllers\Demonstration\EntrerDansLaDemonstrationController;
use App\Http\Middleware\AjouterLesEnTetesDeSecurite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

/**
 * N32 (lot DEMO-COPIE, B.4) : la page du billet d'entrée porte sa propre
 * politique de contenu (form-action 'self' et l'origine exacte du site de
 * démonstration), Referrer-Policy: no-referrer et Cache-Control: no-store ;
 * aucune autre page n'élargit form-action ; le formulaire part par un
 * fichier du site, sans script écrit dans la page ; le billet signé a
 * l'audience « entree » et vaut 60 secondes.
 */
class BilletEtPolitiqueDeContenuTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    public function test_la_page_du_billet_porte_sa_politique_et_son_formulaire_vers_le_site_de_demonstration(): void
    {
        Mail::fake();
        $this->reglerLeSiteDeDemonstration();
        $this->travelTo(self::aParis('2026-11-18 10:00:00'));
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        $demonstration = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null)->demonstration;
        app(TenantContext::class)->clear();

        $reponse = $this->actingAs($proprietaire)->post(route('ma-demonstration.ouvrir'));

        $reponse->assertOk();
        $politique = (string) $reponse->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("form-action 'self' ".self::ORIGINE_DE_LA_DEMONSTRATION.';', $politique);
        $this->assertSame(str_replace("form-action 'self'", "form-action 'self' ".self::ORIGINE_DE_LA_DEMONSTRATION, AjouterLesEnTetesDeSecurite::POLITIQUE_DE_CONTENU), $politique);
        $this->assertSame('no-referrer', $reponse->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
        $html = (string) $reponse->getContent();
        $this->assertStringContainsString('action="'.self::ORIGINE_DE_LA_DEMONSTRATION.'/entrer-par-le-compte"', $html);
        $this->assertStringContainsString('src="'.asset('js/demonstration/envoyer-le-billet.js').'"', $html);
        $this->assertSame(0, preg_match('~<script(?![^>]*\bsrc=)[^>]*>~i', $html), 'Aucun script écrit dans la page.');

        preg_match('~name="message" value="([^"]+)"~', $html, $message);
        preg_match('~name="signature" value="([^"]+)"~', $html, $signature);
        $billet = MessagesSignes::verifier(html_entity_decode($message[1] ?? '', ENT_QUOTES), $signature[1] ?? '', $this->clesPubliquesDuTest);
        $this->assertSame([MessagesSignes::AUDIENCE_DE_L_ENTREE, MessagesSignes::BILLET_D_ENTREE, $demonstration->reference, '2026-11-18T09:00:00Z'], [$billet['aud'], $billet['quoi'], $billet['reference'], $billet['emis_le']]);
        $this->assertSame(['aud', 'destinataire', 'emis_le', 'kid', 'nonce', 'quoi', 'reference', 'v'], array_keys($billet));
    }

    public function test_aucune_autre_page_n_elargit_form_action_et_un_refus_ne_montre_que_son_message(): void
    {
        $this->reglerLeSiteDeDemonstration();
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        app(TenantContext::class)->clear();

        $refus = $this->actingAs($proprietaire)->post(route('ma-demonstration.ouvrir'));
        $refus->assertStatus(422)->assertSee(OuvrirLaDemonstrationAction::MESSAGE_AUCUNE_DEMONSTRATION);
        $this->assertStringContainsString("form-action 'self';", (string) $refus->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("form-action 'self';", (string) $this->get('/robots.txt')->headers->get('Content-Security-Policy'));

        $fichiers = collect(File::allFiles(app_path()))
            ->filter(fn (SplFileInfo $fichier): bool => str_contains($fichier->getContents(), 'form-action'))
            ->map(fn (SplFileInfo $fichier): string => $fichier->getRelativePathname())
            ->sort()->values()->all();
        $this->assertSame(['Http/Controllers/Demonstration/EntrerDansLaDemonstrationController.php', 'Http/Controllers/OuvrirMaDemonstrationController.php', 'Http/Middleware/AjouterLesEnTetesDeSecurite.php'], $fichiers);
        $this->assertStringContainsString("form-action 'self';", EntrerDansLaDemonstrationController::POLITIQUE_DE_CONTENU, 'La page du lien du site de démonstration n\'élargit pas form-action.');
    }
}
