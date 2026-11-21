<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Charges\Support\ContenuDeLEtat;
use App\Domain\Demonstration\Actions\ChangerDeCompteAction;
use App\Domain\Demonstration\Exceptions\SiteMalConfigureException;
use App\Domain\Demonstration\Registre\Models\Temoin;
use App\Domain\Demonstration\Visites\BandeauDeLaDemonstration;
use App\Domain\Facturation\Enums\FormatDuTelechargement;
use App\Domain\Facturation\Services\RecapitulatifDesDocuments;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\TelechargementDeDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Sur le site réel, ni bandeau « Vous êtes : », ni changement de compte
 * sans mot de passe, ni « E-mails qui seraient partis » (lot DEMO-COPIE,
 * C.8 et C.9) : leurs routes n'existent pas, leurs classes refusent de
 * servir, leurs tables ne sont pas dans la base réelle, et le transport
 * « demonstration » refuse d'écrire.
 */
class BandeauEtCourrielsHorsDuSiteReelTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_bandeau_et_le_changement_de_compte_sont_impossibles_au_site_reel(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        foreach (['demonstration.choisir', 'demonstration.changer-de-compte', 'demonstration.quitter', 'demonstration.courriels', 'demonstration.courriel', 'demonstration.courriel.piece'] as $route) {
            $this->assertFalse(Route::has($route), "La route {$route} n'existe que sur le site de démonstration.");
        }

        $this->get('/choisir')->assertNotFound();
        $this->get('/demonstration/courriels')->assertNotFound();
        $this->post('/demonstration/changer-de-compte', ['compte' => $vendeur->getKey()])->assertNotFound();
        $this->assertGuest();
        $this->assertNull(BandeauDeLaDemonstration::enCours($vendeur));
        $this->assertThrows(
            fn () => app(ChangerDeCompteAction::class)->executer(Request::create('/'), new Temoin, (int) $vendeur->getKey()),
            SiteMalConfigureException::class,
        );
        $this->assertGuest();
    }

    public function test_le_site_reel_ne_marque_jamais_ses_fichiers_demonstration(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->membre($magasin, RoleMagasin::Responsable);
        $this->assertSame('', ContenuDeLEtat::marqueDeLaDemonstration(' · '));
        $telechargement = (new TelechargementDeDocuments)->forceFill(['du' => '2026-10-01', 'au' => '2026-10-31']);
        $telechargement->format = FormatDuTelechargement::Zip;
        $this->assertSame('documents-2026-10-01-au-2026-10-31.zip', $telechargement->nomDuFichier());
        $this->assertStringNotContainsString('Démonstration', app(RecapitulatifDesDocuments::class)->ecrire($compte, [], '2026-10-01', '2026-10-31'));
    }

    public function test_les_tables_de_la_demonstration_sont_absentes_et_son_transport_refuse_d_ecrire(): void
    {
        $this->assertFalse(Schema::hasTable('courriels_de_la_demonstration'));
        $this->assertFalse(Schema::hasTable('comptes_du_bandeau'));

        $this->assertThrows(
            fn () => Mail::mailer('demonstration')->raw('Essai', fn ($message) => $message->to('client@exemple.test')->subject('Essai')),
            SiteMalConfigureException::class,
        );
    }
}
