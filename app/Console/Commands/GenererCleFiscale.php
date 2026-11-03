<?php

namespace App\Console\Commands;

use App\Domain\CaisseSecurisee\Services\SignataireFiscal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Commande de l'éditeur : génère une paire de clés de signature fiscale
 * (ECDSA P-256, voir SignataireFiscal).
 *
 * La clé publique s'écrit dans le dossier des clés épinglées, sous le nom
 * {cle_id}.pem. La clé privée n'est écrite nulle part : la ligne
 * d'environnement qui la contient s'affiche une seule fois, à conserver
 * dans un coffre avant de la placer dans l'environnement du serveur.
 *
 * Une rotation génère une nouvelle paire sans retirer l'ancienne clé
 * publique, qui doit rester épinglée tant que les données qu'elle a
 * signées sont conservées.
 */
#[Signature('caisse:generer-cle')]
#[Description('Génère une paire de clés de signature fiscale : épingle la clé publique et affiche une seule fois la clé privée')]
class GenererCleFiscale extends Command
{
    public function handle(): int
    {
        $dossier = (string) config('caisse_securisee.dossier_cles_publiques');
        $paire = SignataireFiscal::genererUnePaire();
        $fichier = $dossier.DIRECTORY_SEPARATOR.$paire['cle_id'].'.pem';

        File::ensureDirectoryExists($dossier);
        File::put($fichier, $paire['cle_publique']);

        $this->info("Clé publique épinglée : {$fichier}");
        $this->line("Empreinte (cle_id) : {$paire['cle_id']}");
        $this->newLine();
        $this->warn("Clé privée, affichée cette seule fois et écrite nulle part : conservez la ligne suivante dans un coffre, puis placez-la dans l'environnement du serveur.");
        $this->line('CAISSE_SECURISEE_CLE_PRIVEE='.base64_encode($paire['cle_privee']));
        $this->newLine();
        $this->line("Versionnez le fichier .pem uniquement s'il s'agit d'une clé de production de l'éditeur, puis redéployez. Ne supprimez jamais une clé épinglée : les données qu'elle a signées doivent rester vérifiables.");

        return self::SUCCESS;
    }
}
