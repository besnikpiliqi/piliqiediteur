<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Aucune donnée n'est semée tant que les données de démonstration n'existent
 * pas (étape 15 de la feuille de route) : une société se crée par
 * l'inscription du panneau (voir InscriptionSociete), qui passe par
 * CreerSocieteAction à la confirmation de son lien.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Aucune donnée de démonstration : créez une société depuis la page d\'inscription (/app/register).');
    }
}
