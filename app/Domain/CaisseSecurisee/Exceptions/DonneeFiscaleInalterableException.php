<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

use LogicException;

/**
 * Une donnée fiscale validée ne se modifie ni ne se supprime (art. 286
 * I-3° bis du CGI) : une correction s'inscrit par une nouvelle écriture,
 * jamais en réécrivant l'ancienne. Levée par le modèle avant toute
 * requête (voir ProtegeLesDonneesValidees) ; les déclencheurs SQL des
 * tables où l'on ne fait qu'ajouter (voir
 * DeclencheursDeProtection::ajoutSeul()) refusent en plus ce qui ne passe
 * pas par le modèle.
 */
class DonneeFiscaleInalterableException extends LogicException
{
    /**
     * @param  string  $regle  ce qui reste permis sur la donnée, ou pourquoi plus rien ne l'est
     * @param  list<string>  $colonnes  les colonnes dont la modification est refusée
     */
    public static function modification(string $regle, array $colonnes): self
    {
        return new self($regle.' Modification refusée : '.implode(', ', $colonnes).'.');
    }

    /**
     * @param  string  $regle  ce qui reste permis sur la donnée, ou pourquoi plus rien ne l'est
     */
    public static function suppression(string $regle): self
    {
        return new self($regle.' Suppression refusée.');
    }

    /**
     * @param  string  $regle  ce qui reste permis sur la donnée, ou pourquoi plus rien ne l'est
     */
    public static function ajout(string $regle): self
    {
        return new self($regle.' Ajout refusé.');
    }
}
