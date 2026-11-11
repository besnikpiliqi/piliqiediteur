<?php

namespace App\Domain\Fichiers;

use Carbon\CarbonImmutable;

/**
 * Un fichier reçu qui a passé ses contrôles (voir ControleDesFichiers),
 * prêt à s'enregistrer : son nom réécrit avec l'extension de son type
 * réel, son type, le contenu à conserver (le fichier reçu, ou la photo
 * réenregistrée sans ses métadonnées), son empreinte SHA-256 et sa taille,
 * et l'instant de son analyse par l'antivirus ; et, pour le journal
 * d'audit seulement, ce que la personne a déposé : le nom tel que reçu,
 * l'empreinte et la taille du fichier reçu.
 */
final readonly class FichierControle
{
    public function __construct(
        public string $nom,
        public string $type,
        public string $contenu,
        public string $nomRecu,
        public string $empreinteRecue,
        public int $tailleRecue,
        public CarbonImmutable $analyseLe,
    ) {}

    public function empreinte(): string
    {
        return hash('sha256', $this->contenu);
    }

    public function taille(): int
    {
        return strlen($this->contenu);
    }

    /**
     * Ce que le journal d'audit retient du fichier reçu : son nom tel que
     * reçu, comme donnée, son empreinte et sa taille ; jamais son contenu.
     *
     * @return array{nom_recu: string, empreinte_recue: string, taille_recue: int}
     */
    public function valeursRecues(): array
    {
        return ['nom_recu' => $this->nomRecu, 'empreinte_recue' => $this->empreinteRecue, 'taille_recue' => $this->tailleRecue];
    }
}
