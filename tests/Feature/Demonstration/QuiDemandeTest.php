<?php

namespace Tests\Feature\Demonstration;

use App\Domain\Demonstration\Actions\DemanderUneDemonstrationAction;
use App\Domain\Demonstration\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RecommencerLaDemonstrationAction;
use App\Domain\Demonstration\Actions\RenouvelerLeLienDeLaDemonstrationAction;
use App\Domain\Demonstration\Enums\EtatDeLaDemonstration;
use App\Domain\Demonstration\Services\CarteDeLaDemonstration;
use App\Domain\Demonstration\Services\ConditionsDeLaDemonstration;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\MaDemonstration;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * N6 (lot DEMO-COPIE, B.2 et B.3) : seul le propriétaire voit la carte
 * « Ma démonstration » et fait chaque geste, dans une société de chaque
 * type et dans un cabinet ; tout autre compte est refusé, sans rien écrire
 * et sans aucun message vers le site de démonstration ; le type de la
 * copie est celui de la société, sans choix.
 */
class QuiDemandeTest extends TestCase
{
    use DemonstrationDuSiteReel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->reglerLeSiteDeDemonstration(202, ['etat' => 'en_preparation']);
    }

    /**
     * @return array<string, array{0: TypeDActivite}>
     */
    public static function typesDesSocietes(): array
    {
        return [
            'commerce ou atelier' => [TypeDActivite::Commerce],
            'services, sans vente d\'articles' => [TypeDActivite::Services],
        ];
    }

    #[DataProvider('typesDesSocietes')]
    public function test_le_proprietaire_d_une_societe_cree_la_demonstration_de_son_type_sans_choix(TypeDActivite $type): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => $type]);
        $proprietaire = $this->proprietaireDe($magasin);

        $resultat = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $this->assertSame($type, $resultat->demonstration->type_d_activite);
        $this->assertSame(EtatDeLaDemonstration::EnPreparation, $resultat->demonstration->fresh()?->etat);
        $this->assertSame([$type->value], array_column($this->messagesRecus(), 'type'));
        $this->assertNotNull(app(CarteDeLaDemonstration::class)->pour($proprietaire, $magasin->societe));
    }

    /**
     * La phrase de la carte « À créer » décrit la société modèle de son type
     * (F.2) : six magasins pour Maison Ferrand, un seul pour Conseil
     * Vasseur ; jamais un autre type d'activité.
     */
    public function test_la_carte_decrit_la_societe_modele_de_son_type(): void
    {
        $this->assertStringContainsString('plusieurs magasins, toute l\'équipe, treize mois de ventes, d\'achats et de clôtures', CarteDeLaDemonstration::phraseACreer(TypeDActivite::Commerce));
        $this->assertStringContainsString('un magasin, toute l\'équipe, treize mois de ventes et de clôtures', CarteDeLaDemonstration::phraseACreer(TypeDActivite::Services));
        $this->assertStringNotContainsString('plusieurs magasins', CarteDeLaDemonstration::phraseACreer(TypeDActivite::Services));
        $this->assertStringNotContainsString('achats', CarteDeLaDemonstration::phraseACreer(TypeDActivite::Services));

        $magasin = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $carte = app(CarteDeLaDemonstration::class)->pour($this->proprietaireDe($magasin), $magasin->societe);
        $this->assertContains(CarteDeLaDemonstration::phraseACreer(TypeDActivite::Services), $carte['phrases'] ?? []);
    }

    public function test_le_proprietaire_d_un_cabinet_cree_la_demonstration_d_un_cabinet(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);

        $resultat = app(DemanderUneDemonstrationAction::class)->handle($proprietaire, null, null);

        $this->assertSame(TypeDActivite::Cabinet, $resultat->demonstration->type_d_activite);
        $this->assertSame('cabinet', $this->messagesRecus()[0]['type']);
        $this->assertSame(CarteDeLaDemonstration::EN_PREPARATION, app(CarteDeLaDemonstration::class)->pour($proprietaire, $cabinet)['etat'] ?? null);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function autresComptes(): array
    {
        return [
            'administrateur de tous les magasins, autorisé à tout' => ['administrateur'],
            'responsable' => ['responsable'],
            'vendeur' => ['vendeur'],
            'observateur' => ['observateur'],
            'collaborateur d\'un cabinet lié' => ['collaborateur'],
            'expert-comptable déclaré' => ['expert-comptable'],
            'ancien propriétaire retiré' => ['retire'],
        ];
    }

    #[DataProvider('autresComptes')]
    public function test_tout_autre_compte_est_refuse_sans_rien_ecrire_ni_rien_envoyer(string $qui): void
    {
        $magasin = $this->ouvrirUneSociete();
        app(DemanderUneDemonstrationAction::class)->handle($this->proprietaireDe($magasin), null, null);
        $compte = $this->compte($qui, $magasin);
        $avant = [DB::table('demonstrations')->count(), DB::table('messages_vers_la_demonstration')->count(), DB::table('journal_audit')->count(), count($this->messagesRecus())];

        foreach ([
            fn () => app(DemanderUneDemonstrationAction::class)->handle($compte, null, null),
            fn () => app(RenouvelerLeLienDeLaDemonstrationAction::class)->handle($compte, 'password', null),
            fn () => app(RecommencerLaDemonstrationAction::class)->handle($compte, null),
            fn () => app(OuvrirLaDemonstrationAction::class)->handle($compte),
        ] as $geste) {
            app(TenantContext::class)->clear();
            $this->assertThrows($geste, AuthorizationException::class, ConditionsDeLaDemonstration::REFUS);
        }

        $this->assertSame($avant, [DB::table('demonstrations')->count(), DB::table('messages_vers_la_demonstration')->count(), DB::table('journal_audit')->count(), count($this->messagesRecus())]);
        $this->assertNull(app(CarteDeLaDemonstration::class)->pour($compte, $magasin->societe));
    }

    public function test_la_carte_ne_propose_jamais_de_m_envoyer_le_lien_par_e_mail_et_seul_le_proprietaire_la_voit(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin);

        $this->actingAs($proprietaire);
        Livewire::test(MaDemonstration::class, ['societe' => $magasin->societe])
            ->assertSee('Créer ma démonstration')
            ->assertDontSee('M\'envoyer le lien par e-mail');

        $this->actingAs($administrateur);
        Livewire::test(MaDemonstration::class, ['societe' => $magasin->societe])
            ->assertDontSee('Ma démonstration')
            ->assertDontSee('Créer ma démonstration');
    }

    private function compte(string $qui, Magasin $magasin): User
    {
        return match ($qui) {
            'administrateur' => $this->administrateur($magasin),
            'responsable' => $this->responsable($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur),
            'observateur' => $this->membre($magasin, RoleMagasin::Observateur),
            'collaborateur' => $this->collaborateurDuCabinetLie($magasin),
            'expert-comptable' => $this->expertComptable($magasin),
            'retire' => $this->compteRetireQuiEtaitProprietaire($magasin->societe),
            default => $this->fail('Compte inconnu'),
        };
    }

    /**
     * Un compte de la société, retiré, qui garde le rôle de propriétaire
     * qu'aucune action ne lui donnerait plus : son statut suffit au refus.
     */
    private function compteRetireQuiEtaitProprietaire(Societe $societe): User
    {
        $compte = $societe->proprietaire()->firstOrFail();
        $compte->forceFill(['statut' => StatutCompte::Retire])->saveQuietly();

        return $compte->fresh() ?? $this->fail('Compte disparu');
    }
}
