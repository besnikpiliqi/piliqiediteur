<?php

namespace App\Filament\Concerns;

/**
 * Après « Créer » ou « Sauvegarder », retour à la liste de la ressource,
 * sa page parente : rester sur le formulaire donne l'impression que rien
 * ne s'est passé.
 */
trait RevientALaListe
{
    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
