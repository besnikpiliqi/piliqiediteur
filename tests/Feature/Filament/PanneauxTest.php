<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les deux panneaux, celui des magasins et l'espace d'administration de
 * la société : aucune de leurs ressources ne s'ouvre à la recherche
 * globale, qui chercherait hors des règles de chaque écran (l'équipe du
 * magasin, sans MagasinScope, comprise).
 */
class PanneauxTest extends TestCase
{
    use RefreshDatabase;

    public function test_aucune_ressource_n_est_ouverte_a_la_recherche_globale(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $ressources = [];

        $this->dansLeMagasin($magasin, $proprietaire);
        foreach (Filament::getPanel('app')->getResources() as $ressource) {
            $ressources[] = $ressource;
            $this->assertFalse($ressource::canGloballySearch(), "{$ressource} s'ouvre à la recherche globale.");
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        foreach (Filament::getPanel('societe')->getResources() as $ressource) {
            $ressources[] = $ressource;
            $this->assertFalse($ressource::canGloballySearch(), "{$ressource} s'ouvre à la recherche globale.");
        }

        $this->assertContains(EquipeDuMagasinResource::class, $ressources);
        $this->assertContains(MembreResource::class, $ressources);
    }
}
