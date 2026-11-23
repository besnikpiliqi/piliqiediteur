<?php

namespace App\Console\Commands;

use App\Domain\Demonstration\Messages\MessagesSignes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Commande de l'éditeur : tire une paire de clés Ed25519 des messages
 * entre le site réel et le site de démonstration (voir MessagesSignes),
 * distincte des clés de la caisse et du journal d'audit. Rien ne s'écrit
 * sur le disque : la clé privée et son identifiant vont dans le .env du
 * site réel seulement, la clé publique dans celui du site de
 * démonstration seulement (une liste par identifiant, pour tourner la clé
 * sans coupure : garder l'ancienne clé publique jusqu'à ce que plus aucun
 * message signé par elle ne puisse arriver). Avec --etat, la paire de
 * l'état publié du site de démonstration (voir EtatDuSite), dans l'autre
 * sens : la clé privée dans le .env du site de démonstration seulement, la
 * clé publique dans celui du site réel seulement.
 */
#[Signature('demonstration:generer-cle {--etat : Tire la paire de l\'état publié du site de démonstration au lieu de celle des messages}')]
#[Description('Tire une paire de clés des messages vers le site de démonstration et affiche une seule fois les lignes des deux .env')]
class GenererCleDesMessagesDeLaDemonstration extends Command
{
    public function handle(): int
    {
        $paire = MessagesSignes::genererUnePaire();

        if ($this->option('etat') === true) {
            $this->warn('Clé privée, affichée cette seule fois et écrite nulle part : cette ligne va dans le .env du site de démonstration seulement.');
            $this->line('DEMONSTRATION_ETAT_CLE_PRIVEE='.$paire['cle_privee']);
            $this->newLine();
            $this->info('Clé publique : cette ligne va dans le .env du site réel seulement.');
            $this->line('DEMONSTRATION_ETAT_CLE_PUBLIQUE='.$paire['cle_publique']);

            return self::SUCCESS;
        }

        $this->warn('Clé privée, affichée cette seule fois et écrite nulle part : ces deux lignes vont dans le .env du site réel seulement.');
        $this->line('DEMONSTRATION_MESSAGES_CLE_PRIVEE='.$paire['cle_privee']);
        $this->line('DEMONSTRATION_MESSAGES_CLE_ID='.$paire['cle_id']);
        $this->newLine();
        $this->info('Clé publique : cette ligne va dans le .env du site de démonstration seulement (ajoutez-la à la liste existante pour tourner la clé sans coupure).');
        $this->line('DEMONSTRATION_MESSAGES_CLES_PUBLIQUES='.json_encode([$paire['cle_id'] => $paire['cle_publique']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
