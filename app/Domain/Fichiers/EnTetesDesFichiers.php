<?php

namespace App\Domain\Fichiers;

/**
 * Les en-têtes des fichiers que PILIQI sert (décision de l'éditeur du
 * 07/11/2026, lot FI).
 *
 * - Un fichier reçu (pièce du fournisseur), et le ZIP ou le CSV du
 *   téléchargement des documents par période qui peut en contenir, se
 *   télécharge toujours en pièce jointe, jamais affiché dans une page de
 *   PILIQI : son type exact, sans que le navigateur en devine un autre
 *   (nosniff), dans un bac à sable sans aucune source (une page qui
 *   l'ouvrirait malgré tout n'y exécute rien et n'y charge rien), jamais
 *   gardé en cache, jamais chargé par un autre site.
 * - Un fichier que PILIQI produit lui-même (PDF d'un document, reçu,
 *   archive fiscale) garde son mode d'affichage, avec son type exact,
 *   nosniff et sans cache.
 */
final class EnTetesDesFichiers
{
    public const BAC_A_SABLE = "sandbox; default-src 'none'";

    public const SANS_CACHE = 'private, no-store';

    /**
     * Les en-têtes d'un fichier reçu, ou qui en contient, de ce nom et de
     * ce type.
     *
     * @return array<string, string>
     */
    public static function fichierRecu(string $nom, string $type): array
    {
        return [
            'Content-Type' => $type,
            'Content-Disposition' => NomDeFichier::enPieceJointe($nom),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::BAC_A_SABLE,
            'Cache-Control' => self::SANS_CACHE,
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
    }

    /**
     * Les en-têtes d'un fichier que PILIQI produit, de ce type.
     *
     * @return array<string, string>
     */
    public static function fichierProduit(string $type): array
    {
        return [
            'Content-Type' => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => self::SANS_CACHE,
        ];
    }
}
