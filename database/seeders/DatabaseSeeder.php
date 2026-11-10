<?php

namespace Database\Seeders;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * Aucune donnée n'est semée tant que les données de démonstration n'existent
 * pas (étape 15 de la feuille de route) : une société se crée par
 * l'inscription du panneau (voir InscriptionSociete), qui passe par
 * CreerSocieteAction à la confirmation de son lien.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * La commande de la console qui lance le semis (db:seed), qui la donne
     * par setCommand() ; aucune quand le semeur est lancé autrement.
     * Seeder::$command se dit toujours présente, mais reste vide jusque-là.
     */
    private ?Command $commande = null;

    public function setCommand(Command $command): static
    {
        $this->commande = $command;

        return parent::setCommand($command);
    }

    public function run(): void
    {
        $this->commande?->info('Aucune donnée de démonstration : créez une société depuis la page d\'inscription (/app/register).');
    }
}
