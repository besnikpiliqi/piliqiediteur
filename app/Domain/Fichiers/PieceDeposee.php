<?php

namespace App\Domain\Fichiers;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Un fichier déposé, tel que reçu, avant tout contrôle (voir
 * ControleDesFichiers) : une pièce du fournisseur (voir
 * PiecesDuFournisseur) ou une pièce de la vérification de la société (voir
 * DeposerUnePieceAction). Le nom que lui donnait la personne, qui n'est
 * qu'une donnée (le nom enregistré est réécrit, voir NomDeFichier), son
 * contenu, lu une fois, et, pour un fichier envoyé par un écran,
 * l'identifiant de cet envoi (le nom, tiré au hasard par Livewire, de son
 * fichier dans le dossier temporaire des envois).
 */
final readonly class PieceDeposee
{
    public function __construct(
        public string $nom,
        public string $contenu,
        public ?string $envoi = null,
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
            fn (UploadedFile $fichier): self => new self($fichier->getClientOriginalName(), (string) $fichier->get(), $fichier instanceof TemporaryUploadedFile ? $fichier->getFilename() : null),
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
     * Ce qui désigne chaque pièce, sans son contenu : ce que le jeton d'une
     * demande répétée compare (voir ActionsUniques). Son nom, et
     * l'identifiant de son envoi pour un fichier envoyé par un écran, sinon
     * l'empreinte de son contenu : l'écran efface ses envois une fois
     * l'avoir émis (voir effacerLesEnvois()), si bien que la même demande,
     * renvoyée après une réponse perdue, désigne les mêmes envois, mais
     * n'en relit plus le contenu.
     *
     * @param  list<self>  $pieces
     * @return list<array{nom: string, envoi: string}|array{nom: string, empreinte: string}>
     */
    public static function signatures(array $pieces): array
    {
        return array_map(fn (self $piece): array => $piece->envoi === null
            ? ['nom' => $piece->nom, 'empreinte' => hash('sha256', $piece->contenu)]
            : ['nom' => $piece->nom, 'envoi' => $piece->envoi], $pieces);
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
