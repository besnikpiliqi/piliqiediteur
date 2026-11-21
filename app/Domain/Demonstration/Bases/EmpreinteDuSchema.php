<?php

namespace App\Domain\Demonstration\Bases;

use Illuminate\Support\Facades\File;

/**
 * L'empreinte du schéma que le code attend d'une base du site de
 * démonstration (lot DEMO-COPIE, C.6 et E.2) : le SHA-256 du nom et du
 * contenu de chaque migration commune (database/migrations) et de chaque
 * migration du site de démonstration (database/migrations-demonstration).
 * Le registre la note pour chaque modèle et chaque copie ; une copie dont
 * l'empreinte n'est pas celle du code n'est pas servie.
 */
final class EmpreinteDuSchema
{
    public const DOSSIERS = ['database/migrations', 'database/migrations-demonstration'];

    public static function duCode(): string
    {
        $contexte = hash_init('sha256');

        foreach (self::DOSSIERS as $dossier) {
            $fichiers = File::files(base_path($dossier));
            usort($fichiers, static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getFilename(), $b->getFilename()));

            foreach ($fichiers as $fichier) {
                hash_update($contexte, $dossier.'/'.$fichier->getFilename()."\n");
                hash_update($contexte, hash_file('sha256', $fichier->getPathname()) ?: '');
            }
        }

        return hash_final($contexte);
    }
}
