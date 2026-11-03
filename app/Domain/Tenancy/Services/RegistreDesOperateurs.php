<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;

/**
 * Les opérateurs de l'éditeur autorisés à lancer ses procédures
 * exceptionnelles (voir ProcedureDeLEditeur) : chacun par son nom et
 * l'empreinte Argon2id de son secret personnel (EDITEUR_OPERATEURS, voir
 * config/editeur.php), jamais le secret lui-même. Un nom inconnu, un
 * secret faux ou une empreinte d'un autre algorithme reçoivent la même
 * réponse. Limite assumée jusqu'à l'administration de l'éditeur (étape
 * 13) : un secret partagé entre opérateurs ne se détecte pas.
 */
class RegistreDesOperateurs
{
    public const MESSAGE_REFUS = 'Opérateur inconnu ou secret faux : rien n\'est fait.';

    /**
     * @return string le nom de l'opérateur authentifié
     *
     * @throws VerificationRefuseeException
     */
    public function authentifier(?string $nom, ?string $secret): string
    {
        $empreinte = is_string($nom) ? (config('editeur.operateurs')[$nom] ?? null) : null;

        if (! is_string($empreinte) || ! is_string($secret) || $secret === ''
            || password_get_info($empreinte)['algoName'] !== 'argon2id'
            || ! password_verify($secret, $empreinte)) {
            throw new VerificationRefuseeException(self::MESSAGE_REFUS);
        }

        return $nom;
    }
}
