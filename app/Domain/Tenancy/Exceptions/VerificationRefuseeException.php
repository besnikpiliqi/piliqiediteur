<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/**
 * Un geste de la vérification d'une société est refusé (voir les actions
 * de la vérification) : sans l'autorisation qu'il exige, voie bloquée,
 * code faux ou expiré, service d'un fournisseur indisponible pour le
 * moment... Le message, rédigé pour l'utilisateur, dit pourquoi, sans
 * jamais rien révéler d'une autre société.
 */
class VerificationRefuseeException extends LogicException {}
