<?php

namespace App\Domain\Achats\Services;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Un fichier déposé pour devenir une pièce du fournisseur (voir
 * PiecesDuFournisseur::joindre()) : le nom que lui donnait la personne et
 * son contenu, lus une fois, avant tout contrôle.
 */
final readonly class PieceDeposee
{
    public function __construct(
        public string $nom,
        public string $contenu,
    ) {}

    /**
     * Les pièces de ces fichiers envoyés par un formulaire ; rien pour une
     * saisie vide. Une valeur qui n'est pas un fichier envoyé est ignorée :
     * seul un fichier déposé devient une pièce. Les fichiers restent dans le
     * dossier temporaire des envois, d'où les efface effacerLesEnvois() une
     * fois le geste fini (ou, laissés là, app:effacer-pieces-verification au
     * bout d'une heure) : un formulaire refusé, à corriger, les garde.
     *
     * @param  mixed  $fichiers  un fichier ou une liste de fichiers, tels que l'écran les donne
     * @return list<self>
     */
    public static function lire(mixed $fichiers): array
    {
        return array_map(
            fn (UploadedFile $fichier): self => new self($fichier->getClientOriginalName(), (string) $fichier->get()),
            self::envois($fichiers),
        );
    }

    /**
     * Efface ces fichiers du dossier temporaire des envois de l'écran.
     *
     * @param  mixed  $fichiers  un fichier ou une liste de fichiers, tels que l'écran les donne
     */
    public static function effacerLesEnvois(mixed $fichiers): void
    {
        foreach (self::envois($fichiers) as $fichier) {
            if ($fichier instanceof TemporaryUploadedFile && $fichier->exists()) {
                $fichier->delete();
            }
        }
    }

    /**
     * Le nom et l'empreinte de chaque pièce, sans leur contenu : ce que le
     * jeton d'une demande répétée compare (voir ActionsUniques).
     *
     * @param  list<self>  $pieces
     * @return list<array{nom: string, empreinte: string}>
     */
    public static function signatures(array $pieces): array
    {
        return array_map(fn (self $piece): array => ['nom' => $piece->nom, 'empreinte' => hash('sha256', $piece->contenu)], $pieces);
    }

    /**
     * @return list<UploadedFile>
     */
    private static function envois(mixed $fichiers): array
    {
        return array_values(array_filter(
            is_iterable($fichiers) ? [...$fichiers] : [$fichiers],
            fn (mixed $fichier): bool => $fichier instanceof UploadedFile,
        ));
    }
}
