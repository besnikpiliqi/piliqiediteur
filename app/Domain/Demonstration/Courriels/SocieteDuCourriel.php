<?php

namespace App\Domain\Demonstration\Courriels;

/**
 * La société d'où part l'e-mail d'une notification en cours d'envoi, sur
 * le site de démonstration (lot DEMO-COPIE, C.9) : celle du compte qui la
 * reçoit, posée au départ de son canal « mail » et oubliée à sa fin (voir
 * SiteDeDemonstrationServiceProvider). Un avis part souvent hors de tout
 * contexte de société (une tâche, un travail de la file) : c'est par son
 * destinataire que le transport de la démonstration le range dans sa
 * société, jamais dans une autre.
 */
final class SocieteDuCourriel
{
    private ?int $societeId = null;

    public function poser(int $societeId): void
    {
        $this->societeId = $societeId;
    }

    public function oublier(): void
    {
        $this->societeId = null;
    }

    public function societeId(): ?int
    {
        return $this->societeId;
    }
}
