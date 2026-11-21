<?php

namespace App\Domain\Tenancy\Contracts;

/**
 * La source de hasard des identifiants de connexion (voir
 * GenerateurDIdentifiants) : un entier tiré entre deux bornes comprises.
 * L'application la lie à SourceDeHasardCryptographique ; un test peut en
 * injecter une autre pour éprouver le générateur.
 */
interface SourceDeHasard
{
    public function entier(int $min, int $max): int;
}
