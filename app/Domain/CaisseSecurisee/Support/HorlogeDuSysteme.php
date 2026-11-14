<?php

namespace App\Domain\CaisseSecurisee\Support;

/**
 * L'horloge du système de la machine, en secondes depuis l'époque Unix :
 * celle que lit le registre fiscal en production pour horodater chaque
 * opération (voir RegistreFiscal::verrouiller()), et non l'heure que les
 * tests figent. caisse:diagnostiquer la compare à celle du serveur de base
 * (voir DiagnostiquerCaisseSecurisee) ; un test la remplace dans le
 * conteneur pour la décaler.
 */
class HorlogeDuSysteme
{
    public function secondes(): float
    {
        return microtime(true);
    }
}
