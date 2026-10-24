<?php

namespace App\Domain\Tenancy\Services;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * Le débit des e-mails que PILIQI envoie, depuis le domaine de l'éditeur,
 * à la demande d'une personne : au plus 60 par heure et par personne, tous
 * e-mails confondus (documents et relances d'un magasin, liens de
 * confirmation d'une nouvelle adresse, liens de la transmission de la
 * propriété), et, pour
 * ceux d'un magasin, 200 par heure et par magasin (voir
 * EnvoyerDocumentAction). Personne n'en fait un relais d'envoi en masse,
 * ni d'une société vers les comptes d'une autre. Les envois du système
 * (relance automatique) ne se comptent pas.
 *
 * Un geste vérifie le débit avant d'écrire quoi que ce soit, puis le
 * compte une fois fait.
 */
final class DebitDesEmails
{
    public const PAR_HEURE_ET_PAR_PERSONNE = 60;

    public const PAR_HEURE_ET_PAR_MAGASIN = 200;

    /**
     * @param  int|null  $magasinId  le magasin de l'e-mail ; null pour un e-mail qui n'est celui d'aucun magasin
     *
     * @throws LogicException quand l'un des plafonds est atteint
     */
    public function verifier(User $expediteur, ?int $magasinId = null): void
    {
        foreach ($this->compteurs($expediteur, $magasinId) as $cle => $maximum) {
            if (RateLimiter::tooManyAttempts($cle, $maximum)) {
                $minutes = (int) ceil(RateLimiter::availableIn($cle) / 60);

                throw new LogicException("Trop d'e-mails envoyés en une heure : réessayez dans {$minutes} minute(s).");
            }
        }
    }

    /**
     * @param  int|null  $magasinId  le magasin de l'e-mail ; null pour un e-mail qui n'est celui d'aucun magasin
     */
    public function compter(User $expediteur, ?int $magasinId = null): void
    {
        foreach (array_keys($this->compteurs($expediteur, $magasinId)) as $cle) {
            RateLimiter::hit($cle, 3600);
        }
    }

    /**
     * @return array<string, int> le maximum d'e-mails par heure de chaque compteur
     */
    private function compteurs(User $expediteur, ?int $magasinId): array
    {
        return [
            "emails:personne:{$expediteur->getKey()}" => self::PAR_HEURE_ET_PAR_PERSONNE,
            ...($magasinId === null ? [] : ["emails:magasin:{$magasinId}" => self::PAR_HEURE_ET_PAR_MAGASIN]),
        ];
    }
}
