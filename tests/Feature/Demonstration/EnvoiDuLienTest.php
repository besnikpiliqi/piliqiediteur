<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Listeners\MasquerLeLienDansLeJournal;
use App\Domain\Demonstration\Mail\LienDeLaDemonstrationMail;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * N8 (lot DEMO-COPIE, B.6, B.10 et B.11) : l'e-mail du lien part seulement
 * à l'adresse de connexion confirmée du propriétaire, sans son
 * identifiant, avec la phrase du choix 2 et le repère ; au plus 5
 * renouvellements par jour ; le transport « log » masque le lien.
 */
class EnvoiDuLienTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reglerLeSiteDeDemonstration();
    }

    public function test_l_e_mail_part_a_l_adresse_de_connexion_confirmee_sans_l_identifiant_avec_le_repere_et_la_phrase_du_secret(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);

        $resultat = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        Mail::assertSent(LienDeLaDemonstrationMail::class, function (LienDeLaDemonstrationMail $mail) use ($proprietaire, $resultat): bool {
            $html = html_entity_decode($mail->render(), ENT_QUOTES | ENT_HTML5);
            $identifiant = $proprietaire->identifiantDeConnexion();

            return $mail->hasTo((string) $proprietaire->email)
                && count($mail->to) === 1
                && $mail->envelope()->subject === LienDeLaDemonstrationMail::OBJET
                && str_contains($html, DemanderUneDemonstrationAction::adresseDuLien((string) $resultat->lien))
                && str_contains($html, LienDeLaDemonstrationMail::PHRASE_DU_SECRET)
                && str_contains($html, $resultat->demonstration->repere)
                && $identifiant !== null
                && ! str_contains($html, $identifiant);
        });
    }

    public function test_cinq_renouvellements_par_jour_le_sixieme_est_refuse_en_clair(): void
    {
        Mail::fake();
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());
        app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        for ($i = 0; $i < RenouvelerLeLienDeLaDemonstrationAction::PAR_JOUR; $i++) {
            app(TenantContext::class)->clear();
            app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null);
        }

        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($proprietaire, 'password', null), LogicException::class, RenouvelerLeLienDeLaDemonstrationAction::MESSAGE_TROP_DE_RENOUVELLEMENTS);
        Mail::assertSentCount(1 + RenouvelerLeLienDeLaDemonstrationAction::PAR_JOUR);
    }

    public function test_avec_le_transport_log_le_lien_est_masque_dans_le_journal(): void
    {
        $journal = storage_path('logs/essai-lien-de-la-demonstration-'.getmypid().'.log');
        File::delete($journal);
        config([
            'mail.default' => 'log',
            'mail.mailers.log.channel' => 'essai-du-lien',
            'logging.channels.essai-du-lien' => ['driver' => 'single', 'path' => $journal],
        ]);
        $proprietaire = $this->proprietaireDe($this->ouvrirUneSociete());

        $resultat = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $contenu = File::get($journal);
        File::delete($journal);
        $this->assertStringContainsString(MasquerLeLienDansLeJournal::REMPLACEMENT, $contenu);
        $this->assertStringNotContainsString((string) $resultat->lien, $contenu);
        $this->assertStringContainsString($resultat->demonstration->repere, $contenu);
    }
}
