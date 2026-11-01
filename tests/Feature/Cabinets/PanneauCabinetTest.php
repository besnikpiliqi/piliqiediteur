<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Cabinet\RessourceDuPanneauCabinet;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L'espace d'un cabinet d'expertise comptable (voir CabinetPanelProvider)
 * : chaque page y déclare elle-même son accès et ne s'ouvre qu'aux comptes
 * actifs du cabinet affiché ; chaque ressource hérite de la base stricte,
 * avec une policy ; aucun compte d'une société qui n'est pas un cabinet ne
 * l'ouvre jamais.
 */
class PanneauCabinetTest extends TestCase
{
    use RefreshDatabase;

    public function test_chaque_page_et_ressource_du_panneau_cabinet_declare_son_acces_et_a_une_policy(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $proprietaire = $this->proprietaireDuCabinet($cabinet);
        $autreCabinet = $this->ouvrirUnCabinet(SocieteFactory::sirenFictif());
        $magasin = $this->ouvrirUneSociete();
        $proprietaireDUnCommerce = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $suspendu = $this->proprietaireDuCabinet($autreCabinet);
        $suspendu->forceFill(['statut' => StatutCompte::Suspendu])->save();
        $panneau = Filament::getPanel('cabinet');
        $pages = $panneau->getPages();

        $this->assertContains(TableauDeBordDuCabinet::class, $pages);

        foreach ($pages as $page) {
            /** @var class-string<Page> $page */
            $this->assertSame($page, (new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName(), $page);
            $this->assertSame(
                ['proprietaire' => true, 'autre_cabinet' => false, 'commerce' => false, 'administrateur' => false, 'suspendu' => false, 'sans_connexion' => false],
                [
                    'proprietaire' => $this->ouvre($page, $proprietaire, $cabinet),
                    'autre_cabinet' => $this->ouvre($page, $this->proprietaireDuCabinet($this->ouvrirUnCabinet(SocieteFactory::sirenFictif())), $cabinet),
                    'commerce' => $this->ouvre($page, $proprietaireDUnCommerce, $magasin->societe),
                    'administrateur' => $this->ouvre($page, $administrateur, $magasin->societe),
                    'suspendu' => $this->ouvre($page, $suspendu->fresh(), $autreCabinet),
                    'sans_connexion' => $this->ouvre($page, null, $cabinet),
                ],
                $page,
            );
        }

        foreach ($panneau->getResources() as $ressource) {
            /** @var class-string<resource> $ressource */
            $this->assertTrue(is_subclass_of($ressource, RessourceDuPanneauCabinet::class), $ressource);
            $this->assertFalse($ressource::shouldCheckPolicyExistence(), $ressource);
            $this->assertNotNull(Gate::getPolicyFor($ressource::getModel()), $ressource);
        }

        $this->assertFalse(RessourceDuPanneauCabinet::shouldCheckPolicyExistence());
    }

    /**
     * @return array<string, array{0: TypeDActivite, 1: string}>
     */
    public static function comptesSansEspaceDeCabinet(): array
    {
        $comptes = [];

        foreach ([TypeDActivite::Commerce, TypeDActivite::Services] as $type) {
            foreach (['proprietaire', 'administrateur', 'membre'] as $compte) {
                $comptes["{$compte} ({$type->value})"] = [$type, $compte];
            }
        }

        return $comptes;
    }

    /**
     * Le propriétaire, un administrateur ou un membre d'une société qui
     * n'est pas un cabinet n'ouvre jamais l'espace d'un cabinet : ni pour
     * sa propre société (403), ni pour un cabinet (403), et le panneau ne
     * lui propose aucune société.
     */
    #[DataProvider('comptesSansEspaceDeCabinet')]
    public function test_un_compte_d_une_societe_qui_n_est_pas_un_cabinet_n_ouvre_jamais_le_panneau_cabinet(TypeDActivite $type, string $role): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $magasin = $this->ouvrirUneSociete(['type' => $type]);
        $compte = match ($role) {
            'proprietaire' => $this->proprietaireDe($magasin),
            'administrateur' => $this->administrateur($magasin, null, AutorisationsSociete::toutes()),
            default => $this->responsable($magasin),
        };
        app(TenantContext::class)->clear();

        $this->assertFalse($compte->canAccessPanel(Filament::getPanel('cabinet')));
        $this->assertSame([], $compte->getTenants(Filament::getPanel('cabinet'))->modelKeys());
        $this->assertFalse($compte->canAccessTenant($cabinet));
        $this->actingAs($compte);
        $this->get(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $magasin->societe))->assertForbidden();
        $this->get(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $cabinet))->assertForbidden();
    }

    private function ouvre(string $page, ?User $compte, Societe $societe): bool
    {
        auth()->logout();

        if ($compte !== null) {
            $this->actingAs($compte);
        }

        Filament::setCurrentPanel(Filament::getPanel('cabinet'));
        Filament::setTenant($societe, isQuiet: true);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($societe);

        return $page::canAccess();
    }
}
