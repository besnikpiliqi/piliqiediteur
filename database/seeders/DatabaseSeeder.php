<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Aucune donnée n'est semée tant que les données de démonstration n'existent
 * pas (étape 15 de la feuille de route) : un magasin se crée par
 * l'inscription du panneau, qui passe par CreerMagasinAction.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Aucune donnée de démonstration : créez un magasin depuis la page d\'inscription (/app/register).');
    }
}
