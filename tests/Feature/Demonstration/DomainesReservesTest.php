<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Cabinets\Actions\InviterSonCabinetACreerSonEspaceAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Rules\PasUnDomaineReserve;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * B.15 (lot DEMO-COPIE, choix 26) : en production, sur le site réel, une
 * adresse de connexion, une adresse professionnelle d'expert-comptable et
 * une adresse d'invitation sur un domaine réservé aux exemples sont
 * refusées avec leur message ; en développement et en test, le semis s'en
 * sert.
 */
class DomainesReservesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function adresses(): array
    {
        return [
            'example.com' => ['marie@example.com', true],
            'sous-domaine de example.org' => ['marie@contact.example.org', true],
            'example.net en capitales' => ['Marie@EXAMPLE.NET', true],
            '.test' => ['marie@petit-associes.test', true],
            '.example' => ['marie@boutique.example', true],
            '.invalid' => ['marie@boutique.invalid', true],
            '.localhost' => ['marie@poste.localhost', true],
            'vrai domaine' => ['marie@boutique-durand.fr', false],
            'domaine qui finit comme un exemple' => ['marie@monexample.com', false],
        ];
    }

    #[DataProvider('adresses')]
    public function test_le_domaine_reserve_se_reconnait(string $adresse, bool $reserve): void
    {
        $this->assertSame($reserve, PasUnDomaineReserve::estReserve($adresse));
    }

    public function test_en_production_sur_le_site_reel_chaque_saisie_d_une_adresse_d_exemple_est_refusee(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaireDuCabinet = $this->proprietaireDuCabinet($cabinet);
        app(TenantContext::class)->clear();
        $this->app->detectEnvironment(fn (): string => 'production');

        foreach ([
            'expert-comptable' => fn () => app(CreationDesComptes::class)->validerLAdresse('marc@petit-associes.test', 'marc@petit-associes.test'),
            'adresse de connexion' => fn () => app(DemanderUneNouvelleAdresseAction::class)->handle($proprietaire, 'nouvelle@example.com'),
            'inscription' => fn () => app(CreerSocieteAction::class)->handle('552100554', TypeDActivite::Commerce, 'proprietaire@example.org', 'un-mot-de-passe-solide'),
            'invitation d\'un cabinet' => fn () => app(InviterUneEntrepriseAction::class)->handle($proprietaireDuCabinet, $cabinet, '552100554', 'client@example.net', 'password'),
            'invitation d\'une société à son cabinet' => fn () => app(InviterSonCabinetACreerSonEspaceAction::class)->handle($proprietaire, $proprietaire->societe, 'cabinet@exemple.invalid'),
        ] as $saisie => $geste) {
            app(TenantContext::class)->clear();

            try {
                $geste();
                $this->fail('Adresse d\'exemple acceptée : '.$saisie);
            } catch (ValidationException $refus) {
                $this->assertContains(PasUnDomaineReserve::MESSAGE, $refus->validator->errors()->all(), $saisie);
            }
        }
    }

    public function test_en_test_le_semis_se_sert_des_domaines_reserves(): void
    {
        $this->assertFalse(PasUnDomaineReserve::s_applique());
        $this->assertSame('marc@petit-associes.test', app(CreationDesComptes::class)->validerLAdresse('marc@petit-associes.test', 'marc@petit-associes.test'));
    }
}
