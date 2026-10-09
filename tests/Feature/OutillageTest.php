<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Install\SkillComposer;
use Laravel\Boost\Support\Config;
use Tests\TestCase;

/**
 * Aucun paquet inutilisé et aucun outil Node (décisions de l'éditeur) :
 * Filament Shield et Sanctum, retirés, n'exposent plus ni route ni
 * commande, et rien de ce que lisent les agents ne renvoie à un outil
 * absent du projet.
 */
class OutillageTest extends TestCase
{
    public function test_aucune_route_d_un_paquet_retire_n_est_exposee(): void
    {
        $reponse = $this->get('/sanctum/csrf-cookie');

        $reponse->assertNotFound();
    }

    public function test_aucune_commande_d_un_paquet_retire_n_est_proposee(): void
    {
        $commandes = array_keys(Artisan::all());

        $commandesDesPaquetsRetires = array_values(array_filter(
            $commandes,
            fn (string $commande): bool => str_starts_with($commande, 'shield:') || str_starts_with($commande, 'sanctum:'),
        ));

        $this->assertSame([], $commandesDesPaquetsRetires);
    }

    /**
     * Le gabarit de Boost écrit sans condition la consigne « Frontend
     * Bundling » (npm run build, npm run dev, composer run dev) et la
     * lecture de package.json : un boost:install les remettrait dans
     * CLAUDE.md alors que le projet n'a ni package.json ni script « dev ».
     */
    public function test_les_consignes_des_agents_ne_renvoient_a_aucun_outil_absent(): void
    {
        $consignes = File::get(base_path('CLAUDE.md'));

        $renvoisVersUnOutilAbsent = array_values(array_filter(
            ['package.json', 'npm run', 'composer run dev'],
            fn (string $renvoi): bool => str_contains($consignes, $renvoi),
        ));

        $this->assertSame([], $renvoisVersUnOutilAbsent);
    }

    /**
     * Les skills suivis par Boost (boost.json) et ceux que lisent les agents
     * (.claude/skills) font tous partie de ceux que Boost propose pour ce
     * projet d'après ses paquets installés : un skill resté après le retrait
     * de son paquet (Fortify, Tailwind) enverrait l'agent vers un outil absent.
     */
    public function test_aucun_skill_d_un_paquet_absent_n_est_propose_aux_agents(): void
    {
        $boost = new Config;
        $configuration = new GuidelineConfig;
        $configuration->usesCloud = $boost->getCloud();
        $skillsDesPaquetsInstalles = app(SkillComposer::class)->config($configuration)->skills()->keys();

        $skillsProposesAuxAgents = collect($boost->getSkills())
            ->merge(array_map('basename', File::directories(base_path('.claude/skills'))))
            ->unique();

        $this->assertSame([], $skillsProposesAuxAgents->diff($skillsDesPaquetsInstalles)->values()->all());
    }
}
